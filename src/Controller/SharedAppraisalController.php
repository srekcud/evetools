<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SharedShoppingListRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SharedAppraisalController
{
    public function __construct(
        private readonly SharedShoppingListRepository $repository,
    ) {
    }

    #[Route('/s/{token}', name: 'shared_appraisal_og', methods: ['GET'])]
    public function __invoke(string $token, Request $request): Response
    {
        $sharedList = $this->repository->findByToken($token);

        if ($sharedList === null) {
            return new Response($this->buildNotFoundHtml(), Response::HTTP_NOT_FOUND, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        $data = $sharedList->getData();
        $totals = $data['totals'] ?? [];
        $items = $data['items'] ?? [];

        $sellTotal = $totals['sellTotalWeighted'] ?? $totals['sellTotal'] ?? 0;
        $buyTotal = $totals['buyTotalWeighted'] ?? $totals['buyTotal'] ?? 0;
        $splitTotal = $totals['splitTotalWeighted'] ?? $totals['splitTotal'] ?? 0;

        $ogTitle = sprintf(
            '%s Sell | %s Buy | %s Split',
            $this->formatIsk((float) $sellTotal),
            $this->formatIsk((float) $buyTotal),
            $this->formatIsk((float) $splitTotal),
        );

        $ogDescription = $this->buildDescription($items);
        $ogUrl = $request->getSchemeAndHttpHost() . '/s/' . $token;
        $appraisalUrl = '/appraisal/shared/' . $token;

        $html = $this->buildHtml($token, $ogTitle, $ogDescription, $ogUrl, $appraisalUrl);

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function formatIsk(float $value): string
    {
        $absValue = abs($value);

        if ($absValue >= 1_000_000_000) {
            return number_format($value / 1_000_000_000, 2) . 'B';
        }

        if ($absValue >= 1_000_000) {
            return number_format($value / 1_000_000, 2) . 'M';
        }

        if ($absValue >= 1_000) {
            return number_format($value / 1_000, 2) . 'K';
        }

        return number_format($value);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function buildDescription(array $items): string
    {
        $maxItems = 5;
        $lines = [];

        foreach (array_slice($items, 0, $maxItems) as $item) {
            $typeName = (string) ($item['typeName'] ?? 'Unknown');
            $quantity = (int) ($item['quantity'] ?? 0);
            $lines[] = $typeName . ' x ' . number_format($quantity);
        }

        $remaining = count($items) - $maxItems;

        if ($remaining > 0) {
            $lines[] = '... and ' . $remaining . ' more items';
        }

        return implode("\n", $lines);
    }

    private function buildHtml(string $token, string $ogTitle, string $ogDescription, string $ogUrl, string $appraisalUrl): string
    {
        $escapedTitle = htmlspecialchars($ogTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escapedDescription = htmlspecialchars($ogDescription, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escapedUrl = htmlspecialchars($ogUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escapedAppraisalUrl = htmlspecialchars($appraisalUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escapedToken = htmlspecialchars($token, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta property="og:site_name" content="EVE Tools">
                <meta property="og:title" content="{$escapedTitle}">
                <meta property="og:description" content="{$escapedDescription}">
                <meta property="og:url" content="{$escapedUrl}">
                <meta property="og:type" content="website">
                <meta name="twitter:card" content="summary">
                <script>window.location.replace('/appraisal/shared/' + '{$escapedToken}')</script>
            </head>
            <body style="background:#0f172a;color:#94a3b8;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">
              <div style="text-align:center">
                <h1 style="color:#22d3ee">EVE Tools - Appraisal</h1>
                <p>{$escapedTitle}</p>
                <p><a href="{$escapedAppraisalUrl}" style="color:#22d3ee">View full appraisal</a></p>
              </div>
            </body>
            </html>
            HTML;
    }

    private function buildNotFoundHtml(): string
    {
        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta property="og:site_name" content="EVE Tools">
                <meta property="og:title" content="Appraisal Not Found">
                <meta property="og:type" content="website">
            </head>
            <body style="background:#0f172a;color:#94a3b8;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">
              <div style="text-align:center">
                <h1 style="color:#22d3ee">EVE Tools</h1>
                <p>This appraisal has expired or does not exist.</p>
              </div>
            </body>
            </html>
            HTML;
    }
}
