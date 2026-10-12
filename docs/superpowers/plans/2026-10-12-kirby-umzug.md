# Umzug von Hugo auf Kirby: Gesamtplan und Phase 1

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Ziel:** sven.eisenschmidt.website läuft auf Kirby 5. Sven schreibt Beiträge im Panel. Alle heutigen URLs antworten weiter.

**Architektur:** Inhalte sind Zustand auf dem Server (Option A, entschieden am 2026-10-12), wie bei concertshotsbyluisa.de. Code liegt in Git, Inhalte in Backups. Kirby wächst in diesem Repo neben Hugo, bis zum Umschalten in Phase 5. Hugo läuft bis dahin unverändert weiter.

**Technik:** Kirby 5.6.1 über Composer (`getkirby/cms`, installiert nach `www/kirby`, Abhängigkeiten in `vendor/` außerhalb des Document Root), PHP 8.5, PHPUnit 12, Bash, Apache auf All-Inkl.

**Regeln für die Ausführung:**
- Die Arbeit läuft auf dem Branch `kirby`. Er reift, bis Sven umschaltet; erst in Phase 5 wird er in `main` gemergt. Neue Beiträge von `main` kommen per `git merge main` in `kirby`, bevor ein Import sie braucht.
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
| 3 | Templates | 2 bis 3 Tage | Startseite, Archiv, Beitrag, Suche, 404, RSS mit festen GUIDs, Sitemap, `llms.txt`, Karte, EXIF, Besucher-Block auf About |
| 4 | URLs | ½ bis 1 Tag | `scripts/check-urls.sh` gegen die Live-Sitemap: 0 Abweichungen, Schrägstrich am Ende bleibt |
| 5 | Staging und Umschalten | ½ Tag | Document Root zeigt auf Kirby, Hugo liegt 2 Wochen als Rückweg bereit |

### Rückbau der Hugo-Notlösungen (Entscheidungen 2026-10-12)

Was nur existiert, weil Hugo statisch ist, fällt weg oder zieht in Kirby. Grundlage: Bestandsaufnahme vom 2026-10-12.

| Heute | Entscheidung | Phase |
|---|---|---|
| Pop zählt Besuche per JavaScript (`layouts/_partials/pop.html`, `page-id.html`) | Plugin „Besucher“ ersetzt Pop (erledigt, `www/site/plugins/analytics`). Pop wird nach dem Hugo-Rückweg abgeschaltet. | 5 |
| Pop-Daten Jan bis Okt 2026 (`var/data.db` auf dem Server) | Einmal als Tageszahlen pro Seite in `content/.analytics/` übernehmen, alte URLs über `data/redirects.yaml` zugeordnet. Danach die Rohdaten mit den Fingerprints löschen (Sven auf dem Server). | 4 |
| Statistik-Block auf About holt Zahlen von Pop | Snippet zeigt die Zahlen aus „Besucher“, auf dem Server gerendert | 3 |
| Suche mit Pagefind, im CI gebaut | Kirby-Suche über Titel, Anriss, Text und Alternativtexte, auf dem Server; `search.js` holt Ergebnisse beim Tippen | 3 |
| Build alle 2 Stunden für geplante Beiträge | Fällt weg. Veröffentlichen im Panel macht einen Beitrag sofort sichtbar, Zukunftsdaten gibt es nicht. | 5 |
| ntfy beim Deploy, wenn ein Beitrag neu ist | Fällt weg. Der ntfy-Server ist nur im Tailnet erreichbar, Sven veröffentlicht selbst. | 5 |
| Weiterleitungen als `RedirectMatch` aus `data/redirects.yaml` | Weiterleitungen werden Inhalt auf dem Server, gestartet mit den Einträgen aus `data/redirects.yaml`. Ändert sich Slug oder Datum eines veröffentlichten Beitrags, legt ein Hook die Weiterleitung an. | 4 |
| RSS mit eingefrorenen GUIDs (`rss: keep`, 270 Beiträge, `layouts/home.rss.xml`) | Der Kirby-Feed gibt dieselben GUIDs byte-gleich aus, inklusive `?source=rss`; die Zuordnung kommt aus den Weiterleitungen | 3, 4 |
| IndexNow liest den Schlüssel aus `static/` | Schlüsseldatei nach `www/`, Pfad in `.github/workflows/indexnow.yml` anpassen | 5 |
| Bild-Cache `resources/_gen` in Git, Hugo-Version fest | Fällt weg. Kirby rechnet Vorschaubilder beim ersten Abruf; nach dem Umschalten einmal alle Seiten und Bilder abrufen. Smart Crop wird Mitte plus Fokuspunkt im Panel. | 5 |
| `.htaccess`-Header (Kompression, Cache, ETag) aus `layouts/home.htaccess` | In `www/.htaccess` übernehmen, mit langem Cache für `media/` | 5 |
| `scripts/check-urls.sh` mit Pop-Zahlen | Neu schreiben: alte URLs gegen Kirby und die Weiterleitungen, ohne Pop | 4 |
| `static/llms.txt` von Hand, veraltet | Aus den Inhalten erzeugen, Einleitung als Feld | 3 |
| `to-srgb.sh`, `make push`, `check-hugo`, Pagefind-Attribute, `home.searchmeta.json`, `cover-alt.html` | Fällt weg (sRGB-Treiber, Panel mit Verlauf, Alternativtext als Dateifeld) | 5 |

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
- **Phase 3, EXIF-Zeile wie Hugo:** Brennweite auf ganze Zahl runden plus `mm`. Blende `ƒ/` plus ganze Zahl, sonst eine Nachkommastelle. Belichtung `1/N` wird `1/Ns`, sonst eine Nachkommastelle plus `s`. ISO als ` · ISO n` hinter der Belichtung, ohne Belichtung allein `ISO n`. Gemessen gegen Hugos Ausgabe: 737 von 742 Fotos gleich.
- **Phase 3, Bild-Blueprint:** Bekommt `files/image.yml` je `create:`-Optionen (Größe, Format), rechnet Kirby die Datei vor dem `after`-Hook neu, und EXIF ist weg. Dann EXIF in `file.create:before` aus `$upload->root()` lesen.
- **Phase 5, Objektiv bei 5 Fotos:** In `2026-02-19-dawn` (`IMG_0957`, `IMG_0972`, `IMG_0996`, `IMG_1009`, `IMG_1024`) liest PHP das Objektiv nicht, iOS hat die Dateien verändert. Nach dem letzten Import im Panel eintragen: `EF-S55-250mm f/4-5.6 IS STM`.
- **Phase 3, Markdown wie Hugo:** `'markdown' => ['breaks' => false]` (Kirby macht sonst aus jedem Zeilenumbruch ein `<br>`, betrifft `photos/pipes`) und `'smartypants' => true` für typografische Anführungszeichen und Striche wie Hugos Typographer.
- **Phase 3, Templates:** Titelbild ohne Alternativtext nimmt den Seitentitel (wie `cover-alt.html`, betrifft `wild-horeses` und `hypo`). Kartenpunkte als Zahlen ausgeben (`(float)`), nicht als Text. Stream und Archiv sortieren nach dem Feld `date`: 21 Tage haben zwei Beiträge, `num` allein ordnet sie nach Ordnernamen.
- **Phase 4, Links:** Die URL muss aus einem Page Model kommen (`url()`), nicht nur aus Routen: auch `(link: page://…)` und die Panel-Vorschau nutzen sie. Im Inhalt stehen 43 feste Links der Form `/YYYY-MM-DD-slug/`, die bleiben gültig. Links auf `/log/…`, `/posts` und `/topics` sind schon bei Hugo tot.
- **Phase 4, Slug ändern:** Im Panel lässt sich der Slug eines Beitrags ändern, damit ändert sich die URL. Phase 4 legt fest, ob das gesperrt wird (`changeSlug: false`) oder eine Weiterleitung entsteht.
- **Phase 5, Markdown-Plugin:** `www/site/plugins/markdown-field` kommt aus Composer, ist in `.gitignore` und muss trotzdem hoch. Der Upload darf nicht nach `.gitignore` filtern. Danach prüfen, dass `www/site/plugins/markdown-field/index.php` auf dem Server liegt: ohne das Plugin löscht jedes Speichern im Panel Anriss und Text. `BlueprintTest` fällt lokal ohne Plugin durch.
- **Phase 5, Seed nur lokal:** `bin/seed.php` braucht den Hugo-Ordner `content/`. Auf dem Server gibt es ihn nicht, ein Aufruf dort scheitert, bevor er etwas löscht.
- **Bis zum Umschalten, auf `main`:** Den Kirby-Block der `.gitignore` und `check-main` im Makefile auch auf `main` committen. Dann schützt nicht nur `.git/info/exclude` auf diesem Mac, und `git merge main` nach `kirby` gibt dort keine Konflikte.
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

// Dates in the Panel (default: now) and in feeds are Berlin time.
date_default_timezone_set('Europe/Berlin');

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
	@test -f vendor/autoload.php || { echo "Kirby fehlt. Erst: composer install"; exit 1; }
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

---

## Phase 2: Inhaltsmodell und Import

**Ziel:** Alle 297 Beiträge, rund 740 Dateien und die Seiten About, Archive und Search liegen als Kirby-Inhalte vor. Im Panel lassen sie sich bearbeiten. Der Import läuft beliebig oft und ergibt jedes Mal dieselben Dateien.

**Entscheidungen (Sven, 2026-10-12):** Text bleibt Markdown in einem Textfeld (KirbyText). Der Anriss vor `<!--more-->` wird ein eigenes Feld `intro`.

**Aufbau:**
- `www/content/{photos,trips,notes,essays}/` wie die Hugo-Sektionen. Ein Beitrag liegt in `YYYYMMDD_slug/` (Kirby `num: date`), ein Entwurf in `_drafts/slug/`. Der Slug ist der Hugo-Ordner- oder Dateiname.
- Felder eines Beitrags: `title`, `date` (`Y-m-d H:i:s`, Berliner Zeit), `intro`, `text`, `cover` (Datei), `pick`, bei Reisen `trip`, bei Karten `route` (Datei), `mappoints` (Struktur: `lat`, `lon`, `label`), `mapzoom`, `uuid`.
- Bilder: `{{< img src="x" ... >}}` wird `(image: x)`. `alt`, `caption` und `photographer` stehen in der Datei (`x.txt`, Template `image`), EXIF ebenso (`camera`, `lens`, `focal`, `aperture`, `shutter`, `iso`). Die Werte stehen ohne Einheit; das Format macht Phase 3.
- Karte: `{{< map >}}` wird `(map:)` an derselben Stelle im Text. Route, Punkte und Zoom sind Seitenfelder. Der Tag gibt in Phase 2 nur `<div class="map"></div>` aus.
- `{{< ref "slug" >}}` wird `(link: page://UUID text: …)`. `{{< email >}}` wird ein Markdown-Link `mailto:` wie bei Hugo. `{{< rawhtml >}}` fällt weg, das HTML bleibt.
- UUIDs sind fest: `hugo_uid('page:<slug>')`, `hugo_uid('file:<slug>/<datei>')`. Ein Beitrag und seine Dateien bekommen als Zeit das Datum des Beitrags, About sein `lastmod`, alles andere und alle Ordner eine feste Zeit. So ist das Ergebnis auf jedem Klon gleich, und `lastmod` in der Sitemap ist wie bei Hugo das Datum des Beitrags.
- Unbekanntes stoppt den Import mit Datei und Fundstelle: Shortcode, Argument, Frontmatter-Schlüssel, Dateityp.
- Seitentitel ist `Sven Eisenschmidt - Personal Blog` wie in `hugo.toml`.

