<?php

declare(strict_types=1);

namespace App\Controller;

use App\Listener\ErrorPageListener;
use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Exception\HttpException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;

class ErrorPreviewController extends AbstractController
{
    public function __construct(#[Autowire(param: 'kernel.debug')] protected bool $debug)
    {
    }

    #[Route('/_error/{code}', name: 'error_preview', methods: ['GET'], requirements: ['code' => '[45][0-9]{2}'])]
    public function preview(Request $request, int $code): Response
    {
        if (!$this->debug) {
            throw $this->createNotFoundException();
        }

        $request->attributes->set(ErrorPageListener::PREVIEW, true);

        throw new HttpException($code, 'Preview of the ' . $code . ' error page.');
    }
}