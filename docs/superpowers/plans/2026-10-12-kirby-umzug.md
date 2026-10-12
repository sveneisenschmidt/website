# Umzug von Hugo auf Kirby: Gesamtplan und Phase 1

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Ziel:** sven.eisenschmidt.website läuft auf Kirby 5. Sven schreibt Beiträge im Panel. Alle heutigen URLs antworten weiter.

**Architektur:** Inhalte sind Zustand auf dem Server (Option A, entschieden am 2026-10-12), wie bei concertshotsbyluisa.de. Code liegt in Git, Inhalte in Backups. Kirby wächst in diesem Repo neben Hugo, bis zum Umschalten in Phase 5. Hugo läuft bis dahin unverändert weiter.

**Technik:** Kirby 5.6.1 über Composer (`getkirby/cms`, installiert nach `www/kirby`, Abhängigkeiten in `vendor/` außerhalb des Document Root), PHP 8.5, PHPUnit 12, Bash, Apache auf All-Inkl.

**Regeln für die Ausführung:**
- Die Arbeit läuft auf dem Branch `kirby`. In Phase 5 wird er in `main` gemergt.
- Claude committet und pusht nie. Am Ende jeder Aufgabe steht ein Checkpoint, Sven committet.
- Hugo darf nicht brechen: `make dev`, `make push` und der Deploy von Hugo bleiben, wie sie sind.
- Kirby-Ziele im Makefile tragen bis Phase 5 das Präfix `kirby-`.
- Ports: `kirby-dev` auf 8010, `kirby-restore-test` auf 8093 und 8094. Luisas Seite belegt lokal 8000, 8091 und 8092, beide Projekte laufen so parallel.

---

## Gesamtplan

| Phase | Inhalt | Dauer | Fertig, wenn |
|---|---|---|---|
| 1 | Grundgerüst (dieser Plan) | ½ Tag | Kirby läuft lokal, Tests grün, `make kirby-restore-test` grün |
| 2 | Inhaltsmodell und Import | 1 Tag | 297 Seiten und rund 720 Bilder in Kirby, Import beliebig oft wiederholbar |
| 3 | Templates | 2 bis 3 Tage | Startseite, Archiv, Beitrag, Suche, 404, RSS, Sitemap, `llms.txt`, Karte, EXIF, Pop, ntfy |
| 4 | URLs | ½ bis 1 Tag | `scripts/check-urls.sh` gegen die Live-Sitemap: 0 Abweichungen, Schrägstrich am Ende bleibt |
| 5 | Staging und Umschalten | ½ Tag | Document Root zeigt auf Kirby, Hugo liegt 2 Wochen als Rückweg bereit |

### Was Phase 2 bis 5 wissen müssen

- **Bestand:** 235 `photo`, 37 `trip`, 21 `note`, 4 `essay`, 1 `movie`. 711 Aufrufe von `{{< img >}}`, 21 von `{{< map >}}` (mit `route.json`), je 1 von `email`, `rawhtml`, `ref`. Frontmatter: `title`, `date`, `type`, `cover`, `trip`, `pick`, `draft`, `layout`.
- **Phase 2, Seitentitel:** Hugo hat `title = "Sven Eisenschmidt - Personal Blog"`, Testdaten und Seed haben `Sven Eisenschmidt`. Der Import legt den Titel fest, Testdaten und Seed ziehen nach.
- **Phase 3, Tests:** Sobald `www/assets` existiert, bekommt `TestSite::app()` die Root `'assets' => realpath(self::SITE . '/../assets')`. Ab mehreren Testklassen gibt es eine gemeinsame Basis wie `IntegrationTestCase` bei Luisa. Tests für Routen in Phase 4 laufen über `$kirby->render(...)`, so ist der Schrägstrich am Ende mitgeprüft.
- **Aus concertshotsbyluisa übernehmen:** sRGB-Treiber (`plugins/portfolio/srgb.php`, Treiber `cbl-gd`), EXIF beim Hochladen (`plugins/portfolio/exif.php`), Seiten-Cache (`plugins/portfolio/cache.php`), Routen für `robots.txt`, `sitemap.xml` und `llms.txt` (`config/routes.php`), Inhaltsmigrationen (`plugins/portfolio/migrate.php`, `bin/migrate.php`).
- **Server-Platz:** Der Hugo-Ordner `sven.eisenschmidt.website` belegt 4,4 GB. Kirby ersetzt ihn. Während Staging liegen beide parallel, rund 8,5 GB.
- **Backups auf dem Server (Phase 5):** Gelöst in Luisas Repo, #49 (2026-10-12): `bin/backup.sh --snapshot` legt Snapshot-Ordner mit Hardlinks an, ein unveränderter Zustand ergibt keinen neuen Snapshot, `bin/restore.sh` nimmt Archiv oder Snapshot-Ordner. Phase 1 hat diese Fassung schon übernommen. Phase 5 übernimmt dazu aus #49: Deploy und `pull` mit `--snapshot`, das Aufräumen alter `.website-tmp-*` und die Snapshot-Prüfungen im Restore-Test.
- **Phase 5 braucht von Sven:** Subdomain für Staging in KAS, PHP 8.5 für diese Domain, Kirby-Basic-Lizenz (99 EUR), PHP auf dem Runner im Homelab.
- **Phase 5, Staging-Passwort:** `Require valid-user` sperrt auch `/.well-known/`. Erneuert All-Inkl Let's Encrypt über HTTP-01, schlägt das während Staging fehl. Abhilfe: `<If "%{REQUEST_URI} =~ m#^/\.well-known/#">Require all granted</If>` im Staging-Block.
- **Phase 5, Salt:** `bin/init-config.php` legt eine neue `.salt` an, sobald die Datei fehlt, auch bei vorhandenen Inhalten. Für den Server muss das Skript abbrechen, wenn `content/` existiert und `.salt` fehlt.
- **Phase 5, PHP auf dem Server:** Für Webseiten stellt KAS die Version je Domain ein (8.5). Das `php` in der SSH-Shell ist 8.4.24. Skripte, die der Deploy über SSH startet, laufen darum mit `php85`.
- **Phase 5, Deploy mit Composer:** Das CI führt `composer install --no-dev --no-interaction --optimize-autoloader` aus und lädt `www/` und `vendor/` nebeneinander hoch. In KAS zeigt das Document Root auf `www/`. So ist `vendor/` von außen nicht erreichbar.
  Der Ordner mit `index.php` muss auf dem Server `www` heißen und neben `vendor/` liegen: Der Autoloader kennt den Pfad `www/kirby` fest. Vor dem Install prüft `composer validate --no-check-publish --strict`, dass die Lock-Datei zu `composer.json` passt.