**Neue Dateien:**

| Datei | Aufgabe |
|---|---|
| `www/site/plugins/blog/index.php` | Plugin: EXIF beim Hochladen, Tag `(map:)` |
| `www/site/plugins/blog/exif.php` | EXIF lesen und speichern |
| `bin/lib/hugo.php` | Import: reine Funktionen und `hugo_import()` |
| `www/site/blueprints/…` | Panel: Website, Sektionen, Beiträge, About, Archiv, Suche, Dateien |
| `tests/Unit/ExifTest.php`, `tests/Unit/HugoTest.php` | Unit-Tests |
| `tests/Integration/ExifUploadTest.php`, `ImportTest.php`, `BlueprintTest.php` | Integrationstests |
| `tests/Support/HugoFixture.php` | kleiner Hugo-Ordner mit je einem Beispiel |
| `tests/fixtures/exif.jpg` | Foto mit EXIF, aus concertshotsbyluisa |

**Zahlen aus dem Bestand (2026-10-12):** 235 `photo`, 37 `trip`, 21 `note`, 4 `essay`. 2 Entwürfe (`notes/testing-macos-versions-i-dont-run`, `notes/optimizing-hugo-builds`). 741 Dateien in Beiträgen (558 `.jpeg`, 143 `.jpg`/`.JPG`, 18 `.png`, 1 `.webp`, 21 `.json`) und `about/sven.jpg`. Ein alter Ordner `trips/2026-summer-vacation-14/content/` aus dem Redesign-Commit `53786248` stoppt den Import; Hugo hat ihn nie benutzt. 21 Karten (18 in Reisen, 3 in Fotos), höchstens eine pro Beitrag. 711 `img`, 1 `ref`, 1 `email`, 1 `rawhtml`. Jedes Bild steht höchstens einmal in einem Beitrag. Alle Slugs sind eindeutig und bestehen nur aus `a-z0-9-`.

---

### Aufgabe 9: EXIF lesen

**Dateien:**
- Neu: `www/site/plugins/blog/exif.php`, `www/site/plugins/blog/index.php`
- Neu: `tests/Unit/ExifTest.php`, `tests/fixtures/exif.jpg`
- Ändern: `phpunit.xml` (Suite `unit`), `tests/bootstrap.php`

- [ ] **Schritt 1: Fixture kopieren**

```bash
mkdir -p tests/fixtures && cp ../concertshotsbyluisa/tests/fixtures/exif.jpg tests/fixtures/exif.jpg
```

- [ ] **Schritt 2: `phpunit.xml`: Suite `unit` vor `integration` einfügen**

```xml
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
```

- [ ] **Schritt 3: `tests/bootstrap.php`: am Ende anhängen**

```php
// Plugin and import functions for unit tests. Kirby loads the plugin with
// include_once when it starts, so nothing is defined twice.
require_once __DIR__ . '/../www/site/plugins/blog/index.php';
require_once __DIR__ . '/../bin/lib/hugo.php';
```

Bis Aufgabe 11 gibt es `bin/lib/hugo.php` noch nicht. Lege darum jetzt eine leere Datei an:

```bash
mkdir -p bin/lib && printf '<?php\n' > bin/lib/hugo.php
```

- [ ] **Schritt 4: `tests/Unit/ExifTest.php` anlegen**

```php
<?php

namespace Website\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ExifTest extends TestCase
{
    public function testReadsCameraLensAndExposure(): void
    {
        $this->assertSame([
            'camera' => 'TEST-CAM',
            'lens' => 'Test Lens 24mm',
            'focal' => '24',
            'aperture' => '2.8',
            'shutter' => '1/250',
            'iso' => '3200',
        ], blog_read_exif(__DIR__ . '/../fixtures/exif.jpg'));
    }

    public function testCameraNamesTheMakeOnce(): void
    {
        $this->assertSame('Canon EOS R6m2', blog_camera('Canon', 'Canon EOS R6m2'));
        $this->assertSame('SONY ILCE-7M4', blog_camera('SONY', 'ILCE-7M4'));
        $this->assertSame('TEST-CAM', blog_camera('', 'TEST-CAM'));
    }

    public function testFileWithoutExifGivesNothing(): void
    {
        $path = sys_get_temp_dir() . '/website-noexif-' . bin2hex(random_bytes(4)) . '.png';
        imagepng(imagecreatetruecolor(4, 4), $path);
        try {
            $this->assertSame([], blog_read_exif($path));
        } finally {
            unlink($path);
        }
    }

    public function testFieldsFromExifSections(): void
    {
        // Canon EOS R5: ISO only in tag 0x8832, which PHP does not name.
        $this->assertSame(
            ['camera' => 'Canon EOS R5', 'focal' => '35', 'aperture' => '1.8', 'shutter' => '1/100', 'iso' => '12800'],
            blog_exif_fields([
                'IFD0' => ['Make' => 'Canon', 'Model' => 'Canon EOS R5'],
                'EXIF' => ['FocalLength' => '35/1', 'FNumber' => '9/5', 'ExposureTime' => '1/100', 'UndefinedTag:0x8832' => 12800],
            ])
        );
    }

    public function testLeavesOutZeroAndBrokenValues(): void
    {
        $this->assertSame([], blog_exif_fields([
            'EXIF' => ['FocalLength' => '0/1', 'FNumber' => '0/0', 'ExposureTime' => '0/1', 'ISOSpeedRatings' => 0],
        ]));
    }

    public function testShutterSpeeds(): void
    {
        $this->assertSame('1/250', blog_shutter(1 / 250));
        $this->assertSame('1/3', blog_shutter(1 / 3));
        $this->assertSame('0.8', blog_shutter(0.8));
        $this->assertSame('2.5', blog_shutter(2.5));
        $this->assertSame('30', blog_shutter(30.0));
    }
}
```

- [ ] **Schritt 5: Plugin-Gerüst anlegen, Test rot**

`www/site/plugins/blog/index.php`:

```php
<?php

use Kirby\Cms\App as Kirby;

/**
 * The blog: posts in the sections photos, trips, notes and essays.
 */

require_once __DIR__ . '/exif.php';

Kirby::plugin('sveneisenschmidt/blog', []);
```

`www/site/plugins/blog/exif.php`:

```php
<?php
```

Run: `make kirby-test`
Expected: FAIL, `Call to undefined function blog_read_exif()` (und `blog_camera`, `blog_exif_fields`, `blog_shutter`).

- [ ] **Schritt 6: `www/site/plugins/blog/exif.php` schreiben**

```php
<?php

use Kirby\Cms\File;

/**
 * EXIF of photos. Read once, on import and on upload, and stored as file
 * fields: camera, lens, focal, aperture, shutter, iso. The values carry no
 * units; the templates format them.
 */

const BLOG_EXIF_FIELDS = ['camera', 'lens', 'focal', 'aperture', 'shutter', 'iso'];

/** Evaluate an EXIF rational such as "450/10". */
function blog_rational($v): ?float
{
    if (is_array($v)) {
        $v = reset($v);
    }
    if ($v === null || $v === '') {
        return null;
    }
    if (is_string($v) && str_contains($v, '/')) {
        [$a, $b] = array_map('floatval', explode('/', $v, 2));
        return $b != 0.0 ? $a / $b : null;
    }
    return is_numeric($v) ? (float)$v : null;
}

/** A number without trailing zeros: 2.80 -> "2.8", 45.0 -> "45". */
function blog_num(float $v): string
{
    return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
}

/** "Canon EOS R6m2" from make "Canon" and model "Canon EOS R6m2": the make once, as Hugo showed it. */
function blog_camera(string $make, string $model): string
{
    $make = trim($make);
    $model = trim($model);
    if ($make !== '' && str_starts_with($model, $make)) {
        $model = ltrim(substr($model, strlen($make)));
    }
    return trim($make . ' ' . $model);
}

/** Shutter speed: "1/250" when 1/t is a whole number, otherwise seconds ("0.8", "2.5"). */
function blog_shutter(float $t): string
{
    if ($t < 1) {
        $n = 1 / $t;
        if (abs($n - round($n)) < 0.05) {
            return '1/' . (int)round($n);
        }
    }
    return blog_num($t);
}

/** File fields from the sections exif_read_data() returns. Missing or zero values are left out. */
function blog_exif_fields(array $d): array
{
    $exif = $d['EXIF'] ?? [];
    $ifd0 = $d['IFD0'] ?? [];
    $out = [];

    $camera = blog_camera((string)($ifd0['Make'] ?? ''), (string)($ifd0['Model'] ?? ''));
    if ($camera !== '') {
        $out['camera'] = $camera;
    }

    $lens = $exif['LensModel'] ?? $exif['UndefinedTag:0xA434'] ?? '';
    if (is_string($lens) && trim($lens) !== '') {
        $out['lens'] = trim($lens);
    }

    if (($f = blog_rational($exif['FocalLength'] ?? null)) !== null && $f > 0) {
        $out['focal'] = blog_num($f);
    }

    if (($a = blog_rational($exif['FNumber'] ?? null)) !== null && $a > 0) {
        $out['aperture'] = blog_num($a);
    }

    if (($t = blog_rational($exif['ExposureTime'] ?? null)) !== null && $t > 0) {
        $out['shutter'] = blog_shutter($t);
    }

    // In Hugo's order. The Canon EOS R5 writes ISO only in the tags 0x8832
    // (RecommendedExposureIndex) and 0x8833 (ISOSpeed), which PHP does not name.
    $iso = $exif['ISOSpeedRatings']
        ?? $exif['RecommendedExposureIndex'] ?? $exif['UndefinedTag:0x8832']
        ?? $exif['ISOSpeed'] ?? $exif['UndefinedTag:0x8833']
        ?? $exif['PhotographicSensitivity']
        ?? null;
    if (is_array($iso)) {
        $iso = reset($iso);
    }
    if (is_numeric($iso) && (int)$iso > 0) {
        $out['iso'] = (string)(int)$iso;
    }

    return $out;
}

/** EXIF of an image file, as file fields. */
function blog_read_exif(string $path): array
{
    if (!function_exists('exif_read_data') || !is_file($path)) {
        return [];
    }
    return blog_exif_fields(@exif_read_data($path, null, true) ?: []);
}

/**
 * Store the EXIF of an uploaded image. On upload a value already in the
 * file's content wins. A replaced photo is a new file: all six fields come
 * from it, and a value the new file does not have is cleared.
 */
function blog_store_exif(File $file, bool $replace = false): void
{
    if ($file->type() !== 'image') {
        return;
    }
    $exif = blog_read_exif($file->root());
    $update = [];
    foreach (BLOG_EXIF_FIELDS as $key) {
        $value = $exif[$key] ?? null;
        if ($replace) {
            $update[$key] = $value;
        } elseif ($value !== null && $file->content()->get($key)->isEmpty()) {
            $update[$key] = $value;
        }
    }
    if ($update === []) {
        return;
    }
    try {
        $file->kirby()->impersonate('kirby', fn () => $file->update($update));
    } catch (Throwable $e) {
        // The upload itself worked; the fields can be filled in in the Panel.
        error_log('blog_store_exif: ' . $e->getMessage());
    }
}
```

