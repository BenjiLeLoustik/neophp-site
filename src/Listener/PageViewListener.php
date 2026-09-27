<?php

declare(strict_types=1);

namespace App\Listener;

use App\Service\Analytics;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\TerminateEvent;
use NeoPHP\Component\Logger\Contract\LoggerInterface;
use Throwable;

#[AsListener]
class PageViewListener
{
    public const IGNORED = ['#^/(analytics|login|logout|builds|_debug)(/|$)#', '#\.(ico|txt|xml|css|js|map|png|jpg|svg|webp)$#'];

    public const BOTS = '#bot|crawl|spider|slurp|curl|wget|python|headless|lighthouse#i';

    public function __construct(protected Analytics $analytics, protected LoggerInterface $logger)
    {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $userAgent = $request->headers->get('User-Agent');

        if (!$request->isMethod('GET') || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return;
        }

        if ($userAgent === null || preg_match(self::BOTS, $userAgent) === 1) {
            return;
        }

        foreach (self::IGNORED as $pattern) {
            if (preg_match($pattern, $request->getPath()) === 1) {
                return;
            }
        }

        try {
            $started = (float) ($request->server->get('REQUEST_TIME_FLOAT') ?? microtime(true));
            $this->analytics->recordView(
                $request->getPath(),
                $request->attributes->get('_route'),
                $response->getStatusCode(),
                $request->getClientIp(),
                $userAgent,
                $this->referrer($request->headers->get('Referer'), $request->getHost()),
                (int) round((microtime(true) - $started) * 1000),
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Page view not recorded: {message}', ['message' => $exception->getMessage()]);
        }
    }

    protected function referrer(?string $referer, string $host): ?string
    {
        $referrerHost = $referer !== null ? parse_url($referer, PHP_URL_HOST) : null;

        return is_string($referrerHost) && $referrerHost !== $host ? $referrerHost : null;
    }
}