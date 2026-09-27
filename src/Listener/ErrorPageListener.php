<?php

declare(strict_types=1);

namespace App\Listener;

use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Exception\ExceptionManager;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\Event\ExceptionEvent;
use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Component\View\Contract\ViewInterface;
use Throwable;

#[AsListener(priority: -10)]
class ErrorPageListener
{
    public const PREVIEW = '_error_preview';

    public function __construct(
        protected ViewInterface $view,
        protected ExceptionManager $exceptions,
        protected LoggerInterface $logger,
        #[Autowire(param: 'kernel.debug')] protected bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();
        $status = $this->exceptions->getStatusCode($exception);
        $preview = (bool) $request->attributes->get(self::PREVIEW, false);

        if ($event->hasResponse() || $request->wantsJson()) {
            return;
        }

        if ($this->debug && $status >= 500 && !$preview) {
            return;
        }

        try {
            $content = $this->view->render('errors/error.html.twig', [
                'status' => $status,
                'phrase' => ExceptionManager::PHRASES[$status] ?? 'Error',
                'message' => $status < 500 ? $exception->getMessage() : null,
                'path' => $request->getPath(),
            ]);
        } catch (Throwable $error) {
            $this->logger->error('Error page not rendered: {message}', ['message' => $error->getMessage()]);

            return;
        }

        $event->setResponse(new Response($content, $status, $this->exceptions->getHeaders($exception)));
    }
}