- [ ] **Schritt 7: Test grün**

Run: `make kirby-test`
Expected: alles grün, keine Warnung.

- [ ] **Checkpoint:** Commit auf `kirby` (`tests/fixtures/exif.jpg tests/Unit/ExifTest.php tests/bootstrap.php phpunit.xml bin/lib/hugo.php www/site/plugins/blog`).

---

### Aufgabe 10: EXIF beim Hochladen

**Dateien:**
- Neu: `tests/Integration/ExifUploadTest.php`
- Ändern: `www/site/plugins/blog/index.php`

Nach der Review: Der Hook `file.replace:after` liest EXIF aus einem ersetzten Foto und leert Felder, die die neue Datei nicht hat.

- [ ] **Schritt 1: `tests/Integration/ExifUploadTest.php` anlegen**

```php
<?php

namespace Website\Tests\Integration;

use Kirby\Data\Txt;
use PHPUnit\Framework\TestCase;
use Website\Tests\Support\TestSite;

final class ExifUploadTest extends TestCase
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

    public function testUploadStoresExifAsFileFields(): void
    {
        $kirby = TestSite::app($this->root);
        $file = $kirby->impersonate('kirby', fn () => $kirby->site()
            ->createChild(['slug' => 'aika', 'template' => 'photo', 'content' => ['title' => 'Aika']])
            ->createFile([
                'source' => __DIR__ . '/../fixtures/exif.jpg',
                'filename' => 'aika.jpg',
            ]));

        $this->assertSame('TEST-CAM', $file->camera()->value());
        $this->assertSame('Test Lens 24mm', $file->lens()->value());
        $this->assertSame('24', $file->focal()->value());
        $this->assertSame('2.8', $file->aperture()->value());
        $this->assertSame('1/250', $file->shutter()->value());
        $this->assertSame('3200', $file->iso()->value());
    }

    public function testValueGivenOnCreateWins(): void
    {
        $kirby = TestSite::app($this->root);
        $file = $kirby->impersonate('kirby', fn () => $kirby->site()
            ->createChild(['slug' => 'aika', 'template' => 'photo', 'content' => ['title' => 'Aika']])
            ->createFile([
                'source' => __DIR__ . '/../fixtures/exif.jpg',
                'filename' => 'aika.jpg',
                'content' => ['camera' => 'My Camera'],
            ]));

        $this->assertSame('My Camera', $file->camera()->value());
        $this->assertSame('Test Lens 24mm', $file->lens()->value());
    }

    public function testReplaceTakesTheExifOfTheNewFile(): void
    {
        $kirby = TestSite::app($this->root);
        $plain = $this->root . '/plain.jpg';
        imagejpeg(imagecreatetruecolor(8, 8), $plain, 70);
        $file = $kirby->impersonate('kirby', fn () => $kirby->site()
            ->createChild(['slug' => 'aika', 'template' => 'photo', 'content' => ['title' => 'Aika']])
            ->createFile(['source' => __DIR__ . '/../fixtures/exif.jpg', 'filename' => 'aika.jpg']));

        $kirby->impersonate('kirby', fn () => $file->replace($plain));

        // Read from disk: the new file has no EXIF, so all six fields are gone.
        $meta = array_change_key_case(Txt::decode(file_get_contents($file->root() . '.txt')));
        foreach (['camera', 'lens', 'focal', 'aperture', 'shutter', 'iso'] as $field) {
            $this->assertArrayNotHasKey($field, $meta);
        }
    }
}
```

- [ ] **Schritt 2: Test rot**

Run: `make kirby-test`
Expected: FAIL in `testUploadStoresExifAsFileFields` (`camera` ist leer) und `testReplaceTakesTheExifOfTheNewFile`.

- [ ] **Schritt 3: Hook eintragen**

`www/site/plugins/blog/index.php` wird:

```php
<?php

use Kirby\Cms\App as Kirby;
use Kirby\Cms\File;

/**
 * The blog: posts in the sections photos, trips, notes and essays.
 */

require_once __DIR__ . '/exif.php';

Kirby::plugin('sveneisenschmidt/blog', [
    'hooks' => [
        // EXIF goes into the file's content once, so it is in every backup
        // and can be corrected in the Panel. A replaced photo brings its own.
        'file.create:after' => fn (File $file) => blog_store_exif($file),
        'file.replace:after' => fn (File $newFile) => blog_store_exif($newFile, true),
    ],
    'tags' => [
        // Where the map of a post sits in its text. Route and points are
        // page fields; phase 3 draws the map.
        'map' => [
            'html' => fn () => '<div class="map"></div>',
        ],
    ],
]);
```

- [ ] **Schritt 4: Test grün**

Run: `make kirby-test`
Expected: `OK (13 tests, 33 assertions)`.

- [ ] **Checkpoint:** Commit auf `kirby` (`tests/Integration/ExifUploadTest.php www/site/plugins/blog/index.php`).

---

### Aufgabe 11: Import-Funktionen

**Dateien:**
- Neu: `tests/Unit/HugoTest.php`
- Ändern: `bin/lib/hugo.php`

- [ ] **Schritt 1: `tests/Unit/HugoTest.php` anlegen**

```php
<?php

namespace Website\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HugoTest extends TestCase
{
    public function testReadsFrontMatterWithTablesCommentsAndDottedKeys(): void
    {
        $md = "+++\ntitle = \"Archive · Essays\"\n# a comment\ndraft = false\nbuild.list = 'never'\n[params]\n  stream = \"essays\"\n+++\n\nBody\n";
        $this->assertSame([
            ['title' => 'Archive · Essays', 'draft' => false, 'build.list' => 'never', 'params.stream' => 'essays'],
            "\nBody\n",
        ], hugo_front_matter($md));
    }

    public function testRejectsUnknownFrontMatter(): void
    {
        $this->expectException(RuntimeException::class);
        hugo_front_matter("+++\ntags = ['a']\n+++\n");
    }

    public function testReadsUnquotedDates(): void
    {
        [$fm] = hugo_front_matter("+++\ndate = 2026-06-13T09:00:00\n+++\n");
        $this->assertSame(['date' => '2026-06-13T09:00:00'], $fm);
    }

    public function testDatesAreBerlinLocalTime(): void
    {
        $this->assertSame('2026-03-11 20:40:00', hugo_date('2026-03-11T20:40:00'));
        $this->assertSame('2026-01-02 12:03:26', hugo_date('2026-01-02T12:03:26+01:00'));
        $this->assertSame('2026-07-15 23:00:00', hugo_date('2026-07-15T21:00:00Z'));
    }

    public function testSplitsIntroAtMore(): void
    {
        $this->assertSame(['Intro.', 'Rest.'], hugo_split_intro("\nIntro.\n\n<!--more-->\n\nRest.\n"));
        $this->assertSame(['', 'Only text.'], hugo_split_intro("\nOnly text.\n"));
    }

    public function testConvertsImagesAndKeepsTheirTexts(): void
    {
        [$text, $images, $map] = hugo_convert('A {{< img src="a.jpg" alt="An \"A\"" caption="See [x](https://x.test/?tab=1)" photographer="Nadine" >}} B {{< img src="b.png" alt="B">}}', fn () => '');
        $this->assertSame('A (image: a.jpg) B (image: b.png)', $text);
        $this->assertSame([
            'a.jpg' => ['alt' => 'An "A"', 'caption' => 'See [x](https://x.test/?tab=1)', 'photographer' => 'Nadine'],
            'b.png' => ['alt' => 'B', 'caption' => '', 'photographer' => ''],
        ], $images);
        $this->assertNull($map);
    }

    public function testConvertsMarkdownImagesOfTheBundle(): void
    {
        [$text, $images] = hugo_convert('![A picture](sven.jpg) and ![x](https://x.test/y.jpg)', fn () => '');
        $this->assertSame('(image: sven.jpg) and ![x](https://x.test/y.jpg)', $text);
        $this->assertSame(['sven.jpg' => ['alt' => 'A picture', 'caption' => '', 'photographer' => '']], $images);
    }

    public function testConvertsTheMapIntoPageFields(): void
    {
        [$text, , $map] = hugo_convert("Before\n\n{{< map route=\"route.json\" zoom=\"true\" >}}\n50.8297,0.3924,Norman's Bay, East Sussex\n50.8224,-0.0970,Brighton\n{{< /map >}}\n", fn () => '');
        $this->assertSame("Before\n\n(map:)\n", $text);
        $this->assertSame([
            'route' => 'route.json',
            'zoom' => true,
            'points' => [
                ['label' => "Norman's Bay, East Sussex", 'lat' => '50.8297', 'lon' => '0.3924'],
                ['label' => 'Brighton', 'lat' => '50.8224', 'lon' => '-0.0970'],
            ],
        ], $map);
    }

    public function testRejectsASecondMap(): void
    {
        $this->expectException(RuntimeException::class);
        hugo_convert('{{< map route="a.json" >}}{{< /map >}}{{< map route="b.json" >}}{{< /map >}}', fn () => '');
    }

    public function testConvertsRefLinksToUuidLinks(): void
    {
        [$text] = hugo_convert('Back to [Tournai]({{< ref "2026-summer-vacation-2" >}}).', fn (string $slug) => "uuid-of-$slug");
        $this->assertSame('Back to (link: page://uuid-of-2026-summer-vacation-2 text: Tournai).', $text);
    }

    public function testConvertsTheEmailShortcode(): void
    {
        [$text] = hugo_convert('Send me an {{< email subject="Hypo App" >}}.', fn () => '');
        $this->assertSame('Send me an [Email](mailto:sven.eisenschmidt@gmail.com?subject=Hypo%20App).', $text);
    }

    public function testUnwrapsRawHtmlWithoutBlankLines(): void
    {
        // A blank line would end the HTML block in Markdown; the rest would show as code.
        [$text] = hugo_convert("A\n\n{{< rawhtml >}}\n<script>\nlet a = 1;\n\nlet b = 2;\n</script>\n{{< /rawhtml >}}\n", fn () => '');
        $this->assertSame("A\n\n<script>\nlet a = 1;\nlet b = 2;\n</script>\n", $text);
    }

    public function testFoldsAnImageClassIntoTheTag(): void
    {
        [$text] = hugo_convert("![Sven](sven.jpg)\n{class=\"inline\"}\n\nText", fn () => '');
        $this->assertSame("(image: sven.jpg imgclass: inline)\n\nText", $text);
    }

    public function testStopsOnOtherMarkdownAttributes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('{#anchor}');
        hugo_convert("## Title\n{#anchor}\n", fn () => '');
    }

    public function testUnderscoreEmphasisAroundALinkBecomesStars(): void
    {
        [$text] = hugo_convert('Read _[The Day](https://x.test/The_Day)_ now.', fn () => '');
        $this->assertSame('Read *[The Day](https://x.test/The_Day)* now.', $text);
    }

    public function testStopsOnUnknownShortcodesAndArguments(): void
    {
        foreach (['{{< youtube abc >}}', '{{< img src="a.jpg" width="3" >}}', '{{% note %}}x{{% /note %}}'] as $body) {
            try {
                hugo_convert($body, fn () => '');
                $this->fail("No exception for $body");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
```

