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
    ];

    public const INDEXES = [
        'CREATE INDEX IF NOT EXISTS idx_page_view_created ON page_view (created_at)',
        'CREATE INDEX IF NOT EXISTS idx_page_view_path ON page_view (path)',
        'CREATE INDEX IF NOT EXISTS idx_page_view_visitor ON page_view (visitor)',
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
        return $this->connection->executeStatement('DELETE FROM page_view WHERE created_at < ?', [new DateTimeImmutable('-' . $olderThanDays . ' days')]);
    }

    protected function visitor(?string $ip, ?string $userAgent): string
    {
        return hash_hmac('sha256', (string) $ip . '|' . (string) $userAgent . '|' . date('Y-m-d'), $this->secret);
    }
}