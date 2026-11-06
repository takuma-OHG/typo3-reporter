<?php

declare(strict_types=1);
use Takuma\Typo3Reporter\Middleware\ReporterMiddleware;

return [
    'frontend' => [
        // Vor der Site-Auflösung: der Endpunkt braucht keine Seite und soll auf jeder Domain der Installation antworten.
        'takuma/typo3-reporter' => [
            'target' => ReporterMiddleware::class,
            'before' => ['typo3/cms-frontend/site'],
        ],
    ],
];