- [ ] **Schritt 2: Test rot**

Run: `make kirby-test`
Expected: FAIL, `Call to undefined function hugo_front_matter()`.

- [ ] **Schritt 3: `bin/lib/hugo.php` schreiben (reine Funktionen)**

```php
<?php

/**
 * Hugo to Kirby import (phase 2 of the migration). Everything the import
 * does not know stops it with an exception that names the file: nothing
 * is dropped without a word.
 */

const HUGO_SITE_TITLE = 'Sven Eisenschmidt - Personal Blog';
const HUGO_EMAIL = 'sven.eisenschmidt@gmail.com';
const HUGO_SECTIONS = ['photos' => 'photo', 'trips' => 'trip', 'notes' => 'note', 'essays' => 'essay'];
const HUGO_POST_KEYS = ['title', 'date', 'type', 'cover', 'pick', 'draft', 'trip'];
const HUGO_ABOUT_KEYS = ['title', 'date', 'showDate', 'draft', 'label', 'lastmod', 'build.list'];
const HUGO_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
// Folders and the files without a Hugo source: 2026-10-12 12:00:00 UTC.
const HUGO_FIXED_TIME = 1791806400;
// A shortcode argument: name="value", with \" inside the value.
const HUGO_ATTR = '/(\w+)="((?:[^"\\\\]|\\\\.)*)"/';

/** Splits "+++ toml +++ body" into [fields, body]. Knows the TOML this site uses. */
function hugo_front_matter(string $md): array
{
    if (!preg_match('/\A\+\+\+\n(.*?)\n\+\+\+\n?(.*)\z/s', $md, $m)) {
        throw new RuntimeException('No TOML front matter');
    }
    $fields = [];
    $table = '';
    foreach (explode("\n", $m[1]) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^\[([a-z.]+)\]$/i', $line, $t)) {
            $table = $t[1] . '.';
            continue;
        }
        if (!preg_match('/^([a-z][a-z0-9_.]*)\s*=\s*(.+)$/i', $line, $kv)) {
            throw new RuntimeException("Unknown front matter line: $line");
        }
        $fields[$table . $kv[1]] = hugo_toml_value($kv[2]);
    }
    return [$fields, $m[2]];
}

/** A TOML scalar: 'single', "double", true or false. */
function hugo_toml_value(string $raw): string|bool
{
    $raw = trim($raw);
    if ($raw === 'true' || $raw === 'false') {
        return $raw === 'true';
    }
    // An unquoted TOML date, as in date = 2026-06-13T09:00:00.
    if (preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})?)?$/', $raw)) {
        return $raw;
    }
    if (preg_match("/^'([^']*)'$/", $raw, $m)) {
        return $m[1];
    }
    if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $raw, $m)) {
        return stripcslashes($m[1]);
    }
    throw new RuntimeException("Unsupported TOML value: $raw");
}

/** Hugo date (local time or with offset) as Kirby date in Berlin time. */
function hugo_date(string $value): string
{
    $berlin = new DateTimeZone('Europe/Berlin');
    return (new DateTimeImmutable($value, $berlin))->setTimezone($berlin)->format('Y-m-d H:i:s');
}

/** Unix time of a Kirby date in Berlin time. */
function hugo_time(string $date): int
{
    return (new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin')))->getTimestamp();
}

/** [intro, text]: the text before <!--more--> and the rest. Without the marker the intro is empty. */
function hugo_split_intro(string $body): array
{
    $parts = explode('<!--more-->', $body, 2);
    return count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : ['', trim($body)];
}

/** Arguments of a shortcode. Anything that is not name="value" stops the import. */
function hugo_attrs(string $raw, array $known): array
{
    preg_match_all(HUGO_ATTR, $raw, $m, PREG_SET_ORDER);
    $attrs = [];
    // Hugo unescapes only \" in shortcode arguments.
    foreach ($m as [, $key, $value]) {
        $attrs[$key] = str_replace('\"', '"', $value);
    }
    $rest = trim(preg_replace(HUGO_ATTR, '', $raw));
    if ($rest !== '') {
        throw new RuntimeException("Unparsed shortcode arguments: $rest");
    }
    if ($unknown = array_diff(array_keys($attrs), $known)) {
        throw new RuntimeException('Unknown shortcode arguments: ' . implode(', ', $unknown));
    }
    return $attrs;
}

/**
 * Converts the Hugo shortcodes of a body to KirbyText. Returns
 * [text, images, map]: images maps a file name to its alt, caption and
 * photographer; map is null or [route, zoom, points]. $pageUuid gives the
 * UUID of a post for {{< ref "slug" >}}.
 */
function hugo_convert(string $body, callable $pageUuid): array
{
    $images = [];
    $map = null;
    $image = function (string $name, array $texts) use (&$images): string {
        if (isset($images[$name])) {
            throw new RuntimeException("Image used twice: $name");
        }
        $images[$name] = $texts + ['alt' => '', 'caption' => '', 'photographer' => ''];
        return "(image: $name)";
    };

    // KirbyText keeps HTML as it is. A blank line would end the HTML block in
    // Markdown and the rest would show as code, so blank lines go.
    $body = preg_replace_callback(
        '/\{\{<\s*rawhtml\s*>\}\}\n?(.*?)\n?\{\{<\s*\/rawhtml\s*>\}\}/s',
        fn ($m) => preg_replace('/\n[ \t]*(?=\n)/', '', $m[1]),
        $body
    );

    $body = preg_replace_callback('/\{\{<\s*map\b(.*?)>\}\}(.*?)\{\{<\s*\/map\s*>\}\}/s', function ($m) use (&$map) {
        if ($map !== null) {
            throw new RuntimeException('More than one map in a post');
        }
        $attrs = hugo_attrs($m[1], ['route', 'zoom']);
        $points = [];
        foreach (preg_split('/\R/', trim($m[2])) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // As the Hugo shortcode: latitude, longitude, the rest is the label.
            $parts = explode(',', $line, 3);
            if (count($parts) < 2 || !is_numeric(trim($parts[0])) || !is_numeric(trim($parts[1]))) {
                throw new RuntimeException("Invalid map line: $line");
            }
            $points[] = ['label' => trim($parts[2] ?? ''), 'lat' => trim($parts[0]), 'lon' => trim($parts[1])];
        }
        $map = ['route' => $attrs['route'] ?? '', 'zoom' => ($attrs['zoom'] ?? '') === 'true', 'points' => $points];
        return '(map:)';
    }, $body);

    $body = preg_replace_callback('/\{\{<\s*img\s+(.*?)\s*\/?>\}\}/s', function ($m) use ($image) {
        $attrs = hugo_attrs($m[1], ['src', 'alt', 'caption', 'photographer']);
        if (!isset($attrs['src'])) {
            throw new RuntimeException("img without src: {$m[0]}");
        }
        $src = $attrs['src'];
        unset($attrs['src']);
        return $image($src, $attrs);
    }, $body);

    // Markdown images of files in the bundle: ![alt](file.jpg).
    $body = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s\/:]+)\)/', fn ($m) => $image($m[2], ['alt' => $m[1]]), $body);

    // Goldmark attribute under an image: {class="inline"} goes onto the <img>.
    $body = preg_replace('/\(image: ([^)\s]+)\)\n\{class="([\w -]+)"\}/', '(image: $1 imgclass: $2)', $body);
    if (preg_match('/^\{(?:[.#][\w-]+|[\w-]+=)[^}\n]*\}$/m', $body, $attr)) {
        throw new RuntimeException("Unknown Markdown attribute: {$attr[0]}");
    }

    // Kirby's Markdown does not read _[text](url_with_underscores)_ as emphasis.
    $body = preg_replace('/(?<![\w*])_(\[[^\]\n]+\]\([^)\s]+\))_(?![\w*])/', '*$1*', $body);

    $body = preg_replace_callback(
        '/\[([^\]]+)\]\(\{\{<\s*ref\s+"([^"]+)"\s*>\}\}\)/',
        fn ($m) => '(link: page://' . $pageUuid($m[2]) . ' text: ' . $m[1] . ')',
        $body
    );

    // As the Hugo shortcode: a plain mailto link with the text "Email".
    $body = preg_replace_callback('/\{\{<\s*email\b(.*?)>\}\}/s', function ($m) {
        $attrs = hugo_attrs($m[1], ['subject']);
        $query = isset($attrs['subject']) ? '?subject=' . rawurlencode($attrs['subject']) : '';
        return '[Email](mailto:' . HUGO_EMAIL . $query . ')';
    }, $body);

    if (preg_match('/\{\{[<%].*?[>%]\}\}/s', $body, $left)) {
        throw new RuntimeException("Unknown shortcode: {$left[0]}");
    }
    return [$body, $images, $map];
}
```

- [ ] **Schritt 4: Test grün**

Run: `make kirby-test`
Expected: alles grün.

- [ ] **Checkpoint:** Commit auf `kirby` (`tests/Unit/HugoTest.php bin/lib/hugo.php`).

---

### Aufgabe 12: Import schreibt Kirby-Inhalte

**Dateien:**
- Neu: `tests/Support/HugoFixture.php`, `tests/Integration/ImportTest.php`
- Ändern: `bin/lib/hugo.php` (anhängen)

- [ ] **Schritt 1: `tests/Support/HugoFixture.php` anlegen**