- **Phase 3, Änderungsdatum:** `lastmod` für Sitemap und RSS kommt aus den Dateien der Seite, nicht aus `$site->modified()`. Ordnerzeiten ändern sich auch durch `.DS_Store`.
- **Phase 3, lokaler Server:** Kirbys `router.php` liefert lokal jede Datei unter `www/` aus, auch `content/` und `site/`. Ein eigener `bin/dev-router.php` mit den Sperren aus `www/.htaccess` macht lokale 404 gleich wie auf Apache.
- **Phase 5, Konten:** Ein lokales Backup mit `site/accounts` kommt nie auf den Server. Der Hook `user.login:before` in `config.php` sperrt Adressen unter `.test` ohnehin, sobald Debug aus ist.
- **Phase 3, Restore-Test:** `bin/page-paths.php` listet nur Seiten. Routen wie `/sitemap.xml`, `/robots.txt`, `/llms.txt` und der Feed kommen in Phase 3 dazu.
- **Phase 5, PHP in `backup.sh`:** Das Skript ruft `php` aus dem PATH, auf dem Server ist das 8.4. Wie in `restore-test.sh`: `PHP="${PHP:-php}"`.
- **Phase 5, Debug:** Auf dem Server bleibt `debug` in `env.php` aus. Mit Debug an funktioniert der Dev-Zugang wieder.
- **Beim Merge in `main`:** den Block `kirby-state` aus `.git/info/exclude` entfernen.
- **Nach dem Umschalten:** Die Skills `write-post` und `journey-post` werden gelöscht. Bis dahin bleiben sie, weil Sven mit Hugo weiter schreibt.

---

## Phase 1: Grundgerüst

### Dateien

| Datei | Aufgabe |
|---|---|
| `composer.json`, `composer.lock` | Kirby 5.6.1 und PHPUnit über Composer |
| `www/kirby/`, `vendor/` | von Composer installiert, nicht in Git |
| `www/index.php`, `www/.htaccess` | Einstieg und Apache-Regeln |
| `.php-version` | PHP-Version von Server und lokal |
| `.gitignore` | Zustand und Erzeugtes von Kirby aus Git heraushalten |
| `www/site/config/config.php` | gemeinsame Konfiguration |
| `www/site/config/env.example.php` | Vorlage für die Host-Konfiguration |
| `www/site/templates/default.php` | einfachstes Template |
| `bin/init-config.php` | legt `env.php` und `.salt` an |
| `bin/seed.php` | Beispielinhalte und lokaler Panel-Zugang |
| `bin/backup.sh`, `bin/restore.sh` | Backup und Restore des Zustands |
| `bin/page-paths.php` | listet die Pfade aller Seiten |
| `bin/restore-test.sh` | beweist: Backup und Restore ergeben dieselbe Seite |
| `phpunit.xml`, `tests/` | PHPUnit |
| `Makefile` | Ziele `kirby-*` |
| `.claude/launch.json` | Vorschau `kirby-dev` |

---

### Aufgabe 1: Kirby und Einstieg

**Dateien:**
- Neu: `composer.json`, `composer.lock`
- Neu: `www/index.php`
- Neu: `www/.htaccess`
- Neu: `.php-version`

