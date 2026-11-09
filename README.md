# takuma/typo3-reporter

TYPO3-Extension (Key `takuma_reporter`). Stellt `GET /takuma-reporter` bereit. Der takuma manager ruft darüber TYPO3-Version, PHP-Version, Application Context und die Pakete aus `composer.lock` ab (Contract v1).

Der Endpunkt ist eine PSR-15-Middleware vor der Site-Auflösung. Er antwortet auf jeder Domain der Installation, auch im Wartungsmodus. Ungültiger Token, nicht erlaubte IP oder andere Methode als GET: Die Anfrage läuft normal weiter und bekommt die 404 der Site, genau wie jede andere unbekannte Seite.

Voraussetzung: PHP 8.1+, TYPO3 12.4, 13.4 oder 14, Installation per Composer.

## Installation

Das Repo ist öffentlich. Auf dem Server braucht es weder Key noch Token. Installiert wird direkt auf dem Server per SSH, ein Deploy des Projekts ist nicht nötig.

1. Sicherung anlegen:

```bash
cp composer.json composer.json.bak-reporter
cp composer.lock composer.lock.bak-reporter
```

2. In der `composer.json` unter `repositories` das Repo eintragen. Ist `repositories` eine Liste (`[ … ]`):

```json
{ "type": "vcs", "url": "https://github.com/takuma-OHG/typo3-reporter.git", "no-api": true }
```

Ist `repositories` ein Objekt (`{ "local": { … } }`), braucht der Eintrag einen Namen. Sonst meldet Composer ungültiges JSON:

```json
"typo3-reporter": { "type": "vcs", "url": "https://github.com/takuma-OHG/typo3-reporter.git", "no-api": true }
```

Danach prüfen: `composer validate --no-check-publish`.

3. Nur dieses Paket installieren, alle anderen bleiben auf ihrem Stand:

```bash
composer require takuma/typo3-reporter:^1.0 --update-no-dev
vendor/bin/typo3 cache:flush
```

`no-api` lädt per `git clone` statt über die GitHub-API. Ohne Token erlaubt GitHub nur 60 API-Abfragen pro Stunde und IP. Auf Shared Hosting teilen sich viele Kunden eine IP. Auf dem Server muss dafür `git` verfügbar sein.

Bricht Composer mit „is fixed to … (lock file version) by a partial update“ ab, passen `composer.json` und `composer.lock` auf dem Server schon vorher nicht zusammen. Nicht mit `-W` lösen: Dann hebt Composer auf dem Live-System andere Pakete mit an. Entweder die Anforderung in der `composer.json` auf die installierte Version zurücksetzen oder das betroffene Paket bewusst mit aktualisieren (`composer update <paket> takuma/typo3-reporter -w --no-dev --dry-run` zuerst).

Antwortet ein anderes VCS-Repo des Projekts (z. B. auf Bitbucket) mit HTTP 429, ist dessen anonymes API-Limit erreicht. Kurz warten und erneut starten.

Updates: auf dem Server `composer update takuma/typo3-reporter --no-dev`, dann `vendor/bin/typo3 cache:flush`. Immer eine Versions-Einschränkung wie `^1.0` verwenden, nie `dev-main`. Sonst landet jeder Push auf `main` beim nächsten `composer update` auf dem Kundenserver. Welche Version ein Projekt hat, zeigt der takuma manager im Feld `reporter_version`.

Rückbau: die beiden `.bak-reporter`-Dateien zurückkopieren, dann `composer install --no-dev`, dann `vendor/bin/typo3 cache:flush`.

Die Änderung an `composer.json` und `composer.lock` entsteht auf dem Server. Damit der Stand im Git des Projekts stimmt, dieselbe Änderung lokal nachziehen.

### Ohne git auf dem Server: Path-Paket

Laufzeitdateien ins Projekt kopieren und als Path-Repository einbinden:

```bash
rsync -a --exclude .Build --exclude .git --exclude .github --exclude var --exclude Tests \
  --exclude composer.lock --exclude 'php*.dist' --exclude pint.json \
  ~/ARBEIT/PLUGINS/TYPO3/typo3-reporter/ packages/takuma_reporter/
```

Dann `{ "type": "path", "url": "packages/*" }` als Repository eintragen und `composer require takuma/typo3-reporter:@dev`. Updates heißen hier: Kopie erneuern, dann `composer update takuma/typo3-reporter`.

## Konfiguration

Umgebungsvariablen haben Vorrang. Damit muss der Token nicht in `settings.php` stehen:

```dotenv
TAKUMA_REPORTER_TOKEN=<64 Zeichen aus dem takuma manager>
TAKUMA_REPORTER_IPS=203.0.113.10,2001:db8::/32
# nur hinter Load Balancer/Proxy: dessen IPs, vertraute Hops werden in X-Forwarded-For übersprungen
TAKUMA_REPORTER_TRUSTED_PROXIES=
```

Die Variablen müssen im PHP-Prozess des Webservers ankommen (FPM-Pool `env[...]`, Apache `SetEnv` oder eine geladene `.env`). Geht das beim Hoster nicht, dieselben Werte in die Extension-Konfiguration eintragen (Backend: Einstellungen > Extension-Konfiguration > takuma_reporter, Felder `token`, `ips`, `trustedProxies`) oder in `config/system/additional.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['takuma_reporter'] = [
    'token' => '<64 Zeichen>',
    'ips' => '203.0.113.10',
    'trustedProxies' => '',
];
```

IPs werden exakt oder als CIDR verglichen. Wildcards wie `*` oder `192.168.*` gelten nicht, anders als bei `devIPmask`.

Hinter einem Proxy (nginx, Docker, Load Balancer) steht in `REMOTE_ADDR` die Proxy-IP. Diese nie in die erlaubten IPs eintragen, sonst besteht jeder Aufrufer die IP-Prüfung. Stattdessen die Proxy-IPs in `TAKUMA_REPORTER_TRUSTED_PROXIES`. Bei mehreren Proxys hintereinander alle eintragen. Die Einstellung `reverseProxyIP` der Installation wird bewusst nicht ausgewertet.

Pflicht für jeden vertrauten Proxy: Er hängt die IP seines Gegenübers an `X-Forwarded-For` an (nginx: `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`). Ein Proxy, der den Header vom Client nur durchreicht, macht die IP-Prüfung wertlos.

CDN-Bereiche (Cloudflare, CloudFront usw.) nicht als vertraute Proxys eintragen. Über diese Adressen kann jeder andere Kunde des CDN Anfragen schicken und dabei `X-Forwarded-For` frei setzen. Läuft die Seite hinter einem CDN, im takuma manager als Reporter-URL einen Hostnamen eintragen, der direkt auf den Server zeigt (am CDN vorbei).

Ohne Token oder ohne erlaubte IPs antwortet der Endpunkt immer 404.

## Bekannte Grenzen

- Nur Installationen im Web-Root. Liegt TYPO3 in einem Unterverzeichnis, findet die Middleware den Pfad nicht.
- Gibt es eine Seite mit dem Slug `/takuma-reporter`, bekommen abgelehnte Anfragen diese Seite statt der 404.
- `packages` kommt aus `composer.lock` im Projektverzeichnis. Fehlt die Datei, ist die Liste leer.

## Entwicklung

```bash
composer test   # Pint, PHPStan Level 9, PHPUnit (Functional-Tests mit SQLite)
```