```php
<?php

namespace Website\Tests\Support;

/**
 * A small Hugo content folder with one example of everything the import
 * handles: bundle and single-file posts, a draft, intro, img with caption
 * and photographer, a map with route, a ref link, the email shortcode,
 * rawhtml, a Markdown image, archive, search and about.
 */
final class HugoFixture
{
    /** 2026-01-01 00:00:00 UTC */
    public const TIME = 1767225600;

    /** Writes the folder below $dir and returns its path. */
    public static function create(string $dir): string
    {
        $c = $dir . '/hugo';
        foreach (['photos' => 'Photos', 'trips' => 'Trips', 'notes' => 'Notes', 'essays' => 'Essays'] as $section => $title) {
            self::write("$c/$section/_index.md", "+++\ntitle = \"$title\"\n[build]\n  list = 'never'\n  render = 'never'\n+++\n");
        }

        self::write("$c/photos/aika/index.md", <<<'MD'
+++
date = '2026-03-11T20:40:00'
title = "Aika"
type = "photo"
cover = "IMG_2334.JPG"
pick = true
+++

It was dark, all images were blurry.

<!--more-->

{{< img src="IMG_2334.JPG" alt="Aika" caption="Taken on [the roof](https://example.com)" photographer="Nadine" >}}
MD);
        self::jpeg("$c/photos/aika/IMG_2334.JPG");

        self::write("$c/trips/2026-summer-vacation-5/index.md", <<<'MD'
+++
date = '2026-07-15T22:00:00'
title = "Summer Vacation, Day 5: Brighton"
type = "trip"
trip = "summer-2026"
cover = "A6404180.jpeg"
+++

We packed up and met [Aika]({{< ref "aika" >}}) again.

<!--more-->

{{< img src="A6404180.jpeg" alt="California at the pitch">}}

{{< map route="route.json" zoom="true" >}}
50.8297,0.3924,Norman's Bay, East Sussex
50.8224,-0.0970,Brighton
{{< /map >}}
MD);
        self::jpeg("$c/trips/2026-summer-vacation-5/A6404180.jpeg");
        self::write("$c/trips/2026-summer-vacation-5/route.json", '{"type":"LineString","coordinates":[[0.3924,50.8297],[-0.097,50.8224]]}');

        self::write("$c/notes/gemma.md", "+++\ndate = '2026-06-13T09:00:00'\ntitle = \"Gemma\"\ntype = \"note\"\n+++\n\nA note without intro.\n");
        self::write("$c/notes/draft-note.md", "+++\ndate = '2026-09-01T10:00:00'\ntitle = \"Draft\"\ntype = \"note\"\ndraft = true\n+++\n\nNot yet.\n");
        self::write("$c/essays/ambiguity.md", "+++\ndate = '2026-01-22T08:00:00'\ntitle = \"Ambiguity\"\ntype = \"essay\"\n+++\n\nWrite me an {{< email subject=\"Hypo App\" >}}.\n\n<!--more-->\n\nThe rest.\n");

        self::write("$c/about/index.md", <<<'MD'
+++
date = '2026-01-02T12:03:26+01:00'
showDate = false
draft = false
title = 'About'
label = 'Personal blog'
# Von Hand
lastmod = '2026-09-12'
build.list = 'never'
+++

![A picture of Sven](sven.jpg)
{class="inline"}

{{< rawhtml >}}
<ul class="built">
<li>Reader</li>

<li>Hypo</li>
</ul>
{{< /rawhtml >}}
MD);
        self::jpeg("$c/about/sven.jpg");

        self::write("$c/archive/_index.md", "+++\ntitle = \"Archive\"\nlayout = \"archive\"\n[params]\n  stream = \"all\"\n[build]\n  list = 'never'\n+++\n");
        self::write("$c/archive/essays.md", "+++\ntitle = \"Archive · Essays\"\nlayout = \"archive\"\n[params]\n  stream = \"essays\"\n[build]\n  list = 'never'\n+++\n");
        self::write("$c/search/_index.md", "+++\ntitle = \"Search\"\nlayout = \"search\"\n[build]\n  list = 'never'\n+++\n");
        return $c;
    }

    private static function write(string $path, string $text): void
    {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $text);
        touch($path, self::TIME);
    }

    private static function jpeg(string $path): void
    {
        @mkdir(dirname($path), 0755, true);
        imagejpeg(imagecreatetruecolor(8, 8), $path, 70);
        touch($path, self::TIME);
    }
}
```

- [ ] **Schritt 2: `tests/Integration/ImportTest.php` anlegen**

```php
<?php

namespace Website\Tests\Integration;

use FilesystemIterator;
use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Website\Tests\Support\HugoFixture;
use Website\Tests\Support\TestSite;

final class ImportTest extends TestCase
{
    private string $root;
    private string $hugo;
    private array $counts;
    private App $kirby;

    protected function setUp(): void
    {
        $this->root = TestSite::create();
        Dir::remove($this->root . '/content');
        $this->hugo = HugoFixture::create($this->root);
        $this->counts = hugo_import($this->hugo, $this->root . '/content', 'blog_read_exif');
        $this->kirby = TestSite::app($this->root);
    }

    protected function tearDown(): void
    {
        TestSite::remove($this->root);
    }

    public function testCountsPostsDraftsAndFiles(): void
    {
        $this->assertSame(['photo' => 1, 'trip' => 1, 'note' => 2, 'essay' => 1, 'drafts' => 1, 'files' => 4], $this->counts);
    }

    public function testPhotoPost(): void
    {
        $page = $this->kirby->page('photos/aika');
        $this->assertSame('photo', $page->intendedTemplate()->name());
        $this->assertTrue($page->isListed());
        $this->assertSame(20260311, $page->num());
        $this->assertSame('Aika', $page->title()->value());
        $this->assertSame('2026-03-11 20:40:00', $page->date()->value());
        $this->assertSame('It was dark, all images were blurry.', $page->intro()->value());
        $this->assertSame('(image: IMG_2334.JPG)', $page->text()->value());
        $this->assertSame('IMG_2334.JPG', $page->cover()->toFile()->filename());
        $this->assertTrue($page->pick()->toBool());

        $file = $page->file('IMG_2334.JPG');
        $this->assertSame('image', $file->template());
        $this->assertSame('Aika', $file->alt()->value());
        $this->assertSame('Taken on [the roof](https://example.com)', $file->caption()->value());
        $this->assertSame('Nadine', $file->photographer()->value());
    }

    public function testTripPostWithMapAndLink(): void
    {
        $page = $this->kirby->page('trips/2026-summer-vacation-5');
        $this->assertSame('summer-2026', $page->trip()->value());
        $this->assertSame('route.json', $page->route()->toFile()->filename());
        $this->assertSame('route', $page->file('route.json')->template());
        $this->assertTrue($page->mapzoom()->toBool());

        $points = $page->mappoints()->toStructure();
        $this->assertSame(2, $points->count());
        $this->assertSame("Norman's Bay, East Sussex", $points->first()->label()->value());
        $this->assertSame('-0.0970', $points->last()->lon()->value());

        $uuid = hugo_uid('page:aika');
        $this->assertStringContainsString("(link: page://$uuid text: Aika)", $page->intro()->value());
        $this->assertSame('photos/aika', $this->kirby->page("page://$uuid")->id());
        $this->assertSame("(image: A6404180.jpeg)\n\n(map:)", $page->text()->value());
    }

    public function testNotesWithAndWithoutIntroAndDraft(): void
    {
        $gemma = $this->kirby->page('notes/gemma');
        $this->assertSame('', $gemma->intro()->value());
        $this->assertSame('A note without intro.', $gemma->text()->value());

        $draft = $this->kirby->page('notes')->drafts()->find('draft-note');
        $this->assertNotNull($draft);
        $this->assertTrue($draft->isDraft());
    }

    public function testEssayWithEmailLink(): void
    {
        $this->assertSame(
            'Write me an [Email](mailto:sven.eisenschmidt@gmail.com?subject=Hypo%20App).',
            $this->kirby->page('essays/ambiguity')->intro()->value()
        );
    }

    public function testAboutArchiveSearchSectionsAndSite(): void
    {
        $this->assertSame('Sven Eisenschmidt - Personal Blog', $this->kirby->site()->title()->value());

        $about = $this->kirby->page('about');
        $this->assertSame('Personal blog', $about->label()->value());
        $this->assertSame('2026-09-12', $about->lastmod()->value());
        $this->assertSame("(image: sven.jpg imgclass: inline)\n\n<ul class=\"built\">\n<li>Reader</li>\n<li>Hypo</li>\n</ul>", $about->text()->value());
        $this->assertSame('A picture of Sven', $about->file('sven.jpg')->alt()->value());

        $this->assertSame('all', $this->kirby->page('archive')->stream()->value());
        $this->assertSame('essays', $this->kirby->page('archive/essays')->stream()->value());
        $this->assertSame('Search', $this->kirby->page('search')->title()->value());
        $this->assertSame('Photos', $this->kirby->page('photos')->title()->value());
    }

    public function testKeepsFileTimesAndIsDeterministic(): void
    {
        $post = $this->root . '/content/photos/20260311_aika';
        // Files take the time of their post: the same on every clone, as Hugo's sitemap.
        $time = (new \DateTimeImmutable('2026-03-11 20:40:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $this->assertSame($time, filemtime("$post/photo.txt"));
        $this->assertSame($time, filemtime("$post/IMG_2334.JPG"));
        $this->assertSame($time, filemtime("$post/IMG_2334.JPG.txt"));
        $about = (new \DateTimeImmutable('2026-09-12 00:00:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $this->assertSame($about, filemtime($this->root . '/content/about/about.txt'));

        $again = $this->root . '/content-again';
        hugo_import($this->hugo, $again, 'blog_read_exif');
        $this->assertSame(self::tree($this->root . '/content'), self::tree($again));
    }

    public function testStopsOnUnknownShortcodeAndNamesTheFile(): void
    {
        file_put_contents($this->hugo . '/notes/gemma.md', "+++\ndate = '2026-06-13T09:00:00'\ntitle = \"Gemma\"\ntype = \"note\"\n+++\n\n{{< youtube abc >}}\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('!notes/gemma\.md.*youtube!');
        hugo_import($this->hugo, $this->root . '/content-broken', 'blog_read_exif');
    }

    public function testStopsOnAFolderInsideABundle(): void
    {
        mkdir($this->hugo . '/photos/aika/content');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected folder in bundle');
        hugo_import($this->hugo, $this->root . '/content-broken', 'blog_read_exif');
    }

    public function testStopsOnAnUnknownContentFolder(): void
    {
        mkdir($this->hugo . '/posts/brand-new', 0755, true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('!Unknown entry: .*/posts!');
        hugo_import($this->hugo, $this->root . '/content-broken', 'blog_read_exif');
    }

    public function testStopsOnAnUnderscoreEntryOtherThanIndex(): void
    {
        file_put_contents($this->hugo . '/notes/_draft.md', "+++\ntitle = \"X\"\n+++\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('_draft.md');
        hugo_import($this->hugo, $this->root . '/content-broken', 'blog_read_exif');
    }

    public function testStopsOnMissingTitleAndNamesTheFile(): void
    {
        file_put_contents($this->hugo . '/notes/gemma.md', "+++\ndate = '2026-06-13T09:00:00'\ntype = \"note\"\n+++\n\nText\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('!notes/gemma\.md: Missing front matter: title!');
        hugo_import($this->hugo, $this->root . '/content-broken', 'blog_read_exif');
    }

    /** Path => checksum (or "dir") and file time, for every file and folder. */
    private static function tree(string $dir): array
    {
        $out = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $path = substr($item->getPathname(), strlen($dir));
            $out[$path] = ($item->isDir() ? 'dir' : hash_file('sha256', $item->getPathname())) . ' ' . $item->getMTime();
        }
        ksort($out);
        return $out;
    }
}
```