- [ ] **Schritt 1: `composer.json` anlegen**

```json
{
    "name": "sveneisenschmidt/website",
    "description": "sven.eisenschmidt.website on Kirby. Composer installs Kirby into www/kirby and its dependencies into vendor/, outside the document root.",
    "type": "project",
    "license": "proprietary",
    "require": {
        "getkirby/cms": "^5.6.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^12.0"
    },
    "config": {
        "platform": {
            "php": "8.5.0"
        },
        "allow-plugins": {
            "getkirby/composer-installer": true
        },
        "sort-packages": true
    },
    "extra": {
        "kirby-cms-path": "www/kirby"
    },
    "autoload-dev": {
        "psr-4": {
            "Website\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Schritt 2: Installieren und Version prüfen**

Run: `composer install --no-interaction && grep '"version"' www/kirby/composer.json`
Expected: `Installing getkirby/cms (5.6.1)` in der Ausgabe, am Ende `"version": "5.6.1",`. Es gibt kein `www/kirby/vendor/`, die Abhängigkeiten liegen in `vendor/`.

- [ ] **Schritt 3: `www/index.php` anlegen**

```php
<?php

// Composer keeps the dependencies outside the document root, so Kirby's
// bootstrap does not find them by itself.
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/kirby/bootstrap.php';

// The index root follows this file. Without it Kirby derives it from the
// autoloader's location, and a copy of www/ would serve the original site.
echo (new Kirby(['roots' => ['index' => __DIR__]]))->render();
```

- [ ] **Schritt 4: `www/.htaccess` anlegen**

```apache
# Kirby .htaccess

Options -Indexes

# BEGIN staging auth
# Password protection while the site is not public (Kirby is free on a
# password-protected staging site). Apache needs the absolute path to
# .htpasswd: the deploy replaces __HTPASSWD__ and removes the "#" below.
# Remove this block at launch.
#AuthType Basic
#AuthName "sven.eisenschmidt.website staging"
#AuthUserFile __HTPASSWD__
#Require valid-user
# END staging auth

<IfModule mod_rewrite.c>

RewriteEngine on

# Block files and folders beginning with a dot, such as .git,
# except for the .well-known folder
RewriteRule (^|/)\.(?!well-known\/) index.php [L]

# Block direct access to content, site and kirby folders
RewriteRule ^content/(.*) index.php [L]
RewriteRule ^site/(.*) index.php [L]
RewriteRule ^kirby/(.*) index.php [L]

# Make site links work
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*) index.php [L]

</IfModule>

# Pass the Authorization header to PHP
SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
```

- [ ] **Schritt 5: `.php-version` anlegen**

```
8.5
```

- [ ] **Checkpoint:** Sven committet `composer.json composer.lock www/index.php www/.htaccess .php-version`.

---

### Aufgabe 2: `.gitignore`

**Dateien:**
- Ändern: `.gitignore` (am Ende anhängen)

- [ ] **Schritt 1: Block anhängen**

```gitignore

# Kirby: Zustand lebt in Backups, nie in Git
/www/content/
/www/site/accounts/
/www/site/config/env.php
/www/site/config/.salt
/www/site/config/.license
/www/.htpasswd

# Kirby: erzeugt, entsteht aus den Inhalten neu
/www/media/
/www/site/cache/
/www/site/sessions/
/www/site/logs/

# Kirby: von Composer installiert
/www/kirby/
/vendor/

# Kirby: lokale Ausgabe
/backups/
/.restore-test/
/.phpunit.cache/
```

- [ ] **Schritt 2: Prüfen**

Run: `git check-ignore -v www/content/x www/media/x backups/x vendor/x`
Expected: 4 Zeilen, jede nennt `.gitignore` als Quelle.

- [ ] **Checkpoint:** Sven committet `.gitignore`.

---

### Aufgabe 3: Konfiguration

**Dateien:**
- Neu: `www/site/config/config.php`
- Neu: `www/site/config/env.example.php`
- Neu: `bin/init-config.php`
- Ändern: `Makefile` (Block am Ende, `.PHONY` in Zeile 1)

- [ ] **Schritt 1: `www/site/config/config.php` anlegen**

```php
<?php

/**
 * Shared configuration. Lives in git.
 * Host-specific values (debug and the like) go into env.php: not in git,
 * not in backups, imported per host. See env.example.php.
 */

$env = is_file(__DIR__ . '/env.php') ? require __DIR__ . '/env.php' : [];

// The media salt is state, not host config: it lives in .salt, which is part
// of every backup. If it changed, every image URL would change.
$salt = is_file(__DIR__ . '/.salt') ? trim(file_get_contents(__DIR__ . '/.salt')) : null;

// A host config without .salt means the salt got lost: restore it from a
// backup. Kirby would otherwise derive a salt from the content path, and
// every media URL would change without an error.
if (is_file(__DIR__ . '/env.php') && ($salt === null || $salt === '')) {
    throw new RuntimeException('site/config/.salt is missing: restore it from a backup.');
}

