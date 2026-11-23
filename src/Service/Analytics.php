<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\Database\Contract\ConnectionInterface;

class Analytics
{
    public const TABLES = [
        'page_view' => 'CREATE TABLE IF NOT EXISTS page_view (id INTEGER PRIMARY KEY AUTOINCREMENT, path VARCHAR(255) NOT NULL, route VARCHAR(100) NULL, status INTEGER NOT NULL, visitor CHAR(64) NOT NULL, referrer VARCHAR(255) NULL, duration_ms INTEGER NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)',
        'download' => 'CREATE TABLE IF NOT EXISTS download (id INTEGER PRIMARY KEY AUTOINCREMENT, kind VARCHAR(10) NOT NULL, item VARCHAR(150) NOT NULL, version VARCHAR(50) NOT NULL, platform VARCHAR(30) NULL, source VARCHAR(10) NOT NULL, visitor CHAR(64) NOT NULL, created_at DATETIME NOT NULL)',
    ];

    public const INDEXES = [
        'CREATE INDEX IF NOT EXISTS idx_page_view_created ON page_view (created_at)',
        'CREATE INDEX IF NOT EXISTS idx_page_view_path ON page_view (path)',
        'CREATE INDEX IF NOT EXISTS idx_page_view_visitor ON page_view (visitor)',
        'CREATE INDEX IF NOT EXISTS idx_download_item ON download (kind, item)',
        'CREATE INDEX IF NOT EXISTS idx_download_created ON download (created_at)',
    ];

    public function __construct(
        #[Autowire(service: 'database.connection.analytics')] protected ConnectionInterface $connection,
        #[Autowire(env: 'APP_SECRET')] protected string $secret,
    ) {
    }

    public function install(bool $drop = false): array
    {
        return $this->connection->transactional(function (ConnectionInterface $connection) use ($drop): array {
            $created = [];

            foreach (self::TABLES as $table => $sql) {
                if ($drop) {
                    $connection->executeStatement('DROP TABLE IF EXISTS ' . $connection->quoteIdentifier($table));
                }

                $connection->executeStatement($sql);
                $created[] = $table;
            }

            foreach (self::INDEXES as $sql) {
                $connection->executeStatement($sql);
            }

            return $created;
        });
    }

    public function isInstalled(): bool
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN (?)", [array_keys(self::TABLES)]) === count(self::TABLES);
    }

    public function recordView(string $path, ?string $route, int $status, ?string $ip, ?string $userAgent, ?string $referrer, int $durationMs): int
    {
        $this->connection->insert('page_view', [
            'path' => mb_substr($path, 0, 255),
            'route' => $route,
            'status' => $status,
            'visitor' => $this->visitor($ip, $userAgent),
            'referrer' => $referrer !== null ? mb_substr($referrer, 0, 255) : null,
            'duration_ms' => $durationMs,
            'created_at' => new DateTimeImmutable(),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Records a download of NeoIDE (kind "ide") or of an extension (kind "plugin" / "theme").
     */
    public function recordDownload(string $kind, string $item, string $version, ?string $platform, string $source, ?string $ip, ?string $userAgent): void
    {
        $this->connection->insert('download', [
            'kind' => $kind,
            'item' => mb_substr($item, 0, 150),
            'version' => mb_substr($version, 0, 50),
            'platform' => $platform,
            'source' => $source,
            'visitor' => $this->visitor($ip, $userAgent),
            'created_at' => new DateTimeImmutable(),
        ]);
    }

    /**
     * The total of unique downloads (one per visitor and per day) of every item of the kinds: [item => count].
     */
    public function downloadTotals(array $kinds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT item, COUNT(DISTINCT visitor) AS downloads FROM download WHERE kind IN (?) GROUP BY item',
            [$kinds],
        );

        return array_column(array_map(static fn (array $row): array => [$row['item'], (int) $row['downloads']], $rows), 1, 0);
    }

    public function topDownloads(int $days, int $limit = 10, ?string $kind = null): array
    {
        $sql = 'SELECT kind, item, COUNT(*) AS downloads, COUNT(DISTINCT visitor) AS visitors FROM download WHERE created_at >= :since';
        $parameters = ['since' => $this->since($days)];

        if ($kind !== null) {
            $sql .= ' AND kind = :kind';
            $parameters['kind'] = $kind;
        }

        return $this->connection->fetchAllAssociative($sql . ' GROUP BY kind, item ORDER BY downloads DESC, item ASC LIMIT ' . max(1, $limit), $parameters);
    }

    public function summary(int $days): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors, ROUND(AVG(duration_ms)) AS avg_ms FROM page_view WHERE created_at >= :since',
            ['since' => $this->since($days)],
        ) ?? [];

        return [
            'views' => (int) ($row['views'] ?? 0),
            'visitors' => (int) ($row['visitors'] ?? 0),
            'avg_ms' => (int) ($row['avg_ms'] ?? 0),
        ];
    }

    public function perDay(int $days): array
    {
        $rows = $this->connection->fetchAllAssociativeIndexed(
            'SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM page_view WHERE created_at >= :since GROUP BY day ORDER BY day',
            ['since' => $this->since($days)],
        );
        $result = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = (new DateTimeImmutable('-' . $i . ' days'))->format('Y-m-d');
            $result[$day] = ['views' => (int) ($rows[$day]['views'] ?? 0), 'visitors' => (int) ($rows[$day]['visitors'] ?? 0)];
        }

        return $result;
    }

    public function topPaths(int $days, int $limit = 10, ?string $route = null): array
    {
        $sql = 'SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors, ROUND(AVG(duration_ms)) AS avg_ms FROM page_view WHERE created_at >= :since AND status < 400';
        $parameters = ['since' => $this->since($days)];

        if ($route !== null) {
            $sql .= ' AND route = :route';
            $parameters['route'] = $route;
        }

        return $this->connection->fetchAllAssociative($sql . ' GROUP BY path ORDER BY views DESC, path ASC LIMIT ' . max(1, $limit), $parameters);
    }

    public function referrers(int $days, int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT referrer, COUNT(*) AS views FROM page_view WHERE created_at >= :since AND referrer IS NOT NULL GROUP BY referrer ORDER BY views DESC LIMIT ' . max(1, $limit),
            ['since' => $this->since($days)],
        );
    }

    public function notFound(int $days, int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT path, COUNT(*) AS views FROM page_view WHERE created_at >= :since AND status = 404 GROUP BY path ORDER BY views DESC LIMIT ' . max(1, $limit),
            ['since' => $this->since($days)],
        );
    }

    protected function since(int $days): DateTimeImmutable
    {
        return new DateTimeImmutable('-' . ($days - 1) . ' days 00:00:00');
    }

    public function purge(int $olderThanDays): int
    {
        $before = new DateTimeImmutable('-' . $olderThanDays . ' days');

        return $this->connection->executeStatement('DELETE FROM page_view WHERE created_at < ?', [$before])
            + $this->connection->executeStatement('DELETE FROM download WHERE created_at < ?', [$before]);
    }

    protected function visitor(?string $ip, ?string $userAgent): string
    {
        return hash_hmac('sha256', (string) $ip . '|' . (string) $userAgent . '|' . date('Y-m-d'), $this->secret);
    }
}