- [ ] **Schritt 3: Test rot**

Run: `make kirby-test`
Expected: FAIL, `Call to undefined function hugo_import()`.

- [ ] **Schritt 4: `bin/lib/hugo.php` ergänzen (am Ende anhängen)**

```php
/** Stable 16-char id, used as Kirby UUID. */
function hugo_uid(string $s): string
{
    return substr(hash('sha256', 'website:' . $s), 0, 16);
}

/** Writes a Kirby content file with a fixed time. */
function hugo_write(string $path, array $fields, int $mtime): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException("Cannot create $dir");
    }
    if (file_put_contents($path, Kirby\Data\Txt::encode($fields) . "\n") === false) {
        throw new RuntimeException("Cannot write $path");
    }
    touch($path, $mtime);
}

/** Copies a file with the given time. On macOS as APFS clone: no extra space. */
function hugo_copy(string $src, string $dst, int $mtime): void
{
    $done = false;
    if (PHP_OS_FAMILY === 'Darwin') {
        exec('cp -c ' . escapeshellarg($src) . ' ' . escapeshellarg($dst) . ' 2>/dev/null', $out, $code);
        $done = $code === 0;
    }
    if (!$done && !copy($src, $dst)) {
        throw new RuntimeException("Cannot copy $src");
    }
    touch($dst, $mtime);
}

/** Files of a bundle folder, without index.md and dot files. A folder inside stops the import. */
function hugo_bundle_files(?string $dir): array
{
    if ($dir === null) {
        return [];
    }
    $files = [];
    foreach (scandir($dir) as $name) {
        if ($name[0] === '.' || $name === 'index.md') {
            continue;
        }
        if (is_dir("$dir/$name")) {
            throw new RuntimeException("Unexpected folder in bundle: $dir/$name");
        }
        $files[] = $name;
    }
    return $files;
}

/** Copies one file and writes its meta file (template, texts, EXIF, UUID), both with the given time. */
function hugo_import_file(string $src, string $dst, string $uuid, array $texts, callable $exif, int $mtime): void
{
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $image = in_array($ext, HUGO_IMAGE_EXTENSIONS, true);
    if (!$image && $ext !== 'json') {
        throw new RuntimeException("Unknown file type: $src");
    }
    hugo_copy($src, $dst, $mtime);
    $meta = ['template' => $image ? 'image' : 'route'];
    if ($image) {
        $meta += array_filter($texts, fn ($v) => $v !== '') + $exif($src);
    }
    $meta['uuid'] = $uuid;
    hugo_write("$dst.txt", $meta, $mtime);
}

/** Every post of the four sections, by slug. */
function hugo_posts(string $hugo): array
{
    $posts = [];
    foreach (HUGO_SECTIONS as $section => $type) {
        foreach (scandir("$hugo/$section") as $entry) {
            if ($entry[0] === '.' || $entry === '_index.md') {
                continue;
            }
            $path = "$hugo/$section/$entry";
            $bundle = is_dir($path);
            if (!$bundle && !str_ends_with($entry, '.md')) {
                throw new RuntimeException("Unknown entry: $path");
            }
            $slug = $bundle ? $entry : substr($entry, 0, -3);
            if (isset($posts[$slug])) {
                throw new RuntimeException("Slug used twice: $slug");
            }
            $md = $bundle ? "$path/index.md" : $path;
            try {
                [$fm, $body] = hugo_front_matter(file_get_contents($md));
            } catch (RuntimeException $e) {
                throw new RuntimeException("$md: " . $e->getMessage(), 0, $e);
            }
            $posts[$slug] = [
                'section' => $section,
                'type' => $type,
                'slug' => $slug,
                'md' => $md,
                'dir' => $bundle ? $path : null,
                'fm' => $fm,
                'body' => $body,
                'draft' => ($fm['draft'] ?? false) === true,
            ];
        }
    }
    return $posts;
}

/** Writes one post and its files. Returns the number of files. */
function hugo_import_post(array $post, string $content, callable $pageUuid, callable $exif): int
{
    $fm = $post['fm'];
    if ($unknown = array_diff(array_keys($fm), HUGO_POST_KEYS)) {
        throw new RuntimeException('Unknown front matter: ' . implode(', ', $unknown));
    }
    foreach (['title', 'date', 'type'] as $key) {
        if (!is_string($fm[$key] ?? null) || $fm[$key] === '') {
            throw new RuntimeException("Missing front matter: $key");
        }
    }
    if ($fm['type'] !== $post['type']) {
        throw new RuntimeException("type is not {$post['type']}");
    }
    [$body, $images, $map] = hugo_convert($post['body'], $pageUuid);
    [$intro, $text] = hugo_split_intro($body);

    $files = hugo_bundle_files($post['dir']);
    $fileUuid = fn (string $name) => hugo_uid('file:' . $post['slug'] . '/' . $name);
    $needed = array_merge(array_keys($images), array_filter([$fm['cover'] ?? null, $map['route'] ?? null]));
    if ($missing = array_diff($needed, $files)) {
        throw new RuntimeException('Missing files: ' . implode(', ', $missing));
    }

    $date = hugo_date($fm['date']);
    // Content and files take the time of the post: the same on every clone, as Hugo's sitemap.
    $time = hugo_time($date);
    $fields = ['title' => $fm['title'], 'date' => $date, 'intro' => $intro, 'text' => $text];
    if (isset($fm['cover'])) {
        $fields['cover'] = ['file://' . $fileUuid($fm['cover'])];
    }
    if (($fm['pick'] ?? false) === true) {
        $fields['pick'] = 'true';
    }
    if (isset($fm['trip'])) {
        $fields['trip'] = $fm['trip'];
    }
    if ($map !== null) {
        $fields['route'] = ['file://' . $fileUuid($map['route'])];
        $fields['mappoints'] = $map['points'];
        if ($map['zoom']) {
            $fields['mapzoom'] = 'true';
        }
    }
    $fields['uuid'] = hugo_uid('page:' . $post['slug']);

    $folder = $content . '/' . $post['section'] . '/'
        . ($post['draft'] ? '_drafts/' . $post['slug'] : str_replace('-', '', substr($date, 0, 10)) . '_' . $post['slug']);
    hugo_write("$folder/{$post['type']}.txt", $fields, $time);
    foreach ($files as $name) {
        hugo_import_file($post['dir'] . '/' . $name, "$folder/$name", $fileUuid($name), $images[$name] ?? [], $exif, $time);
    }
    return count($files);
}

/** Writes the about page and its files. Returns the number of files. */
function hugo_import_about(string $hugo, string $content, callable $pageUuid, callable $exif): int
{
    $md = "$hugo/about/index.md";
    [$fm, $body] = hugo_front_matter(file_get_contents($md));
    if ($unknown = array_diff(array_keys($fm), HUGO_ABOUT_KEYS)) {
        throw new RuntimeException("$md: unknown front matter " . implode(', ', $unknown));
    }
    if (($fm['draft'] ?? false) === true) {
        throw new RuntimeException("$md: about is a draft");
    }
    [$text, $images, $map] = hugo_convert($body, $pageUuid);
    if ($map !== null) {
        throw new RuntimeException("$md: map on the about page");
    }
    $time = isset($fm['lastmod']) ? hugo_time(hugo_date($fm['lastmod'])) : HUGO_FIXED_TIME;
    hugo_write("$content/about/about.txt", [
        'title' => $fm['title'],
        'label' => $fm['label'] ?? '',
        'lastmod' => $fm['lastmod'] ?? '',
        'text' => trim($text),
        'uuid' => hugo_uid('about'),
    ], $time);
    $files = hugo_bundle_files("$hugo/about");
    foreach ($files as $name) {
        hugo_import_file("$hugo/about/$name", "$content/about/$name", hugo_uid("file:about/$name"), $images[$name] ?? [], $exif, $time);
    }
    return count($files);
}

/** Writes the archive page and its children, one per stream. */
function hugo_import_archive(string $hugo, string $content): void
{
    foreach (scandir("$hugo/archive") as $entry) {
        if (!str_ends_with($entry, '.md')) {
            continue;
        }
        $md = "$hugo/archive/$entry";
        [$fm] = hugo_front_matter(file_get_contents($md));
        if ($unknown = array_diff(array_keys($fm), ['title', 'layout', 'params.stream', 'build.list'])) {
            throw new RuntimeException("$md: unknown front matter " . implode(', ', $unknown));
        }
        $child = $entry === '_index.md' ? '' : '/' . substr($entry, 0, -3);
        hugo_write("$content/archive$child/archive.txt", [
            'title' => $fm['title'],
            'stream' => $fm['params.stream'],
            'uuid' => hugo_uid("archive$child"),
        ], HUGO_FIXED_TIME);
    }
}

/**
 * Imports the Hugo content folder $hugo into $content, which must not
 * exist yet. $exif reads the EXIF of an image. Returns the counts.
 */
function hugo_import(string $hugo, string $content, callable $exif): array
{
    if (file_exists($content)) {
        throw new RuntimeException("$content exists");
    }
    // Only the folders the import knows; anything else would be lost without a word.
    $known = [...array_keys(HUGO_SECTIONS), 'about', 'archive', 'search'];
    foreach (scandir($hugo) as $entry) {
        if ($entry[0] !== '.' && !in_array($entry, $known, true)) {
            throw new RuntimeException("Unknown entry: $hugo/$entry");
        }
    }
    $posts = hugo_posts($hugo);
    $pageUuid = fn (string $slug) => isset($posts[$slug])
        ? hugo_uid("page:$slug")
        : throw new RuntimeException("ref to unknown post: $slug");

    hugo_write("$content/site.txt", ['title' => HUGO_SITE_TITLE, 'uuid' => hugo_uid('site')], HUGO_FIXED_TIME);
    hugo_write("$content/home/home.txt", ['title' => 'Home', 'uuid' => hugo_uid('home')], HUGO_FIXED_TIME);
    hugo_write("$content/error/error.txt", ['title' => 'Page not found', 'uuid' => hugo_uid('error')], HUGO_FIXED_TIME);
    foreach (array_keys(HUGO_SECTIONS) as $section) {
        $md = "$hugo/$section/_index.md";
        [$fm] = hugo_front_matter(file_get_contents($md));
        if ($unknown = array_diff(array_keys($fm), ['title', 'build.list', 'build.render'])) {
            throw new RuntimeException("$md: unknown front matter " . implode(', ', $unknown));
        }
        hugo_write("$content/$section/$section.txt", ['title' => $fm['title'], 'uuid' => hugo_uid("section:$section")], HUGO_FIXED_TIME);
    }

    $counts = ['photo' => 0, 'trip' => 0, 'note' => 0, 'essay' => 0, 'drafts' => 0, 'files' => 0];
    foreach ($posts as $post) {
        try {
            $counts['files'] += hugo_import_post($post, $content, $pageUuid, $exif);
        } catch (Throwable $e) {
            throw new RuntimeException($post['md'] . ': ' . $e->getMessage(), 0, $e);
        }
        $counts[$post['type']]++;
        $counts['drafts'] += $post['draft'] ? 1 : 0;
    }
    $counts['files'] += hugo_import_about($hugo, $content, $pageUuid, $exif);
    hugo_import_archive($hugo, $content);

    $md = "$hugo/search/_index.md";
    [$fm] = hugo_front_matter(file_get_contents($md));
    if ($unknown = array_diff(array_keys($fm), ['title', 'layout', 'build.list'])) {
        throw new RuntimeException("$md: unknown front matter " . implode(', ', $unknown));
    }
    hugo_write("$content/search/search.txt", ['title' => $fm['title'], 'uuid' => hugo_uid('search')], HUGO_FIXED_TIME);

    // One fixed time for all folders: Kirby's Site::modified() reads them too.
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($content, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        if ($item->isDir()) {
            touch($item->getPathname(), HUGO_FIXED_TIME);
        }
    }
    touch($content, HUGO_FIXED_TIME);
    return $counts;
}
```