return array_replace_recursive([
    'debug' => false,
    // Search engines may index the site. Set per host in env.php.
    'public' => false,
    'locale' => 'en_US.utf-8',
    'content' => array_filter(['salt' => $salt]),
    'panel' => [
        'install' => false,
        'language' => 'de',
    ],
    'hooks' => [
        // The local dev login from bin/seed.php (an address under .test) works
        // only with debug on. Should a local backup with accounts ever reach
        // the server (debug off there), nobody can log in with it.
        'user.login:before' => function (Kirby\Cms\User $user) {
            if (str_ends_with($user->email(), '.test') && option('debug') !== true) {
                throw new Kirby\Exception\PermissionException('The dev login works only locally.');
            }
        },
    ],
], $env);
```

- [ ] **Schritt 2: `www/site/config/env.example.php` anlegen**

```php
<?php

/**
 * Host-specific configuration. Copy to env.php: make kirby-config does
 * that for local development, the deploy does it for the server.
 * env.php is not in git and not in backups. Never put state here.
 */

return [
    'debug' => false,
    // true: search engines may index the site. The deploy sets it.
    'public' => false,
    // Canonical site URL on the server. The deploy sets it; leave it out locally.
    // 'url' => 'https://sven.eisenschmidt.website',
];
```

- [ ] **Schritt 3: `bin/init-config.php` kopieren**

```bash
mkdir -p bin && cp ../concertshotsbyluisa/bin/init-config.php bin/init-config.php
```

Die Datei bleibt unverändert. Sie legt `env.php` mit `'debug' => true` an und, nur bei einer neuen Seite, `.salt`.

- [ ] **Schritt 4: Makefile ergänzen**

Jedes Kirby-Ziel bekommt seine eigene `.PHONY`-Zeile im Kirby-Block. Zeile 1 bleibt bei Hugo. Am Ende anhängen:

```make

# Kirby, Umzug von Hugo (Plan: docs/superpowers/plans/2026-10-12-kirby-umzug.md).
# Die Ziele tragen das Präfix kirby-, bis Hugo beim Umschalten wegfällt.
WWW := www
# PHP für die kirby-Ziele. Die Seite läuft auf dem Server mit PHP 8.5
# (.php-version), lokal ist es dieselbe Version. Anderes PHP: make PHP=...
PHP ?= php
# Port von make kirby-dev. Luisas Seite nutzt lokal 8000.
KIRBY_PORT := 8010

.PHONY: kirby-config
kirby-config:
	@$(PHP) bin/init-config.php $(WWW)
```

Nach der Review ergänzt (Stand im Repo):
- `check-main` vor `push`: `make push` bricht ab, wenn der Branch nicht `main` ist.
- `.git/info/exclude` hat dieselben Muster wie der Kirby-Block der `.gitignore`. So committet `make push` auf `main` keine Kirby-Dateien, solange die `.gitignore` nur auf `kirby` liegt.
- `config.php` bricht ab, wenn `env.php` da ist und `.salt` fehlt.
- `.gitignore` ignoriert auch `/www/site/logs/`.

- [ ] **Schritt 5: Ausführen**

Run: `make kirby-config`
Expected:
```
Created www/site/config/env.php
Created www/site/config/.salt
```

- [ ] **Schritt 6: Hugo prüfen**

Run: `hugo --renderToMemory --quiet && echo hugo ok`
Expected: `hugo ok`

- [ ] **Checkpoint:** Sven committet `www/site/config/config.php www/site/config/env.example.php bin/init-config.php Makefile`.

---

### Aufgabe 4: Tests, erst rot

**Dateien:**
- Neu: `phpunit.xml`
- Neu: `tests/bootstrap.php`
- Neu: `tests/Support/TestSite.php`
- Neu: `tests/Integration/RenderTest.php`
- Ändern: `Makefile` (Ziel `kirby-test`)

- [ ] **Schritt 1: `phpunit.xml` anlegen**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnWarning="true"
         failOnRisky="true"
         failOnDeprecation="true"
         failOnNotice="true"
         failOnPhpunitDeprecation="true"
         beStrictAboutOutputDuringTests="true">
    <testsuites>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <!-- Deprecations and notices fail the run only for our own code, not for Kirby or vendor. -->
    <source restrictNotices="true" ignoreIndirectDeprecations="true">
        <include>
            <directory>www/site</directory>
            <directory>bin</directory>
            <directory>tests</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Schritt 2: `tests/bootstrap.php` anlegen**

```php
<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../www/kirby/bootstrap.php';
// Whoops would replace PHPUnit's error handlers (Kirby's own tests do the same).
Kirby\Cms\App::$enableWhoops = false;
```

- [ ] **Schritt 3: `tests/Support/TestSite.php` anlegen**

```php
<?php

namespace Website\Tests\Support;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;

