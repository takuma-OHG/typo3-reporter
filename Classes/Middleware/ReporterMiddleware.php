<?php

declare(strict_types=1);

namespace Takuma\Typo3Reporter\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Information\Typo3Version;

final class ReporterMiddleware implements MiddlewareInterface
{
    public const VERSION = '1.0.0';

    public const PATH = '/takuma-reporter';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Abgelehnte Anfragen laufen normal weiter und bekommen so die 404 der Site, der Endpunkt bleibt unauffindbar.
        if ($request->getUri()->getPath() !== self::PATH
            || $request->getMethod() !== 'GET'
            || ! $this->tokenMatches($request)
            || ! $this->ipAllowed($request)) {
            return $handler->handle($request);
        }

        return new JsonResponse([
            'schema_version' => 1,
            'generated_at' => date(DATE_ATOM),
            'reporter_version' => self::VERSION,
            'system' => 'typo3',
            'system_version' => (new Typo3Version)->getVersion(),
            'php_version' => PHP_VERSION,
            'environment' => (string) Environment::getContext(),
            'packages' => $this->packages(),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    private function tokenMatches(ServerRequestInterface $request): bool
    {
        $expected = $this->setting('TAKUMA_REPORTER_TOKEN', 'token');
        $header = $request->getHeaderLine('Authorization');
        $given = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    private function ipAllowed(ServerRequestInterface $request): bool
    {
        $allowed = $this->csvSetting('TAKUMA_REPORTER_IPS', 'ips');

        // IpUtils statt GeneralUtility::cmpIP: cmpIP wertet '*' und verkürzte Adressen als Wildcard. Leere Liste ergibt false.
        return IpUtils::checkIp($this->clientIp($request), $allowed);
    }

    /** Von rechts lesen und vertraute Proxys überspringen: alles links vom ersten fremden Eintrag kann der Client fälschen. */
    private function clientIp(ServerRequestInterface $request): string
    {
        // Bewusst REMOTE_ADDR statt reverseProxyIP der Installation: deren Proxy-Einstellung soll hier nicht greifen.
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $ip = is_string($ip) ? $ip : '';
        $proxies = $this->csvSetting('TAKUMA_REPORTER_TRUSTED_PROXIES', 'trustedProxies');
        $hops = explode(',', $request->getHeaderLine('X-Forwarded-For'));

        while ($proxies !== [] && $ip !== '' && IpUtils::checkIp($ip, $proxies) && $hops !== []) {
            $ip = trim((string) array_pop($hops));
        }

        return $ip;
    }

    /** Umgebungsvariable vor Extension-Konfiguration, damit der Token nicht in settings.php stehen muss. */
    private function setting(string $env, string $key): string
    {
        $value = getenv($env);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        // Direkt statt ExtensionConfiguration::get(), das bei fehlendem Eintrag settings.php neu schreibt.
        $value = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        foreach (['EXTENSIONS', 'takuma_reporter', $key] as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : null;
        }

        return is_string($value) ? $value : '';
    }

    /** @return list<string> */
    private function csvSetting(string $env, string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->setting($env, $key)))));
    }

    /** @return list<array{name: string, version: string}> */
    private function packages(): array
    {
        $path = Environment::getProjectPath().'/composer.lock';
        $lock = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($lock) || ! is_array($lock['packages'] ?? null)) {
            return [];
        }

        $packages = [];
        foreach ($lock['packages'] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $packages[] = ['name' => $package['name'], 'version' => $package['version']];
            }
        }

        return $packages;
    }
}