- [ ] **Schritt 5: Test grün**

Run: `make kirby-test`
Expected: alles grün.

- [ ] **Checkpoint:** Commit auf `kirby` (`tests/Support/HugoFixture.php tests/Integration/ImportTest.php bin/lib/hugo.php`).

---

### Aufgabe 13: Blueprints für das Panel

**Dateien:**
- Neu: `tests/Integration/BlueprintTest.php`
- Neu: `www/site/blueprints/site.yml`
- Neu: `www/site/blueprints/pages/{photos,trips,notes,essays,photo,trip,note,essay,about,archive,search}.yml`
- Neu: `www/site/blueprints/sections/{post-text,post-meta,post-map,post-images,post-files}.yml`
- Neu: `www/site/blueprints/files/{image,route}.yml`

Labels im Panel sind deutsch, wie bei Luisa.

- [ ] **Schritt 1: `tests/Integration/BlueprintTest.php` anlegen**

```php
<?php

namespace Website\Tests\Integration;

use Kirby\Cms\Page;
use Kirby\Data\Data;
use Kirby\Filesystem\Dir;
use Kirby\Form\Form;
use PHPUnit\Framework\TestCase;
use Website\Tests\Support\HugoFixture;
use Website\Tests\Support\TestSite;

final class BlueprintTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TestSite::create();
        Dir::remove($this->root . '/content');
        hugo_import(HugoFixture::create($this->root), $this->root . '/content', 'blog_read_exif');
    }

    protected function tearDown(): void
    {
        TestSite::remove($this->root);
    }

    public function testEveryImportedPageAndFileHasItsBlueprint(): void
    {
        $kirby = TestSite::app($this->root);
        $pages = [
            'photos/aika' => 'Foto',
            'trips/2026-summer-vacation-5' => 'Reise',
            'notes/gemma' => 'Notiz',
            'essays/ambiguity' => 'Essay',
            'photos' => 'Fotos',
            'trips' => 'Reisen',
            'notes' => 'Notizen',
            'essays' => 'Essays',
            'about' => 'Über mich',
            'archive' => 'Archiv',
            'archive/essays' => 'Archiv',
            'search' => 'Suche',
        ];
        foreach ($pages as $id => $title) {
            $this->assertSame($title, $kirby->page($id)->blueprint()->title(), $id);
        }
        $this->assertSame('Website', $kirby->site()->blueprint()->title());

        $trip = $kirby->page('trips/2026-summer-vacation-5');
        foreach (['intro', 'text', 'date', 'cover', 'pick', 'trip', 'route', 'mappoints', 'mapzoom'] as $field) {
            $this->assertNotNull($trip->blueprint()->field($field), "trip: $field");
        }
        $this->assertSame('Bild', $trip->file('A6404180.jpeg')->blueprint()->title());
        $this->assertSame('Route', $trip->file('route.json')->blueprint()->title());
        foreach (['alt', 'caption', 'photographer', 'camera', 'lens', 'focal', 'aperture', 'shutter', 'iso'] as $field) {
            $this->assertNotNull($trip->file('A6404180.jpeg')->blueprint()->field($field), "image: $field");
        }
    }

    public function testAnUnchangedPanelSaveKeepsTheImportedValues(): void
    {
        $kirby = TestSite::app($this->root);
        $kirby->impersonate('kirby');

        // A post late in the evening: if the date field rounds, an unchanged
        // save moves it to the next day (and its folder number).
        $photo = $kirby->page('photos')->createChild([
            'slug' => 'late-night',
            'template' => 'photo',
            'content' => ['title' => 'Late night', 'date' => '2026-02-24 23:59:40'],
        ])->changeStatus('listed');
        $this->assertSame('2026-02-24 23:59:40', $this->panelSave($photo)['date']);

        $trip = $kirby->page('trips/2026-summer-vacation-5');
        $stored = $this->panelSave($trip);
        foreach (['date', 'intro', 'text', 'cover', 'route', 'trip', 'mappoints', 'mapzoom'] as $field) {
            // Files and structures come back decoded, the content file holds YAML.
            $imported = $trip->content()->get($field)->value();
            $expected = is_array($stored[$field]) ? Data::decode($imported, 'yaml') : $imported;
            $this->assertSame($expected, $stored[$field], "trip: $field");
        }
    }

    /**
     * What the Panel stores when the form is saved without a change: the form
     * values go back through a fresh form of the same page.
     */
    private function panelSave(Page $page): array
    {
        $values = Form::for($page)->toFormValues();
        return Form::for($page)->submit($values, force: true)->toStoredValues();
    }
}
```

- [ ] **Schritt 2: Test rot**

Run: `make kirby-test`
Expected: FAIL, der erste Titel ist nicht `Foto`.

- [ ] **Schritt 3: Abschnitte anlegen**

`www/site/blueprints/sections/post-text.yml`:

```yaml
type: fields
fields:
  intro:
    label: Anriss
    type: textarea
    size: small
    uploads: image
    help: Steht auf der Startseite und über dem Text.
  text:
    label: Text
    type: textarea
    size: huge
    uploads: image
```

`www/site/blueprints/sections/post-meta.yml`:

```yaml
type: fields
fields:
  date:
    label: Datum
    type: date
    time:
      step:
        unit: second
        size: 1
      display: HH:mm:ss
    default: now
    required: true
  cover:
    label: Titelbild
    type: files
    multiple: false
    query: page.images
    uploads: image
  pick:
    label: Hervorgehoben
    type: toggle
```

`www/site/blueprints/sections/post-map.yml`:

```yaml
type: fields
fields:
  route:
    label: Route (GeoJSON)
    type: files
    multiple: false
    query: page.files.filterBy("extension", "json")
    uploads: route
  mappoints:
    label: Kartenpunkte
    type: structure
    fields:
      label:
        label: Name
        type: text
      lat:
        label: Breite
        type: text
      lon:
        label: Länge
        type: text
  mapzoom:
    label: Karte zoombar
    type: toggle
```

`www/site/blueprints/sections/post-images.yml`:

```yaml
type: files
label: Fotos
template: image
```

`www/site/blueprints/sections/post-files.yml`:

```yaml
type: files
label: Routen
template: route
```

- [ ] **Schritt 4: Beiträge anlegen**

`www/site/blueprints/pages/photo.yml`:

```yaml
title: Foto
icon: image
num: date
status:
  draft: Entwurf
  listed: Veröffentlicht
columns:
  main:
    width: 2/3
    sections:
      body:
        extends: sections/post-text
  sidebar:
    width: 1/3
    sections:
      meta:
        extends: sections/post-meta
      images:
        extends: sections/post-images
      map:
        extends: sections/post-map
      routes:
        extends: sections/post-files
```

`www/site/blueprints/pages/trip.yml`:

```yaml
title: Reise
icon: map
num: date
status:
  draft: Entwurf
  listed: Veröffentlicht
columns:
  main:
    width: 2/3
    sections:
      body:
        extends: sections/post-text
  sidebar:
    width: 1/3
    sections:
      meta:
        extends: sections/post-meta
      journey:
        type: fields
        fields:
          trip:
            label: Reise
            type: tags
            max: 1
            accept: all
            options:
              type: query
              query: page.parent.childrenAndDrafts.pluck("trip", ",", true)
      images:
        extends: sections/post-images
      map:
        extends: sections/post-map
      routes:
        extends: sections/post-files
```

`www/site/blueprints/pages/note.yml`:

```yaml
title: Notiz
icon: text
num: date
status:
  draft: Entwurf
  listed: Veröffentlicht
columns:
  main:
    width: 2/3
    sections:
      body:
        extends: sections/post-text
  sidebar:
    width: 1/3
    sections:
      meta:
        extends: sections/post-meta
      images:
        extends: sections/post-images
```

`www/site/blueprints/pages/essay.yml`: wie `note.yml`, nur `title: Essay` und `icon: book`.

- [ ] **Schritt 5: Sektionen anlegen**

`www/site/blueprints/pages/photos.yml`:

```yaml
title: Fotos
icon: images
options:
  changeSlug: false
  changeStatus: false
  changeTemplate: false
  delete: false
  duplicate: false
sections:
  posts:
    type: pages
    label: Fotos
    template: photo
    sortBy: date desc
    image:
      query: page.cover.toFile
```

`trips.yml`, `notes.yml`, `essays.yml` genauso, mit:

| Datei | `title` und `label` | `icon` | `template` | `image` |
|---|---|---|---|---|
| `trips.yml` | Reisen | `map` | `trip` | `query: page.cover.toFile` |
| `notes.yml` | Notizen | `text` | `note` | `false` |
| `essays.yml` | Essays | `book` | `essay` | `false` |

- [ ] **Schritt 6: About, Archiv, Suche anlegen**

`www/site/blueprints/pages/about.yml`:

```yaml
title: Über mich
icon: user
options:
  changeSlug: false
  changeStatus: false
  delete: false
columns:
  main:
    width: 2/3
    fields:
      text:
        label: Text
        type: textarea
        size: huge
        uploads: image
  sidebar:
    width: 1/3
    sections:
      meta:
        type: fields
        fields:
          label:
            label: Bezeichnung
            type: text
          lastmod:
            label: Zuletzt geprüft
            type: date
      images:
        extends: sections/post-images
```

`www/site/blueprints/pages/archive.yml`:

```yaml
title: Archiv
icon: archive
options:
  changeSlug: false
  changeStatus: false
  delete: false
sections:
  settings:
    type: fields
    fields:
      stream:
        label: Inhalt
        type: select
        required: true
        options:
          all: Alle Beiträge
          photos: Fotos
          trips: Reisen
          notes: Notizen
          essays: Essays
  streams:
    type: pages
    label: Unterseiten
    template: archive
    create: false
```

`www/site/blueprints/pages/search.yml`:

```yaml
title: Suche
icon: search
options:
  changeSlug: false
  changeStatus: false
  delete: false
```

- [ ] **Schritt 7: Dateien und Website anlegen**

`www/site/blueprints/files/image.yml`:

```yaml
title: Bild
accept:
  type: image
columns:
  - width: 1/2
    fields:
      alt:
        label: Alternativtext
        type: text
      caption:
        label: Bildunterschrift
        type: text
        help: Markdown für Links.
      photographer:
        label: Fotografiert von
        type: text
  - width: 1/2
    fields:
      camera:
        label: Kamera
        type: text
      lens:
        label: Objektiv
        type: text
      focal:
        label: Brennweite (mm)
        type: text
        width: 1/2
      aperture:
        label: Blende (f/)
        type: text
        width: 1/2
      shutter:
        label: Belichtungszeit (s)
        type: text
        width: 1/2
      iso:
        label: ISO
        type: text
        width: 1/2
```

`www/site/blueprints/files/route.yml`:

```yaml
title: Route
accept:
  extension: json
```

`www/site/blueprints/site.yml`:

```yaml
title: Website
columns:
  - width: 1/2
    sections:
      photos:
        type: pages
        label: Fotos
        parent: site.find("photos")
        template: photo
        sortBy: date desc
        limit: 10
        image:
          query: page.cover.toFile
      trips:
        type: pages
        label: Reisen
        parent: site.find("trips")
        template: trip
        sortBy: date desc
        limit: 10
        image:
          query: page.cover.toFile
  - width: 1/2
    sections:
      notes:
        type: pages
        label: Notizen
        parent: site.find("notes")
        template: note
        sortBy: date desc
        limit: 10
        image: false
      essays:
        type: pages
        label: Essays
        parent: site.find("essays")
        template: essay
        sortBy: date desc
        limit: 10
        image: false
      pages:
        type: pages
        label: Seiten
        create: false
        templates:
          - about
          - archive
          - search
```

- [ ] **Schritt 8: Test grün**

Run: `make kirby-test`
Expected: alles grün.

- [ ] **Checkpoint:** Commit auf `kirby` (`tests/Integration/BlueprintTest.php www/site/blueprints`).

---

### Aufgabe 14: Seed ist der Import, Template zeigt den Text

**Dateien:**
- Ändern: `bin/seed.php`, `www/site/templates/default.php`
- Ändern: `tests/Support/TestSite.php` (Seitentitel), `tests/Integration/RenderTest.php`

- [ ] **Schritt 1: Seitentitel in den Tests**

In `tests/Support/TestSite.php` wird `"Title: Sven Eisenschmidt\n"` zu `"Title: Sven Eisenschmidt - Personal Blog\n"`. In `tests/Integration/RenderTest.php` wird in beiden `<title>`-Erwartungen ` · Sven Eisenschmidt</title>` zu ` · Sven Eisenschmidt - Personal Blog</title>`.

- [ ] **Schritt 2: Render-Test für Text, Bild und Karte anhängen**

In `tests/Integration/RenderTest.php` vor der letzten `}`:

```php

    public function testRendersIntroTextImagesAndTheMapTag(): void
    {
        $kirby = TestSite::app($this->root);
        $page = $kirby->impersonate('kirby', function () use ($kirby) {
            $draft = $kirby->site()->createChild([
                'slug' => 'note-x',
                'template' => 'note',
                'content' => ['title' => 'Note', 'date' => '2026-01-01 10:00:00', 'intro' => 'Hello **world**', 'text' => "(image: a.jpg)\n\n(map:)"],
            ]);
            $draft->createFile(['source' => __DIR__ . '/../fixtures/exif.jpg', 'filename' => 'a.jpg', 'template' => 'image']);
            return $draft->changeStatus('listed');
        });

        $html = $page->render();

        $this->assertStringContainsString('<strong>world</strong>', $html);
        $this->assertStringContainsString('<div class="map"></div>', $html);
        $this->assertMatchesRegularExpression('!<img [^>]*src="http://localhost/media/pages/note-x/[^"]+/a\.jpg"!', $html);
    }
```

Run: `make kirby-test`
Expected: FAIL in `testRendersIntroTextImagesAndTheMapTag`, kein `<strong>world</strong>`.

- [ ] **Schritt 3: `www/site/templates/default.php`**

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
    <?= $page->intro()->kt() ?>
    <?= $page->text()->kt() ?>
</body>
</html>
```

Run: `make kirby-test`
Expected: alles grün.

- [ ] **Schritt 4: `bin/seed.php` neu schreiben**

```php
<?php

/**
 * Builds www/content from the Hugo site in content/ (the import of phase 2)
 * and creates a local Panel login. Same input, same files and file times.
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

require $root . '/vendor/autoload.php';
require $root . '/www/kirby/bootstrap.php';
require_once $root . '/www/site/plugins/blog/exif.php';
require_once $root . '/bin/lib/hugo.php';

if (!function_exists('exif_read_data')) {
    fwrite(STDERR, "PHP has no exif extension: the import would lose all camera data.\n");
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

// Import next to www/content and swap only on success: a failed import
// (an unknown field in a new post) leaves the current content as it is.
$staging = $content . '.new';
rrmdir($staging);
try {
    $counts = hugo_import($root . '/content', $staging, 'blog_read_exif');
} catch (Throwable $e) {
    rrmdir($staging);
    fwrite(STDERR, "Import failed, www/content is unchanged.\n" . $e->getMessage() . "\n");
    exit(1);
}
rrmdir($content);
if (!rename($staging, $content)) {
    fwrite(STDERR, "Cannot move $staging to $content.\n");
    exit(1);
}

// Dev account, only if no account exists yet (keeps a real local account untouched).
$accounts = $root . '/www/site/accounts';
// Only account folders count: Kirby also writes a .logins file there.
$hasAccounts = count(glob($accounts . '/*', GLOB_ONLYDIR) ?: []) > 0;
if (!$hasAccounts) {
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

$posts = $counts['photo'] + $counts['trip'] + $counts['note'] + $counts['essay'];
printf(
    "Imported %d posts (%d photo, %d trip, %d note, %d essay), %d drafts, %d files.\n",
    $posts, $counts['photo'], $counts['trip'], $counts['note'], $counts['essay'], $counts['drafts'], $counts['files']
);
```

- [ ] **Schritt 5: Echten Bestand importieren**

Run: `make kirby-seed FORCE=1`
Expected: `Imported 297 posts (235 photo, 37 trip, 21 note, 4 essay), 2 drafts, 742 files.`

Bricht der Import mit einer Fundstelle ab, steht im Bestand etwas, das die Tests nicht abdecken. Dann: anhalten und berichten, nicht im Import improvisieren.

- [ ] **Schritt 6: Alle Prüfungen**

Run: `make kirby-lint && make kirby-test && make kirby-restore-test`
Expected: `lint ok`, alles grün, `OK: 307 pages identical`. Mit 4 GB Inhalt dauert der Restore-Test einige Minuten. 307 sind `/`, 295 Beiträge, 4 Sektionen, Archiv mit 4 Unterseiten, Suche und About.

- [ ] **Schritt 7: Im Browser prüfen**

Vorschau `kirby-dev`, Anmeldung mit dem Dev-Zugang:
- Panel-Startseite zeigt Fotos, Reisen, Notizen, Essays und Seiten, die neuesten oben, mit Titelbild.
- Ein Reise-Beitrag zeigt Anriss, Text, Datum, Titelbild, Reise, Fotos, Karte und Route.
- Ein Foto zeigt Alternativtext, Bildunterschrift und Kameradaten.
- `http://localhost:8010/photos/aika` zeigt Titel, Anriss und das Foto.

- [ ] **Checkpoint:** Commit auf `kirby` (`bin/seed.php www/site/templates/default.php tests/Support/TestSite.php tests/Integration/RenderTest.php`).

---

### Aufgabe 15: Markdown-Editor im Panel (nur mit Svens Ja)

Das Plugin [`fabianmichael/kirby-markdown-field`](https://packagist.org/packages/fabianmichael/kirby-markdown-field) (4.0.0, Kirby 5, PHP 8.4+) ersetzt das Textfeld durch einen Editor, der Markdown und KirbyText hervorhebt. Gespeichert wird weiter Markdown.

**Dateien:**
- Ändern: `composer.json`, `composer.lock`, `.gitignore`, `.git/info/exclude`
- Ändern: `www/site/blueprints/sections/post-text.yml`, `www/site/blueprints/pages/about.yml`

- [ ] **Schritt 1: Installationsort für Plugins festlegen**

In `composer.json` unter `extra` ergänzen: `"kirby-plugin-path": "www/site/plugins"`. In `.gitignore` im Block „Kirby: von Composer installiert“ und in `.git/info/exclude` die Zeile `/www/site/plugins/markdown-field/` ergänzen.

- [ ] **Schritt 2: Installieren**

Run: `composer require fabianmichael/kirby-markdown-field:^4.0`
Expected: Das Plugin liegt in `www/site/plugins/markdown-field/`, `git status --short` zeigt den Ordner nicht.

- [ ] **Schritt 3: Feldtyp im README nachsehen**

Run: `grep -n "type:" www/site/plugins/markdown-field/README.md | head`
Expected: Das README nennt `type: markdown`. Steht dort ein anderer Name, nimm diesen in Schritt 4.

- [ ] **Schritt 4: Felder umstellen**

In `sections/post-text.yml` bei `intro` und `text` und in `pages/about.yml` bei `text`: `type: textarea` wird `type: markdown`. `size` bleibt.

- [ ] **Schritt 5: Prüfen**

Run: `make kirby-test`
Expected: alles grün.

Im Panel einen Beitrag öffnen: Überschriften und Fettschrift erscheinen hervorgehoben. Ein Foto aus der Dateiliste in den Text ziehen ergibt `(image: …)`.

- [ ] **Checkpoint:** Commit auf `kirby` (`composer.json composer.lock .gitignore www/site/blueprints`).