/**
 * A Kirby instance on a temporary folder. Code (blueprints, plugins,
 * templates, snippets, config.php) comes from www/site. State (content,
 * accounts, cache, media) lives in the temporary folder, so a test never
 * touches the local site. Host config (env.php, .salt) is left out.
 */
final class TestSite
{
    public const SITE = __DIR__ . '/../../www/site';

    public static function create(): string
    {
        $root = sys_get_temp_dir() . '/website-test-' . bin2hex(random_bytes(6));
        Dir::make($root . '/config');
        // Every shared config file (config.php, later routes.php). env.php is
        // host config and stays out; .salt and .license are not *.php.
        foreach (glob(self::SITE . '/config/*.php') as $file) {
            if (basename($file) !== 'env.php') {
                copy($file, $root . '/config/' . basename($file));
            }
        }
        Dir::make($root . '/content');
        file_put_contents($root . '/content/site.txt', "Title: Sven Eisenschmidt\n");
        return $root;
    }

    public static function app(string $root, array $options = []): App
    {
        return new App([
            'roots' => [
                'index' => $root,
                'site' => realpath(self::SITE),
                'config' => $root . '/config',
                'content' => $root . '/content',
                'accounts' => $root . '/accounts',
                'cache' => $root . '/cache',
                'media' => $root . '/media',
                'sessions' => $root . '/sessions',
                'logs' => $root . '/logs',
            ],
            'urls' => ['index' => 'http://localhost'],
            'options' => $options,
        ]);
    }

    public static function remove(string $root): void
    {
        Dir::remove($root);
    }
}
```

- [ ] **Schritt 4: `tests/Integration/RenderTest.php` anlegen**

```php
<?php

namespace Website\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Website\Tests\Support\TestSite;

final class RenderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TestSite::create();
    }

    protected function tearDown(): void
    {
        TestSite::remove($this->root);
    }

    public function testDefaultTemplateRendersPageAndSiteTitle(): void
    {
        $kirby = TestSite::app($this->root);
        // TestSite loads the shared config from www/site.
        $this->assertSame('de', $kirby->option('panel.language'));
        $page = $kirby->impersonate('kirby', fn () => $kirby->site()->createChild([
            'slug' => 'about',
            'template' => 'default',
            'content' => ['title' => 'About'],
        ])->changeStatus('unlisted'));

        $html = $page->render();

        $this->assertStringContainsString('<title>About · Sven Eisenschmidt</title>', $html);
        $this->assertStringContainsString('<h1>About</h1>', $html);
    }

    public function testTitlesAreEscapedOnce(): void
    {
        $kirby = TestSite::app($this->root);
        $page = $kirby->impersonate('kirby', fn () => $kirby->site()->createChild([
            'slug' => 'fox-and-wagtail',
            'template' => 'default',
            'content' => ['title' => 'Fox & Wagtail'],
        ])->changeStatus('unlisted'));

        $html = $page->render();

        $this->assertStringContainsString('<title>Fox &amp; Wagtail · Sven Eisenschmidt</title>', $html);
        $this->assertStringContainsString('<h1>Fox &amp; Wagtail</h1>', $html);
    }
}
```

- [ ] **Schritt 5: Ziel `kirby-test` anhängen**

```make

.PHONY: kirby-test
kirby-test:
	@test -x vendor/bin/phpunit || { echo "PHPUnit fehlt. Erst: composer install"; exit 1; }
	@$(PHP) vendor/bin/phpunit
```

- [ ] **Schritt 6: Test laufen lassen**

Run: `make kirby-test`
Expected: beide Tests scheitern mit `The default template does not exist`.

---

### Aufgabe 5: Template, dann grün

**Dateien:**
- Neu: `www/site/templates/default.php`

- [ ] **Schritt 1: `www/site/templates/default.php` anlegen**

```php
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $page->title()->esc() ?> · <?= $site->title()->esc() ?></title>
</head>
<body>
    <h1><?= $page->title()->esc() ?></h1>
</body>
</html>
```

- [ ] **Schritt 2: Test laufen lassen**

Run: `make kirby-test`
Expected: `OK (2 tests, 5 assertions)`

- [ ] **Checkpoint:** Sven committet `phpunit.xml tests www/site/templates/default.php Makefile`.

---

### Aufgabe 6: Beispielinhalte und lokaler Server

**Dateien:**
- Neu: `bin/seed.php`
- Neu: `tests/Integration/DevLoginTest.php` (nach der Review: der Dev-Zugang funktioniert nur mit Debug, Hook in `config.php`)
- Ändern: `Makefile` (Ziele `kirby-seed`, `kirby-dev`)
- Ändern: `.claude/launch.json`

`bin/seed.php` ist eine Übergangslösung. In Phase 2 ersetzt der Import aus Hugo die Beispielinhalte.

- [ ] **Schritt 1: `bin/seed.php` anlegen**

```php
<?php

/**
 * Example content for local development until the Hugo import exists
 * (phase 2): site, home, about and error page, plus a local Panel login.
 * Output is deterministic: same files and file times on every run.
 *
 * Usage: php bin/seed.php [--force]
 */

