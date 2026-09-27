<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Analytics;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;
use NeoPHP\Package\Security\Attribute\IsGranted;

class AnalyticsController extends AbstractController
{
    public const PERIODS = [7, 30, 90];

    #[Route('/analytics', name: 'analytics', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request, Analytics $analytics): Response
    {
        $days = (int) $request->query->get('days', 7);
        $days = in_array($days, self::PERIODS, true) ? $days : 7;

        if (!$analytics->isInstalled()) {
            $analytics->install();
        }

        $perDay = $analytics->perDay($days);

        return $this->render('analytics/index.html.twig', [
            'days' => $days,
            'periods' => self::PERIODS,
            'summary' => $analytics->summary($days),
            'perDay' => $perDay,
            'maxViews' => max(1, ...array_column($perDay, 'views')),
            'topPages' => $analytics->topPaths($days),
            'topDocs' => $analytics->topPaths($days, 10, 'docs_feature'),
            'referrers' => $analytics->referrers($days),
            'notFound' => $analytics->notFound($days),
        ]);
    }
}