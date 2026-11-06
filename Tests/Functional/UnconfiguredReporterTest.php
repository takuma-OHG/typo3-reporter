<?php

declare(strict_types=1);

namespace Takuma\Typo3Reporter\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Takuma\Typo3Reporter\Middleware\ReporterMiddleware;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/** Eigene Klasse, weil die Ext-Konfiguration pro Testinstanz feststeht und hier komplett fehlen muss. */
final class UnconfiguredReporterTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['takuma/typo3-reporter'];

    #[Test]
    public function missing_token_configuration_rejects_requests_without_token(): void
    {
        putenv('TAKUMA_REPORTER_TOKEN');
        putenv('TAKUMA_REPORTER_IPS=203.0.113.10');

        $request = (new InternalRequest('https://example.test'.ReporterMiddleware::PATH))
            ->withServerParams(['SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => 'example.test', 'SERVER_NAME' => 'example.test', 'HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.10']);

        try {
            self::assertSame(404, $this->executeFrontendSubRequest($request)->getStatusCode());
            self::assertSame(404, $this->executeFrontendSubRequest($request->withHeader('Authorization', 'Bearer '))->getStatusCode());
        } finally {
            putenv('TAKUMA_REPORTER_IPS');
        }
    }
}
