<?php

declare(strict_types=1);

namespace App\Service;

use App\Listener\PageViewListener;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Logger\Contract\LoggerInterface;
use Throwable;

/**
 * Counts the downloads of NeoIDE and of the extensions, without blocking the download when the analytics database fails.
 */
class DownloadTracker
{
    public function __construct(protected Analytics $analytics, protected LoggerInterface $logger)
    {
    }

    public function track(Request $request, string $kind, string $item, string $version, ?string $platform = null): void
    {
        $userAgent = $request->headers->get('User-Agent');

        if ($userAgent === null || preg_match(PageViewListener::BOTS, $userAgent) === 1) {
            return;
        }

        try {
            $this->analytics->recordDownload($kind, $item, $version, $platform, self::source($userAgent), $request->getClientIp(), $userAgent);
        } catch (Throwable $exception) {
            $this->logger->warning('Download not recorded: {message}', ['message' => $exception->getMessage()]);
        }
    }

    /**
     * "ide" when NeoIDE itself downloads (User-Agent "NeoIDE/x.y.z"), "web" otherwise.
     */
    public static function source(?string $userAgent): string
    {
        return str_starts_with((string) $userAgent, 'NeoIDE/') ? 'ide' : 'web';
    }
}