$root = dirname(__DIR__);
$content = $root . '/www/content';
$force = in_array('--force', $argv, true);

if (is_dir($content) && !$force) {
    fwrite(STDERR, "www/content exists. Use --force (or make kirby-seed FORCE=1) to replace it.\n");
    exit(1);
}

// rrmdir would empty the target of a symlink.
if (is_link($content)) {
    fwrite(STDERR, "www/content is a symlink. Refusing to replace it.\n");
    exit(1);
}

// Local development login. Only ever created locally by this script;
// accounts are state and never go into git.
const DEV_EMAIL = 'dev@sven.eisenschmidt.test';
const DEV_PASSWORD = 'dev-panel-login';

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        ($f->isDir() && !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** Kirby content file: "Key: value" blocks separated by "----". */
function write_txt(string $path, array $fields): void
{
    $blocks = [];
    foreach ($fields as $k => $v) {
        $v = (string)$v;
        $blocks[] = $k . ':' . (str_contains($v, "\n") ? "\n\n" . $v : ' ' . $v);
    }
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, implode("\n\n----\n\n", $blocks) . "\n");
}

/** Stable 16-char id from a string, used as Kirby UUID. */
function uid(string $s): string
{
    return substr(hash('sha256', 'website:' . $s), 0, 16);
}

rrmdir($content);
mkdir($content, 0755, true);

write_txt("$content/site.txt", ['Title' => 'Sven Eisenschmidt', 'Uuid' => uid('site')]);
write_txt("$content/home/home.txt", ['Title' => 'Home', 'Uuid' => uid('home')]);
write_txt("$content/about/default.txt", ['Title' => 'About', 'Uuid' => uid('about')]);
write_txt("$content/error/error.txt", ['Title' => 'Page not found', 'Uuid' => uid('error')]);

// Fixed file times for files and folders: Kirby's modified() reads both.
$mtime = strtotime('2026-10-12 12:00:00 UTC');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($content, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
    touch($f->getPathname(), $mtime);
}
touch($content, $mtime);

// Dev account, only if no account exists yet (keeps a real local account untouched).
$accounts = $root . '/www/site/accounts';
// Only account folders count: Kirby also writes a .logins file there.
$hasAccounts = count(glob($accounts . '/*', GLOB_ONLYDIR) ?: []) > 0;
if (!$hasAccounts) {
    require $root . '/vendor/autoload.php';
    require $root . '/www/kirby/bootstrap.php';
    $kirby = new Kirby(['roots' => ['index' => $root . '/www']]);
    $kirby->impersonate('kirby');
    $kirby->users()->create([
        'email' => DEV_EMAIL,
        'password' => DEV_PASSWORD,
        'role' => 'admin',
        'language' => 'de',
        'name' => 'Dev',
    ]);
    echo "Dev login for the Panel (make kirby-dev, then /panel): see DEV_EMAIL and DEV_PASSWORD in bin/seed.php\n";
}

echo "Seeded 4 pages into www/content.\n";
```

- [ ] **Schritt 2: Ziele anhängen**

```make

.PHONY: kirby-seed
kirby-seed:
	$(PHP) bin/seed.php $(if $(filter 1,$(FORCE)),--force,)

# Nur localhost: der PHP-Server liefert jede Datei unter www/ aus, auch
# content/ und site/. Anders als Hugos make dev nie an 0.0.0.0 binden.
.PHONY: kirby-dev
kirby-dev:
	@test -f vendor/autoload.php || { echo "Kirby fehlt. Erst: composer install"; exit 1; }
	@test -f $(WWW)/site/config/env.php || { echo "Keine Konfiguration. Erst: make kirby-config"; exit 1; }
	@test -d $(WWW)/content || { echo "Keine Inhalte. Erst: make kirby-seed"; exit 1; }
	@$(PHP) -r 'if (PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION !== trim(file_get_contents(".php-version"))) fwrite(STDERR, "Hinweis: hier PHP ".PHP_VERSION.", .php-version sagt ".trim(file_get_contents(".php-version"))."\n");'
	@echo "Seite: http://localhost:$(KIRBY_PORT)  Panel: http://localhost:$(KIRBY_PORT)/panel  (Ende mit Ctrl+C)"
	@set -o pipefail; PHP_CLI_SERVER_WORKERS=4 $(PHP) -S localhost:$(KIRBY_PORT) -t $(WWW) $(WWW)/kirby/router.php 2>&1 | grep --line-buffered -v -E '(Accepted|Closing)$$'
