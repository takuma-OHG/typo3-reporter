<?php

declare(strict_types=1);

namespace Takuma\Typo3Reporter\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Takuma\Typo3Reporter\Middleware\ReporterMiddleware;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReporterMiddlewareTest extends FunctionalTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected array $testExtensionsToLoad = ['takuma/typo3-reporter'];

    /** Abweichender Token, damit die übrigen Tests zugleich belegen, dass die Umgebungsvariable Vorrang hat. */
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => ['takuma_reporter' => ['token' => 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc', 'ips' => '', 'trustedProxies' => '']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        GeneralUtility::mkdir_deep($this->instancePath.'/typo3conf/sites/main');
        file_put_contents($this->instancePath.'/typo3conf/sites/main/config.yaml', "rootPageId: 1\nbase: 'https://example.test/'\nlanguages:\n  - languageId: 0\n    title: Deutsch\n    locale: de_DE.UTF-8\n    base: /\n");
        putenv('TAKUMA_REPORTER_TOKEN='.self::TOKEN);
        putenv('TAKUMA_REPORTER_IPS=203.0.113.10,2001:db8::/32');
        putenv('TAKUMA_REPORTER_TRUSTED_PROXIES');
    }

    protected function tearDown(): void
    {
        putenv('TAKUMA_REPORTER_TOKEN');
        putenv('TAKUMA_REPORTER_IPS');
        putenv('TAKUMA_REPORTER_TRUSTED_PROXIES');
        if (is_file($this->instancePath.'/composer.lock')) {
            unlink($this->instancePath.'/composer.lock');
        }
        parent::tearDown();
    }

    #[Test]
    public function valid_token_and_ip_returns_contract_v1_with_lock_packages(): void
    {
        file_put_contents($this->instancePath.'/composer.lock', json_encode([
            'packages' => [['name' => 'typo3/cms-core', 'version' => 'v13.4.2']],
            'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '11.0.0']],
        ]));

        $response = $this->report();
        $data = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertIsArray($data);
        self::assertSame(['schema_version', 'generated_at', 'reporter_version', 'system', 'system_version', 'php_version', 'environment', 'packages'], array_keys($data));
        self::assertSame(1, $data['schema_version']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, (string) $data['generated_at']));
        self::assertSame(ReporterMiddleware::VERSION, $data['reporter_version']);
        self::assertSame('typo3', $data['system']);
        self::assertSame((new Typo3Version)->getVersion(), $data['system_version']);
        self::assertSame(PHP_VERSION, $data['php_version']);
        self::assertSame('Testing', $data['environment']);
        self::assertSame([['name' => 'typo3/cms-core', 'version' => 'v13.4.2']], $data['packages']);
    }

    #[Test]
    public function packages_are_empty_without_composer_lock(): void
    {
        $data = json_decode((string) $this->report()->getBody(), true);

        self::assertIsArray($data);
        self::assertSame([], $data['packages']);
    }

    #[Test]
    public function ipv6_cidr_is_allowed(): void
    {
        self::assertSame(200, $this->report(ip: '2001:db8::1')->getStatusCode());
    }

    #[Test]
    public function forwarded_ip_is_used_only_behind_trusted_proxy(): void
    {
        putenv('TAKUMA_REPORTER_TRUSTED_PROXIES=10.0.0.1');
        $behindProxy = fn (string $remote) => $this->report(ip: $remote, headers: ['X-Forwarded-For' => '198.51.100.7, 203.0.113.10']);

        self::assertSame(200, $behindProxy('10.0.0.1')->getStatusCode());
        self::assertSame(404, $behindProxy('10.0.0.2')->getStatusCode());
    }

    /** @return array<string, array{string, string, array<string, string>}> */
    public static function rejectedRequests(): array
    {
        return [
            'wrong token' => ['GET', '203.0.113.10', ['Authorization' => 'Bearer '.str_repeat('b', 64)]],
            'missing token' => ['GET', '203.0.113.10', []],
            'empty bearer' => ['GET', '203.0.113.10', ['Authorization' => 'Bearer ']],
            'wrong ip' => ['GET', '198.51.100.7', ['Authorization' => 'Bearer '.self::TOKEN]],
            'post' => ['POST', '203.0.113.10', ['Authorization' => 'Bearer '.self::TOKEN]],
            'spoofed forwarded-for without trusted proxy' => ['GET', '198.51.100.7', ['Authorization' => 'Bearer '.self::TOKEN, 'X-Forwarded-For' => '203.0.113.10']],
        ];
    }

    /** @param array<string, string> $headers */
    #[Test]
    #[DataProvider('rejectedRequests')]
    public function rejected_request_gets_the_sites404(string $method, string $ip, array $headers): void
    {
        $unknownPage = $this->request('/takuma-reporter-gibt-es-nicht', $method, $ip, $headers);
        $response = $this->request(ReporterMiddleware::PATH, $method, $ip, $headers);

        self::assertSame(404, $unknownPage->getStatusCode());
        self::assertSame(404, $response->getStatusCode());
        self::assertSame($this->withoutPerRequestValues($unknownPage), $this->withoutPerRequestValues($response));
        self::assertSame($unknownPage->getHeaderLine('Content-Type'), $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function extension_configuration_is_used_without_environment_variable(): void
    {
        putenv('TAKUMA_REPORTER_TOKEN');

        self::assertSame(200, $this->report(headers: ['Authorization' => 'Bearer '.str_repeat('c', 64)])->getStatusCode());
        self::assertSame(404, $this->report()->getStatusCode());
    }

    #[Test]
    public function missing_ip_configuration_always_rejects(): void
    {
        putenv('TAKUMA_REPORTER_IPS');

        self::assertSame(404, $this->report()->getStatusCode());
    }

    /** CSP-Nonce und Request-ID wechseln bei jeder Anfrage, auch zwischen zwei unbekannten Seiten. */
    private function withoutPerRequestValues(ResponseInterface $response): string
    {
        return (string) preg_replace(['/nonce="[^"]*"/', '/Request: [0-9a-f]+/'], ['nonce=""', 'Request: x'], (string) $response->getBody());
    }

    /** @param array<string, string> $headers */
    private function report(string $ip = '203.0.113.10', array $headers = []): ResponseInterface
    {
        return $this->request(ReporterMiddleware::PATH, 'GET', $ip, $headers + ['Authorization' => 'Bearer '.self::TOKEN]);
    }

    /** @param array<string, string> $headers */
    private function request(string $path, string $method, string $ip, array $headers): ResponseInterface
    {
        $request = (new InternalRequest('https://example.test'.$path))
            ->withMethod($method)
            ->withServerParams(['SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => 'example.test', 'SERVER_NAME' => 'example.test', 'HTTPS' => 'on', 'REMOTE_ADDR' => $ip]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->executeFrontendSubRequest($request);
    }
}