```

- [ ] **Schritt 3: Vorschau eintragen**

In `.claude/launch.json` unter `configurations` ergänzen:

```json
{
  "name": "kirby-dev",
  "runtimeExecutable": "make",
  "runtimeArgs": ["kirby-dev"],
  "port": 8010
}
```

- [ ] **Schritt 4: Beispielinhalte anlegen**

Run: `make kirby-seed`
Expected: die Zeile zum Dev-Zugang, dann `Seeded 4 pages into www/content.`

- [ ] **Schritt 5: Im Browser prüfen**

Vorschau `kirby-dev` starten.
- `http://localhost:8010/` zeigt die Überschrift `Home`.
- `http://localhost:8010/about` zeigt `About`.
- `http://localhost:8010/panel` zeigt die Anmeldung. Anmeldung mit `DEV_EMAIL` und `DEV_PASSWORD` aus `bin/seed.php` öffnet das Panel.

- [ ] **Checkpoint:** Sven committet `bin/seed.php tests/Integration/DevLoginTest.php www/site/config/config.php Makefile .claude/launch.json`.

---

### Aufgabe 7: Backup, Restore und Beweis

**Dateien:**
- Neu: `bin/backup.sh`
- Neu: `bin/restore.sh`
- Neu: `bin/page-paths.php`
- Neu: `bin/restore-test.sh`
- Ändern: `Makefile` (Ziele `kirby-backup`, `kirby-restore`, `kirby-restore-test`)

- [ ] **Schritt 1: `bin/backup.sh` kopieren und das Präfix ändern**

```bash
cp ../concertshotsbyluisa/bin/backup.sh bin/backup.sh
sed -i '' 's|/cbl-|/website-|g; s|\.cbl-tmp-|.website-tmp-|g' bin/backup.sh
chmod +x bin/backup.sh
```

Run: `grep -n 'website-\|cbl' bin/backup.sh`
Expected: Treffer nur mit `website-` (Archive, Snapshot-Ordner, Temp-Ordner `.website-tmp-`), keiner mit `cbl`.

- [ ] **Schritt 2: `bin/restore.sh` kopieren und die Migration entfernen**

```bash
cp ../concertshotsbyluisa/bin/restore.sh bin/restore.sh
chmod +x bin/restore.sh
```

In Zeile 7 `make config` durch `make kirby-config` ersetzen. Diese zwei Zeilen am Ende von `bin/restore.sh` löschen. Inhaltsmigrationen kommen erst mit dem Inhaltsmodell in Phase 2:

```bash
# A backup from older code gets the content migrations of this code.
php "$(dirname "$0")/migrate.php" "$WWW" | sed 's/^/  /'
```

- [ ] **Schritt 3: `bin/page-paths.php` anlegen**

```php
<?php

/**
 * Prints the URL path of every page, one per line: first "/", then every
 * listed and unlisted page except home and error. bin/restore-test.sh
 * fetches each of them from both sites.
 *
 * Usage: php bin/page-paths.php [www-root]
 */

$www = rtrim($argv[1] ?? 'www', '/');
require dirname(__DIR__) . '/vendor/autoload.php';
require $www . '/kirby/bootstrap.php';
$kirby = new Kirby(['roots' => ['index' => realpath($www)]]);

echo "/\n";
foreach ($kirby->site()->index() as $page) {
    if ($page->isHomePage() || $page->isErrorPage()) {
        continue;
    }
    echo parse_url($page->url(), PHP_URL_PATH), "\n";
}
```

- [ ] **Schritt 4: Pfade prüfen**

Run: `php bin/page-paths.php www`
Expected:
```
/
/about
```

- [ ] **Schritt 5: `bin/restore-test.sh` anlegen**

```bash
#!/usr/bin/env bash
# Proves that backup + restore gives exactly the previous site:
# 1. Back up www.
# 2. Copy only the code of www (no state, nothing generated) to .restore-test/www.
# 3. Import the host config (env.php) and restore the backup there.
# 4. Compare the state checksums and file times of both trees.
# 5. Serve both, fetch every page from bin/page-paths.php, compare the HTML.
#
# Usage: bin/restore-test.sh [www-root]
set -euo pipefail

WWW="${1:-www}"
TEST=".restore-test"
PORT_A=8093
PORT_B=8094
# kirby-restore-test passes the PHP of the Makefile.
PHP="${PHP:-php}"

sha256() { if command -v sha256sum >/dev/null; then sha256sum "$@"; else shasum -a 256 "$@"; fi; }
state_sums() { ( cd "$1" && find content site/accounts site/config/.salt site/config/.license -type f ! -name '.DS_Store' 2>/dev/null | LC_ALL=C sort | while read -r f; do sha256 "$f"; done ); }
# One PHP process for all files: phase 2 brings about a thousand.
mtimes() { ( cd "$1" && find content -type f ! -name '.DS_Store' | LC_ALL=C sort | "$PHP" -r 'while (($f = fgets(STDIN)) !== false) { $f = rtrim($f, "\n"); echo filemtime($f), " ", $f, "\n"; }' ); }
# Prints the first differences, so a failure says where.
same() { local d; d=$(diff "$1" "$2") || { echo "FAIL: $3 differ"; echo "$d" | head -20; exit 1; }; }

rm -rf "$TEST"
mkdir -p "$TEST"

ARCHIVE="$(bin/backup.sh "$WWW" "$TEST/backups" | tail -1)"
echo "Backup: $ARCHIVE"

rsync -a --exclude '/content' --exclude '/media' --exclude '/site/accounts' --exclude '/site/cache' \
  --exclude '/site/sessions' --exclude '/site/config/env.php' --exclude '/site/config/.salt' \
  --exclude '/site/config/.license' "$WWW/" "$TEST/www/"
cp -p "$WWW/site/config/env.php" "$TEST/www/site/config/env.php"   # config import
ln -s ../vendor "$TEST/vendor"   # www/index.php loads ../vendor/autoload.php

bin/restore.sh "$ARCHIVE" "$TEST/www" >/dev/null

same <(state_sums "$WWW") <(state_sums "$TEST/www") "state checksums"
same <(mtimes "$WWW") <(mtimes "$TEST/www") "file times"
echo "OK: state files identical (checksums and file times)"

# Another server on these ports would answer in place of ours.
for port in "$PORT_A" "$PORT_B"; do
  ! curl -s -o /dev/null "http://localhost:$port/" || { echo "FAIL: port $port is busy"; exit 1; }
done
PHP_CLI_SERVER_WORKERS=4 "$PHP" -S "localhost:$PORT_A" -t "$WWW" "$WWW/kirby/router.php" >/dev/null 2>&1 & A=$!
PHP_CLI_SERVER_WORKERS=4 "$PHP" -S "localhost:$PORT_B" -t "$TEST/www" "$TEST/www/kirby/router.php" >/dev/null 2>&1 & B=$!
trap 'pkill -P $A 2>/dev/null; pkill -P $B 2>/dev/null; kill $A $B 2>/dev/null || true' EXIT
until curl -s -o /dev/null "http://localhost:$PORT_A/" && curl -s -o /dev/null "http://localhost:$PORT_B/"; do
  kill -0 "$A" "$B" 2>/dev/null || { echo "FAIL: a test server did not start"; exit 1; }
  sleep 0.2
done

paths=()
while read -r p; do paths+=("$p"); done < <("$PHP" bin/page-paths.php "$WWW")

fail=0
for p in "${paths[@]}"; do
  a=$(curl -fsS --max-time 60 "http://localhost:$PORT_A$p" | sed "s/localhost:$PORT_A/HOST/g")
  b=$(curl -fsS --max-time 60 "http://localhost:$PORT_B$p" | sed "s/localhost:$PORT_B/HOST/g")
  if [ "$a" != "$b" ]; then echo "FAIL: $p differs"; fail=1; fi
done
[ "$fail" = 0 ] || exit 1
echo "OK: ${#paths[@]} pages identical"
# The copy holds the whole state (4 GB from phase 2 on). A failed run keeps it for debugging.
rm -rf "$TEST"
```

Run: `chmod +x bin/restore-test.sh`

- [ ] **Schritt 6: Ziele anhängen**

```make

.PHONY: kirby-backup
kirby-backup:
	bin/backup.sh $(WWW) backups

.PHONY: kirby-restore
kirby-restore:
	@test -n "$(BACKUP)" || { echo "Aufruf: make kirby-restore BACKUP=backups/website-....tar.gz"; exit 1; }
	bin/restore.sh $(BACKUP) $(WWW)

.PHONY: kirby-restore-test
kirby-restore-test:
	PHP=$(PHP) bin/restore-test.sh $(WWW)
```

- [ ] **Schritt 7: Beweis laufen lassen**

Run: `make kirby-restore-test`
Expected, letzte zwei Zeilen:
```
OK: state files identical (checksums and file times)
OK: 2 pages identical
```

- [ ] **Checkpoint:** Sven committet `bin/backup.sh bin/restore.sh bin/page-paths.php bin/restore-test.sh Makefile`.

---

### Aufgabe 8: Lint und Abschluss

**Dateien:**
- Ändern: `Makefile` (Ziel `kirby-lint`)

- [ ] **Schritt 1: Ziel anhängen**

```make

.PHONY: kirby-lint
kirby-lint:
	@find $(WWW)/site $(WWW)/index.php bin tests -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null && echo "lint ok"
```

- [ ] **Schritt 2: Alles prüfen**

Run: `make kirby-lint && make kirby-test && make kirby-restore-test`
Expected: `lint ok`, `OK (4 tests, 7 assertions)`, `OK: 2 pages identical`

- [ ] **Schritt 3: Hugo prüfen**

Run: `hugo --renderToMemory --quiet && echo hugo ok`
Expected: `hugo ok`

- [ ] **Schritt 4: Git prüfen**

Run: `git status --short`
Expected: keine Pfade unter `www/content`, `www/media`, `www/site/accounts`, `backups`, `vendor`, `.restore-test`.

- [ ] **Checkpoint:** Sven committet `Makefile bin/restore-test.sh docs/superpowers/plans/2026-10-12-kirby-umzug.md`. Phase 1 ist fertig.
