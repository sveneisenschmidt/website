# Umzug von Hugo auf Kirby: Phase 5a (Staging)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Ziel:** Kirby läuft auf https://kirby.eisenschmidt.website hinter dem Staging-Passwort, mit allen importierten Inhalten, deployt vom Branch `kirby`. Hugo bleibt live auf sven.eisenschmidt.website. Staging dient zum Testen. Vor dem Umschalten (Phase 5b) kommt der Import noch einmal, was auf Staging entsteht, geht verloren.

**Architektur:** Der Ordner auf dem Server sieht aus wie das Repo: `www/`, `vendor/` und `storage/` nebeneinander. Das Document Root in KAS ist der Ordner selbst, wie bei Luisa. Eine `.htaccess` im Ordner (`server/root.htaccess`) schickt jede Anfrage nach `www/`, so ist alles daneben von außen nicht erreichbar. Deploy, Pull und Snapshots kommen aus Luisas Repo: Build mit `composer install --no-dev` in einem Temp-Ordner, Snapshot vor jedem Deploy, rsync ohne Zustand, Host-Config über `set-env.php`, Staging-Passwort, Seiten-Cache leeren unter der Sperre. Der erste Stand entsteht lokal (Seed, Pop-Import) und kommt als Snapshot-Ordner ohne Konten hoch.

**Technik:** Bash, rsync, ssh, Composer, PHP 8.5 (auf dem Server `php85`), Apache `.htaccess`. Deploy vom Mac.

**Grundlage:** `docs/superpowers/plans/2026-10-12-kirby-umzug.md` (Gesamtplan, Rückbau, „Was Phase 2 bis 5 wissen müssen"), `docs/superpowers/plans/2026-10-12-kirby-phase-3-4.md` („Was Phase 5 wissen muss"), `docs/superpowers/plans/2026-10-12-assistent.md` („Vor dem Start", „Phase 3 und Phase 5"), `docs/superpowers/specs/2026-10-12-assistent-design.md` („Voraussetzungen und Phase 5"). Phase 1 bis 4 und der Assistent sind fertig. Die Aufgaben zählen weiter: 31 bis 40.

**Regeln für die Ausführung:**
- Branch `kirby`. Claude committet und pusht nie. Jeder Checkpoint nennt die Pfade, Sven committet (nie `git add -A`).
- Hugo bleibt, wie es ist: die Live-Seite, ihr Ordner `sven.eisenschmidt.website`, `.github/workflows/deploy.yml` und `indexnow.yml`.
- Auf dem Server nur der Ordner `/www/htdocs/<konto>/kirby.eisenschmidt.website/`. Luisas Ordner und der gemeinsame Ordner `/www/htdocs/<konto>/backups/` nie. Pops `var/data.db` nur lesen.
- `../concertshotsbyluisa` nur lesen.
- Markierungen an den Schritten:
  - **(Sven)** macht Sven selbst: KAS, Passwörter, Browser, Prompt.
  - **(SSH)** führt der Controller über `ssh website` aus, nur mit Svens Ja im Chat für diesen Schritt.
  - **(Svens Terminal)** braucht das Staging-Passwort, der Deploy auch den Anthropic-Schlüssel. Sven setzt beides einmal pro Terminal, danach startet er den Befehl oder der Controller in Svens Terminal-Tab:
    ```zsh
    read -r "STAGING_USER?Staging-Benutzer: "; read -rs "STAGING_PASSWORD?Staging-Passwort: "; echo; read -rs "ANTHROPIC_API_KEY?Anthropic-Schlüssel: "; echo; export STAGING_USER STAGING_PASSWORD ANTHROPIC_API_KEY
    ```
    Seit Aufgabe 38 entfällt das: `make kirby-staging-login` legt Benutzer und Passwort einmal im Schlüsselbund ab (Dienst `kirby-staging`). `kirby-deploy`, `kirby-warm` und `kirby-check-urls SITE=…` holen sie über `bin/staging-env.sh`, den API-Schlüssel aus der lokalen `env.php`. Mit `PUBLIC=1` holt das Skript kein Staging-Passwort.
- Claude liest Passwort, Schlüssel und die Werte in `env.php` nie. Prüfungen von `env.php` geben nur die Namen der Schlüssel aus.
- Der Prompt des Assistenten steht nicht in diesem Plan, nicht in Git und auf keiner Kommandozeile.
- Nach jeder Aufgabe mit Code: `make kirby-lint && make kirby-test && make kirby-restore-test` grün.
- Ports: Deploy-Test 8095. `kirby-dev` 8010, `kirby-restore-test` 8093 und 8094.

**Gemessen im Probelauf (Kopie des Repos, 2026-10-13):** Die Codeblöcke dieses Plans stammen wörtlich aus dieser Kopie. Als Server diente ein Ordner auf dem Mac, ausgeliefert von Apache 2.4.67 mit PHP-FPM 8.5: Document Root war der Ordner der Seite, beide `.htaccess` aktiv, Staging-Block an.
- Deploy in den leeren Ordner: 3,7 s (Composer aus dem Cache). Danach liegen dort `.htaccess`, `.htpasswd`, `www/` und `vendor/` ohne PHPUnit, der Platzhalter `index.htm` ist weg.
- Upload eines Snapshots (4,39 GB, 1.798 Dateien) und Restore: 15 s. Der Deploy danach meldet „State unchanged … no new snapshot": der hochgeladene Snapshot gleicht dem Zustand bis auf Zeit und Rechte. Mit einem neuen Konto entsteht ein Snapshot von 276 KB, der Rest sind harte Links. Deploy mit 4,1 GB Zustand: 19,6 s.
- Ohne Passwort antwortet jede Adresse mit 401. Ausnahme `/.well-known/`: eine Datei dort 200, eine fehlende 404.
- Mit Passwort geben `/vendor/autoload.php`, `/storage/assistant/x`, `/www/content/site.txt`, `/content/site.txt`, `/www/site/config/env.php`, `/site/config/env.php` und `/backups/` Kirbys 404, `/.htpasswd` und `/www/.htaccess` 403.
- Kein Link enthält `/www/`. `/2026-10-09-green-woodpecker` leitet mit 301 auf die Adresse mit Schrägstrich, `/photos/` auf `/archive/photos/`, `/cv/` auf `/about/`. Vorschaubilder kommen als `image/webp` mit `immutable`. Anmelden im Panel geht hinter dem Staging-Passwort.
- Seiten-Cache hinter dem Passwort: kalt 0,03 bis 0,83 s (der Feed am langsamsten), aus dem Cache 5 bis 7 ms. Nur ein Richtwert, der Server ist langsamer.
- `bin/warm.php`: 303 Seiten und 3.517 Vorschaubilder in 267 s, 0 Fehler. `media/` hat danach 251 MB. Mit den Originalen wären es 4,3 GB.
- `bin/check-urls.php` mit Passwort: 296 URLs, 539 Weiterleitungen, 0 Fehler. Ohne Passwort 1.374 Fehler, alle 401.
- `make kirby-lint` ok, 230 Tests grün, `make kirby-restore-test` mit 311 Seiten gleich.

**Entscheidungen in diesem Plan (Sven prüft sie beim Lesen):**
- **Server wie Repo:** `www/` und `vendor/` liegen auf dem Server so wie im Repo. Luisas Deploy lädt `www/` in den Ordner selbst, dieser nach `www/`.
- **Staging-Block:** bleibt in `www/.htaccess`, denn jede Anfrage landet dort. `.htpasswd` liegt neben `www/`.
- **`/.well-known/`:** `server/root.htaccess` reicht den Pfad durch, bevor alles nach `www/` geht. Dort gilt kein Passwort. Die `<If>`-Ausnahme aus der Notiz „Phase 5, Staging-Passwort" im Gesamtplan entfällt.
- **`url` fest:** Kirby läuft aus `www/index.php`. Ohne festes `url` in `env.php` nähme Kirby `/www` als Basis, und jeder Link begänne mit `/www/`. Der Deploy setzt `url` bei jedem Lauf.
- **Build:** aus dem Arbeitsordner, mit denselben Ausschlüssen wie der Upload (wie bei Luisa). Composer läuft mit `--no-dev` in einem Temp-Ordner, das lokale `vendor/` behält PHPUnit. Sven deployt darum aus einem committeten Stand von `kirby`.
- **Snapshots:** in `backups/` im Ordner der Seite, außerhalb von `www/`. Behalten werden die neuesten 30, wie in Luisas #49: nach Anzahl, die Namen sortieren nach Zeit.
- **Erster Stand als Snapshot-Ordner:** `bin/upload-state.sh` lädt einen lokalen Snapshot hoch und spielt ihn ein. Er ist der erste Snapshot auf dem Server, jeder folgende verlinkt hart auf ihn. Ein Archiv bräuchte 4 GB mehr Platz.
- **Erstes Konto:** über Kirbys Installation im Panel. Der Controller setzt `panel.install` für ein paar Minuten auf `true`, Sven legt das Konto im Browser an, danach wieder `false`. Das Passwort sieht nur der Browser, über HTTPS und hinter dem Staging-Passwort. Ein eigenes Skript entfällt. Den Schlüssel `'panel.install'` mit Punkt liest Kirby, `A::get()` prüft ihn zuerst wörtlich (geprüft im Probelauf).
- **Grenzen von Web-PHP:** eine Datei `limits.php` nur für angemeldete Admins, nur für die Messung auf dem Server, nie in Git.
- **Vorwärmen:** nur Vorschaubilder. Originale kopiert Kirby beim ersten Abruf ohne Rechnen, zusammen sind sie 4,1 GB.
- **Deploy vom Mac:** `make kirby-deploy` hängt an `kirby-lint` und `kirby-test`, also laufen Lint und PHPUnit vor jedem Deploy. Den Restore-Test startet Sven nach Codeänderungen selbst, er kopiert die 4 GB des Seeds zweimal. Ein Workflow auf GitHub kommt in 5b.
- **Kein Schalter `public` (Entscheidung vom 2026-10-13, nach Aufgabe 39):** Er schaltete nur `robots.txt` auf `Disallow: /`. Auf Staging liegt die Datei ohnehin hinter dem Passwort, und ein vergessenes `true` beim Umschalten hätte Suchmaschinen ausgesperrt. `robots.txt` erlaubt jetzt immer alles und nennt die Sitemap, der Deploy setzt nur noch `url`. Die Codeblöcke der Aufgaben 31 und 32 zeigen den Stand davor. Auf dem Server bleibt der Schlüssel `public` in `env.php` stehen, Kirby liest ihn nicht mehr.
- **Keine Inhaltsmigrationen:** Das Repo hat keine, der Deploy hat darum keinen Schritt dafür.

---

## Vor dem Start (Sven)

1. **KAS, Subdomain `kirby.eisenschmidt.website`:** Document Root `/kirby.eisenschmidt.website/`, also der Ordner selbst wie bei Luisa. PHP 8.5. SSL mit Let's Encrypt an. Prüfen: `curl -sI https://kirby.eisenschmidt.website/ | head -1` zeigt `HTTP/2 200` (Platzhalter von KAS), ohne Fehler zum Zertifikat.
2. **KAS, Speicher:** mindestens 13 GB frei. Staging belegt 4,1 GB Inhalte und 4,1 GB für den ersten Snapshot, `media/` wächst bis 4,4 GB, sobald Besucher Originale öffnen. Hugo bleibt daneben mit 4,4 GB.
3. **Anthropic:** Luisas Schlüssel (Entscheidung vom 2026-10-13). Sven gibt ihn beim Deploy in die verdeckte Abfrage ein.
4. **Staging-Passwort:** im Passwort-Manager, für Browser und Terminal.

---

## Dateien

| Datei | Aufgabe |
|---|---|
| `bin/backup.sh` | `PHP` aus der Umgebung, `--no-accounts` (31) |
| `bin/set-env.php` | Kopie von Luisa: Schlüssel in `env.php` setzen (31) |
| `bin/clear-cache.php` | Seiten-Cache unter der Sperre leeren, Typ `BlogFileCache` (31) |
| `server/root.htaccess` | `.htaccess` im Ordner der Seite: alles nach `www/`, `/.well-known/` frei (31) |
| `bin/deploy.sh`, `bin/pull.sh` | Deploy und Zustand holen, nach Luisas Skripten (32) |
| `bin/upload-state.sh` | lokalen Stand auf den Server legen (33) |
| `bin/warm.php` | jede Seite und jedes Vorschaubild einmal abrufen, Fortschritt alle 100 Bilder (34) |
| `bin/staging-env.sh` | Staging-Passwort aus dem Schlüsselbund, API-Schlüssel aus der lokalen `env.php`, wenn die Umgebung sie nicht setzt (nach 38, Entscheidung vom 2026-10-13) |
| `bin/check-urls.php` | Staging-Passwort aus der Umgebung (34) |
| `Makefile` | `kirby-deploy`, `kirby-pull` (32), `kirby-upload-state` (33), `kirby-warm` und `SITE` für `kirby-check-urls` (34) |

## Auf dem Server

| Unter `/www/htdocs/<konto>/kirby.eisenschmidt.website/` | Herkunft |
|---|---|
| `.htaccess` | Deploy, aus `server/root.htaccess` |
| `.htpasswd` | Deploy, aus `STAGING_USER` und `STAGING_PASSWORD` |
| `www/` | Deploy. Der Zustand bleibt stehen: `content/`, `site/accounts/`, `site/config/env.php`, `.salt`, `.license`, `media/`, `site/cache/`, `site/sessions/`, `site/logs/` |
| `www/.htaccess` | Deploy, mit aktivem Staging-Block |
| `vendor/` | Deploy, aus `composer install --no-dev` |
| `storage/assistant/` | der Assistent, beim ersten Upload |
| `backups/website-<Zeit>/` | Snapshots von Deploy, Pull und Upload, die neuesten 30 |

---

### Aufgabe 31: Skripte für den Server

**Dateien:**
- Ändern: `bin/backup.sh`
- Neu: `bin/set-env.php` (Kopie)
- Neu: `bin/clear-cache.php`
- Neu: `server/root.htaccess`

Kein Test vorher: Kopien und Werkzeuge in `bin/`, die Schritte prüfen sie direkt (Regel aus Phase 3 und 4).

- [ ] **Schritt 1: `bin/backup.sh` ändern**

Notiz „Phase 5, PHP in backup.sh": Auf dem Server ist `php` 8.4, der Deploy gibt `PHP=php85` mit. `--no-accounts` lässt `site/accounts` weg, so kommt der erste Stand für einen Server ohne das lokale Dev-Konto.

Zeile 8 ersetzen durch:

```bash
# Usage: bin/backup.sh [--snapshot] [--no-accounts] [www-root] [output-dir]
```

Den Block von `# newest one.` bis `PATHS=(content site/accounts site/config/.salt site/config/.license)` ersetzen durch:

```bash
# newest one.
#
# --no-accounts leaves out site/accounts: the first state for a server, made
# locally, never carries the local dev login (bin/upload-state.sh).
#
# PHP: on the server the php of the ssh shell is 8.4, the deploy passes PHP=php85.
set -euo pipefail

SNAPSHOT=0
ACCOUNTS=1
while [ $# -gt 0 ]; do
  case "$1" in
    --snapshot) SNAPSHOT=1 ;;
    --no-accounts) ACCOUNTS=0 ;;
    *) break ;;
  esac
  shift
done
WWW="${1:-www}"
OUT="${2:-backups}"
PHP="${PHP:-php}"
PATHS=(content site/accounts site/config/.salt site/config/.license)
[ "$ACCOUNTS" = 1 ] || PATHS=(content site/config/.salt site/config/.license)
```

Die drei Aufrufe von `php` heißen danach so:

```bash
  echo "kirby: $("$PHP" -r 'echo json_decode(file_get_contents($argv[1]), true)["version"] ?? "unknown";' "$WWW/kirby/composer.json" 2>/dev/null || echo unknown)"
  echo "php: $("$PHP" -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
```

```bash
  until "$PHP" -r 'exit(@rename($argv[1], $argv[2]) ? 0 : 1);' "$NEW" "$OUT/website-$STAMP"; do
```

- [ ] **Schritt 2: Prüfen**

Run: `bash -n bin/backup.sh && grep -c '"\$PHP" -r' bin/backup.sh && ! grep -n '[( ]php -r' bin/backup.sh && echo "no bare php"`
Expected:
```
3
no bare php
```

`--no-accounts` prüft Aufgabe 33 an einem echten Snapshot.

- [ ] **Schritt 3: `bin/set-env.php` kopieren**

```bash
cp ../concertshotsbyluisa/bin/set-env.php bin/set-env.php
```

Die Datei bleibt unverändert. Sie setzt Schlüssel in `env.php`, die übrigen bleiben, `true` und `false` werden Boolesche Werte.

Run:
```bash
E=$(mktemp) && printf '<?php\n\nreturn [\n    %s => false,\n];\n' "'debug'" > "$E" && php bin/set-env.php "$E" public=false url=https://kirby.eisenschmidt.website panel.install=true && php -r 'var_export(require $argv[1]); echo "\n";' "$E"
```
Expected:
```
public: false
url: https://kirby.eisenschmidt.website
panel.install: true
array (
  'debug' => false,
  'public' => false,
  'url' => 'https://kirby.eisenschmidt.website',
  'panel.install' => true,
)
```

- [ ] **Schritt 4: `bin/clear-cache.php` anlegen**

```php
<?php

/**
 * Clears the page cache of every host name, under the same lock as the
 * site itself (plugins/blog/cache.php). rm -rf of site/cache would race
 * with a page that writes its cache entry at that moment.
 * Kirby keeps one page cache per host name: site/cache/<host>/pages.
 * From concertshotsbyluisa, with the cache type of this site and Kirby's
 * dependencies in vendor/ next to www/. The deploy runs it on the server
 * from stdin.
 *
 * Usage: php bin/clear-cache.php [www-root]
 */

$www = rtrim($argv[1] ?? 'www', '/');
if (!is_file($www . '/kirby/bootstrap.php')) {
    fwrite(STDERR, "Not a site root: $www\n");
    exit(1);
}
require dirname(realpath($www)) . '/vendor/autoload.php';
require $www . '/kirby/bootstrap.php';
require $www . '/site/plugins/blog/cache.php';

$cache = $www . '/site/cache';
$count = 0;
foreach (glob($cache . '/*/pages', GLOB_ONLYDIR) ?: [] as $dir) {
    $prefix = basename(dirname($dir)) . '/pages';
    (new BlogFileCache(['root' => $cache, 'prefix' => $prefix]))->flush();
    $count++;
}
echo "Page cache cleared ($count host" . ($count === 1 ? '' : 's') . ")\n";
```

Run: `ls -d www/site/cache/*/pages | wc -l; php bin/clear-cache.php www; php bin/clear-cache.php nowhere; echo "exit $?"`
Expected: zuerst eine Zahl N, dann `Page cache cleared (N hosts)` (bei N = 1: `(1 host)`), dann `Not a site root: nowhere` und `exit 1`.

- [ ] **Schritt 5: `server/root.htaccess` anlegen**

```apache
# .htaccess in the site folder on the server, next to www/ and vendor/
# (the deploy uploads it from server/root.htaccess). The document root in KAS
# is the site folder: every request goes into www/, so vendor/, storage/,
# backups/ and .htpasswd are not reachable from outside. www/.htaccess
# carries Kirby's rules and the staging password.

RewriteEngine On

# Let's Encrypt (HTTP-01) reads /.well-known/ from this folder, without the
# staging password of www/.htaccess.
RewriteRule ^\.well-known/ - [L]

RewriteRule ^(.*)$ www/$1 [L]
```

`www/.htaccess` hat eigene Regeln mit `RewriteEngine on`. Für Pfade unter `www/` gelten darum nur diese, die Regeln des Ordners darüber nicht. So entsteht keine Schleife.

- [ ] **Schritt 6: Alle Prüfungen**

Run: `make kirby-lint && make kirby-test && make kirby-restore-test`
Expected: `lint ok`, `OK (230 tests, …)`, `OK: 311 pages identical`.

- [ ] **Checkpoint:** Sven committet auf `kirby`: `git add bin/backup.sh bin/set-env.php bin/clear-cache.php server/root.htaccess` und `git commit -m "Phase 5a, Aufgabe 31: Skripte für den Server"`.

---

### Aufgabe 32: Deploy und Pull

**Dateien:**
- Neu: `bin/deploy.sh`
- Neu: `bin/pull.sh`
- Ändern: `Makefile` (Block am Ende)

Beide Skripte kommen aus Luisas Repo. Unterschiede: `www/` und `vendor/` gehen in Unterordner, `server/root.htaccess` in den Ordner selbst. Der Build läuft in einem Temp-Ordner mit `composer install --no-dev`. Snapshots liegen in `backups/` im Ordner der Seite. PHP auf dem Server ist `php85`. Ein leeres `DEPLOY_HOST` macht `DEPLOY_PATH` zu einem lokalen Ordner, so lassen sich beide Skripte ohne Server testen. Der Schritt für Inhaltsmigrationen entfällt, das Repo hat keine.

- [ ] **Schritt 1: `bin/deploy.sh` anlegen**

```bash
#!/usr/bin/env bash
# Deploys the code to the server, from concertshotsbyluisa's bin/deploy.sh.
# The server folder has the layout of the repository: www/ and vendor/ side
# by side, plus server/root.htaccess as its .htaccess (the document root in
# KAS is the folder itself). Touches no state.
#
# 1. Build: www/ without state in a temporary folder, composer install
#    --no-dev there (Kirby into www/kirby, the markdown-field plugin into
#    www/site/plugins, the rest into vendor/).
# 2. Snapshot of the live state on the server (if there is any), newest 30 kept.
# 3. rsync of www/ and vendor/, with --delete, but state, host config and
#    generated files are excluded, so rsync neither uploads nor deletes them.
# 4. Staging password: .htpasswd next to www/, the auth block in www/.htaccess.
# 5. Host config: env.php and, for a fresh site, .salt. 'public' is true only
#    without staging password; 'url' is SITE_URL; 'assistantKey' is
#    ANTHROPIC_API_KEY, if set.
# 6. Page cache cleared under the site's cache lock, then a request to the start page.
#
# Environment:
#   DEPLOY_PATH                       required, the site folder on the server
#   SITE_URL                          required, the canonical URL ('url' in env.php)
#   DEPLOY_HOST                       ssh target (alias or user@host); empty: DEPLOY_PATH
#                                     is a local folder standing in for the server (tests)
#   DEPLOY_SSH_KEY_FILE               optional, private key file for ssh
#   STAGING_USER, STAGING_PASSWORD    staging password
#   PUBLIC=1                          deploy without staging password (launch, phase 5b)
#   ANTHROPIC_API_KEY                 optional, key of the Panel assistant
#   REMOTE_PHP                        PHP on the server, default php85 (php in the ssh shell is 8.4)
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH missing}"
: "${SITE_URL:?SITE_URL missing}"
DEPLOY_PATH="${DEPLOY_PATH%/}"
SITE_URL="${SITE_URL%/}"
BACKUP_DIR="$DEPLOY_PATH/backups"
RPHP="${REMOTE_PHP:-php85}"

SSH_OPTS=(-o ConnectTimeout=30 -o ServerAliveInterval=30 -o BatchMode=yes)
[ -n "${DEPLOY_SSH_KEY_FILE:-}" ] && SSH_OPTS+=(-i "$DEPLOY_SSH_KEY_FILE")
if [ -n "${DEPLOY_HOST:-}" ]; then
  remote() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }
  DEST="$DEPLOY_HOST:$DEPLOY_PATH"
  RSYNC_SSH=(-e "ssh ${SSH_OPTS[*]}")
else
  remote() { bash -c "$*"; }
  DEST="$DEPLOY_PATH"
  RSYNC_SSH=()
fi

[ -f www/index.php ] && [ -f composer.lock ] && [ -f server/root.htaccess ] || { echo "Run from the repository root" >&2; exit 1; }

# Until launch the site must stay behind the staging password. Going public
# is a deliberate step: PUBLIC=1 (and a Kirby license).
if [ -z "${STAGING_USER:-}" ] || [ -z "${STAGING_PASSWORD:-}" ]; then
  [ "${PUBLIC:-0}" = "1" ] || { echo "STAGING_USER/STAGING_PASSWORD not set. Refusing to deploy a public site (set PUBLIC=1 at launch)." >&2; exit 1; }
fi

# State, host config and generated files: never in the build, never deleted on the server.
EXCLUDES=(
  --exclude '/content/' --exclude '/content.new/' --exclude '/media/'
  --exclude '/site/accounts/' --exclude '/site/cache/' --exclude '/site/sessions/' --exclude '/site/logs/'
  --exclude '/site/config/env.php' --exclude '/site/config/.salt' --exclude '/site/config/.license'
  --exclude '/.htpasswd' --exclude '.DS_Store'
)

BUILD="$(mktemp -d)"
HTACCESS="$(mktemp)"
trap 'rm -rf "$BUILD" "$HTACCESS"' EXIT

echo "== Build"
# Kirby and the markdown-field plugin come from composer.lock, not from the local copies.
rsync -a "${EXCLUDES[@]}" --exclude '/kirby/' --exclude '/site/plugins/markdown-field/' www/ "$BUILD/www/"
cp composer.json composer.lock "$BUILD/"
composer validate --no-check-publish --strict --working-dir="$BUILD"
composer install --no-dev --no-interaction --no-progress --optimize-autoloader --working-dir="$BUILD"
# The plugin is in .gitignore and must go up all the same: without it every
# save in the Panel empties excerpt and text.
for f in www/kirby/bootstrap.php www/site/plugins/markdown-field/index.php vendor/autoload.php; do
  [ -f "$BUILD/$f" ] || { echo "Build has no $f" >&2; exit 1; }
done
find "$BUILD/www/site" "$BUILD/www/index.php" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

echo "== Snapshot of the live state"
# Exit code 1 means "no live state yet"; anything else (255: ssh failed) stops the deploy.
set +e
remote "test -d '$DEPLOY_PATH/www/content' && test -d '$DEPLOY_PATH/www/kirby'"
state=$?
set -e
[ "$state" -le 1 ] || { echo "Cannot reach the server (ssh exit $state)" >&2; exit 1; }
if [ "$state" -eq 0 ]; then
  # A file the newest snapshot already holds is a hard link to it, and an
  # unchanged state makes no new snapshot (bin/backup.sh).
  remote "mkdir -p '$BACKUP_DIR' && cd '$BACKUP_DIR' && PHP=$RPHP bash -s -- --snapshot '$DEPLOY_PATH/www' '$BACKUP_DIR'" < bin/backup.sh
  # Keep the newest 30 snapshots (the names sort by time). A .website-tmp-*
  # folder older than a day is a snapshot or an upload that was killed
  # before it was complete.
  remote "cd '$BACKUP_DIR' && { ls -1d website-*/ 2>/dev/null | LC_ALL=C sort -r | tail -n +31 | xargs -r rm -rf; find . -maxdepth 1 -name '.website-tmp-*' -mmin +1440 -exec rm -rf {} +; }"
else
  echo "No live state yet, nothing to back up"
fi

echo "== Code"
remote "mkdir -p '$DEPLOY_PATH/www' '$DEPLOY_PATH/vendor'"
rsync -rlptz --delete ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$BUILD/vendor/" "$DEST/vendor/"
# www/.htaccess goes up on its own, after .htpasswd.
rsync -rlptz --delete "${EXCLUDES[@]}" --exclude '/.htaccess' ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$BUILD/www/" "$DEST/www/"

echo "== Staging password"
if [ -n "${STAGING_USER:-}" ] && [ -n "${STAGING_PASSWORD:-}" ]; then
  # The password travels on stdin, never on a command line. Apache checks it
  # on every request (page, CSS, font, each photo): bcrypt cost 5 takes about
  # 4 ms per check, the PHP default 12 took 150 ms.
  printf '%s' "$STAGING_PASSWORD" | remote "$RPHP -r '\$p = stream_get_contents(STDIN); file_put_contents(\$argv[1], \$argv[2] . \":\" . password_hash(\$p, PASSWORD_BCRYPT, [\"cost\" => 5]) . \"\n\");' '$DEPLOY_PATH/.htpasswd' '$STAGING_USER'"
  # All requests land in www/ (root .htaccess), so the block there covers the whole site.
  sed -e "s|^#AuthType Basic|AuthType Basic|" \
      -e "s|^#AuthName |AuthName |" \
      -e "s|^#AuthUserFile __HTPASSWD__|AuthUserFile $DEPLOY_PATH/.htpasswd|" \
      -e "s|^#Require valid-user|Require valid-user|" "$BUILD/www/.htaccess" > "$HTACCESS"
  grep -q "^AuthUserFile $DEPLOY_PATH/.htpasswd" "$HTACCESS" || { echo "Staging block not found in www/.htaccess" >&2; exit 1; }
else
  cp "$BUILD/www/.htaccess" "$HTACCESS"
fi
# mktemp creates 0600; Apache must be able to read the file.
chmod 644 "$HTACCESS"
rsync -tpz ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$HTACCESS" "$DEST/www/.htaccess"
rsync -tpz ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} server/root.htaccess "$DEST/.htaccess"
[ -n "${STAGING_USER:-}" ] && [ -n "${STAGING_PASSWORD:-}" ] || remote "rm -f '$DEPLOY_PATH/.htpasswd'"
# KAS puts a placeholder page into a new domain folder.
remote "rm -f '$DEPLOY_PATH/index.htm'"

echo "== Host config"
# A site with content but without .salt lost its salt: a new one would
# change every media URL. Restore .salt from a backup instead.
remote "cd '$DEPLOY_PATH/www/site/config' && { test -f env.php || printf '<?php\n\nreturn [\n    \x27debug\x27 => false,\n];\n' > env.php; } && { test -f .salt || { test ! -d ../../content || { echo 'content without .salt: restore .salt from a backup' >&2; exit 1; }; $RPHP -r 'echo bin2hex(random_bytes(24)), \"\n\";' > .salt; }; }"

# Search engines may index the site only without staging password. Kirby
# runs from www/index.php: without a fixed url it would take /www as its
# base, and every link would start with /www/.
if [ -n "${STAGING_USER:-}" ] && [ -n "${STAGING_PASSWORD:-}" ]; then PUBLIC_FLAG=false; else PUBLIC_FLAG=true; fi
remote "$RPHP -- '$DEPLOY_PATH/www/site/config/env.php' public=$PUBLIC_FLAG 'url=$SITE_URL'" < bin/set-env.php

# The assistant's key travels on stdin, never on a command line. Without it
# the assistant is off; an existing key stays.
if [ -n "${ANTHROPIC_API_KEY:-}" ]; then
  printf '%s' "$ANTHROPIC_API_KEY" | remote "$RPHP -r '\$k = trim(stream_get_contents(STDIN)); \$f = \$argv[1]; \$e = require \$f; if ((\$e[\"assistantKey\"] ?? null) === \$k) exit(0); \$e[\"assistantKey\"] = \$k; file_put_contents(\$f . \".tmp\", \"<?php\\n\\nreturn \" . var_export(\$e, true) . \";\\n\"); rename(\$f . \".tmp\", \$f); echo \"assistantKey: set\\n\";' '$DEPLOY_PATH/www/site/config/env.php'"
fi

echo "== Cache"
# Under the site's cache lock: rm -rf would race with a page that writes
# its cache entry at that moment (bin/clear-cache.php).
remote "$RPHP -- '$DEPLOY_PATH/www'" < bin/clear-cache.php

echo "== Check $SITE_URL"
AUTH=()
[ -n "${STAGING_USER:-}" ] && AUTH=(-u "$STAGING_USER:$STAGING_PASSWORD")
code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 60 ${AUTH[@]+"${AUTH[@]}"} "$SITE_URL/" || true)
echo "HTTP $code"
case "$code" in 200|404) echo "Deploy done" ;; *) echo "Unexpected status" >&2; exit 1 ;; esac
```

Run: `chmod +x bin/deploy.sh && bash -n bin/deploy.sh && echo ok`
Expected: `ok`

Zwei Stellen sind für die Bash 3.2 von macOS geschrieben: leere Listen stehen als `${LIST[@]+"${LIST[@]}"}`, sonst bricht `set -u` ab. `rsync` auf dem Mac ist openrsync, es kennt `--chmod=F644` nicht: `server/root.htaccess` geht mit `-p` und den Rechten aus Git hoch.

- [ ] **Schritt 2: `bin/pull.sh` anlegen**

```bash
#!/usr/bin/env bash
# Fetches the server's state, from concertshotsbyluisa's bin/pull.sh: a
# snapshot on the server (as the deploy makes it), downloaded as an archive
# to backups/ and restored into the local www/. Local content and accounts
# are replaced by the server's.
#
# Environment: DEPLOY_PATH (required), DEPLOY_HOST (ssh target; empty:
#              DEPLOY_PATH is local), DEPLOY_SSH_KEY_FILE, REMOTE_PHP (default php85)
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH missing}"
DEPLOY_PATH="${DEPLOY_PATH%/}"
BACKUP_DIR="$DEPLOY_PATH/backups"
RPHP="${REMOTE_PHP:-php85}"

SSH_OPTS=(-o ConnectTimeout=30 -o BatchMode=yes)
[ -n "${DEPLOY_SSH_KEY_FILE:-}" ] && SSH_OPTS+=(-i "$DEPLOY_SSH_KEY_FILE")
if [ -n "${DEPLOY_HOST:-}" ]; then
  remote() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }
else
  remote() { bash -c "$*"; }
fi

snapshot=$(remote "mkdir -p '$BACKUP_DIR' && cd '$BACKUP_DIR' && PHP=$RPHP bash -s -- --snapshot '$DEPLOY_PATH/www' '$BACKUP_DIR'" < bin/backup.sh | tail -1)
ARCHIVE="backups/$(basename "$snapshot").tar.gz"
mkdir -p backups
# The archive holds what bin/backup.sh would write; .part until it is complete.
remote "tar -C '$snapshot' -czf - state MANIFEST.sha256 META" > "$ARCHIVE.part"
mv "$ARCHIVE.part" "$ARCHIVE"
bin/restore.sh "$ARCHIVE" www
```

Run: `chmod +x bin/pull.sh && bash -n bin/pull.sh && echo ok`
Expected: `ok`

`make kirby-pull` läuft einmal in der Abnahme (Aufgabe 39) gegen Staging. Ein lokaler Test würde das Dev-Konto durch einen Zustand ohne Konten ersetzen.

- [ ] **Schritt 3: Makefile ergänzen**

Am Ende anhängen:

```make

# Phase 5a: Staging (Plan: docs/superpowers/plans/2026-10-13-kirby-phase-5a-staging.md).
# Host und Pfad sind nicht geheim, es gilt der SSH-Alias website.
# Staging-Passwort und API-Schlüssel kommen aus der Umgebung:
# STAGING_USER, STAGING_PASSWORD, ANTHROPIC_API_KEY. Lint und Tests laufen vorher.
KIRBY_DEPLOY_HOST ?= website
KIRBY_DEPLOY_PATH ?= /www/htdocs/<konto>/kirby.eisenschmidt.website
KIRBY_SITE_URL ?= https://kirby.eisenschmidt.website

.PHONY: kirby-deploy
kirby-deploy: kirby-lint kirby-test
	DEPLOY_HOST=$(KIRBY_DEPLOY_HOST) DEPLOY_PATH=$(KIRBY_DEPLOY_PATH) SITE_URL=$(KIRBY_SITE_URL) bin/deploy.sh

# Ersetzt Inhalte und Konten in www/ durch den Stand des Servers.
.PHONY: kirby-pull
kirby-pull:
	DEPLOY_HOST=$(KIRBY_DEPLOY_HOST) DEPLOY_PATH=$(KIRBY_DEPLOY_PATH) bin/pull.sh
```

- [ ] **Schritt 4: Deploy in einen lokalen Ordner**

Ein Ordner unter `$TMPDIR` steht für den Server, mit dem Platzhalter von KAS. `php -S` beantwortet die Prüfung am Ende. Es kennt keine `.htaccess`, das Passwort prüft erst Staging (Aufgabe 36).

Run:
```bash
T="$(mktemp -d)/site" && mkdir -p "$T/www" && echo placeholder > "$T/index.htm"
php -S localhost:8095 -t "$T/www" bin/dev-router.php >/dev/null 2>&1 &
DEPLOY_HOST= DEPLOY_PATH="$T" SITE_URL=http://localhost:8095 STAGING_USER=probe STAGING_PASSWORD=probe-password ANTHROPIC_API_KEY=probe-key REMOTE_PHP=php bin/deploy.sh 2>&1 | grep -E '^(==|No live|public|url|assistantKey|Page cache|HTTP|Deploy|\./composer)'
```
Expected:
```
== Build
./composer.json is valid
== Snapshot of the live state
No live state yet, nothing to back up
== Code
== Staging password
== Host config
public: false
url: http://localhost:8095
assistantKey: set
== Cache
Page cache cleared (0 hosts)
== Check http://localhost:8095
HTTP 404
Deploy done
```

Run:
```bash
ls -A "$T"; test ! -e "$T/index.htm" && echo "placeholder gone"
grep -c -E '^(AuthType Basic|Require valid-user)$' "$T/www/.htaccess"
grep -q "^AuthUserFile $T/.htpasswd$" "$T/www/.htaccess" && echo "AuthUserFile ok"
test -f "$T/www/site/plugins/markdown-field/index.php" && echo "markdown-field ok"
test ! -e "$T/vendor/phpunit" && echo "no dev packages"
test ! -e "$T/www/site/accounts" && test ! -e "$T/www/content/site.txt" && echo "no local state"
cut -d: -f1 "$T/.htpasswd"
php -r '$e = require $argv[1]; echo implode(" ", array_keys($e)), "\n";' "$T/www/site/config/env.php"
```
Expected:
```
.htaccess
.htpasswd
vendor
www
placeholder gone
2
AuthUserFile ok
markdown-field ok
no dev packages
no local state
probe
debug public url assistantKey
```

- [ ] **Schritt 5: Ohne Passwort bricht der Deploy ab**

Run: `DEPLOY_HOST= DEPLOY_PATH="$T" SITE_URL=http://localhost:8095 REMOTE_PHP=php bin/deploy.sh; echo "exit $?"`
Expected:
```
STAGING_USER/STAGING_PASSWORD not set. Refusing to deploy a public site (set PUBLIC=1 at launch).
exit 1
```

Danach den Server beenden: `kill %1` (oder `pkill -f "php -S localhost:8095"`). Der Ordner `$T` ist 14 MB groß, macOS räumt `$TMPDIR` selbst auf.

- [ ] **Schritt 6: Alle Prüfungen**

Run: `make kirby-lint && make kirby-test && make kirby-restore-test`
Expected: `lint ok`, `OK (230 tests, …)`, `OK: 311 pages identical`.

- [ ] **Checkpoint:** Sven committet auf `kirby`: `git add bin/deploy.sh bin/pull.sh Makefile` und `git commit -m "Phase 5a, Aufgabe 32: Deploy und Pull"`.

---

### Aufgabe 33: Lokalen Stand auf den Server legen

**Dateien:**
- Neu: `bin/upload-state.sh`
- Ändern: `Makefile` (an den Block aus Aufgabe 32 anhängen)

Für den ersten Stand von Staging und für den letzten Import vor dem Umschalten (5b). Das Skript ersetzt die Inhalte auf dem Server. Einen Snapshot mit `site/accounts` lehnt es ab. `bin/restore.sh` leert dabei `site/accounts` auf dem Server: ein späterer Upload braucht danach das Konto neu (Aufgabe 37, Schritt 9).

- [ ] **Schritt 1: `bin/upload-state.sh` anlegen**

```bash
#!/usr/bin/env bash
# Puts a local state on the server: the first state of staging, and the
# last import before the switch (phase 5b). Replaces the content on the
# server, never for daily work.
#
# 1. Snapshot of the server's state, if there is any (as the deploy makes it).
# 2. rsync of the local snapshot folder into the server's backups/, under a
#    temporary name until it is complete, then named with the current time:
#    it is the newest snapshot, the next one hard-links to it.
# 3. bin/restore.sh on the server: checksums first, then the state is replaced.
#
# Usage: bin/upload-state.sh <snapshot-folder from bin/backup.sh --snapshot --no-accounts>
# Environment: DEPLOY_PATH (required), DEPLOY_HOST (ssh target; empty:
#              DEPLOY_PATH is local), REMOTE_PHP (default php85)
set -euo pipefail

SNAP="${1:?Usage: bin/upload-state.sh <snapshot-folder>}"
SNAP="${SNAP%/}"
: "${DEPLOY_PATH:?DEPLOY_PATH missing}"
DEPLOY_PATH="${DEPLOY_PATH%/}"
BACKUP_DIR="$DEPLOY_PATH/backups"
RPHP="${REMOTE_PHP:-php85}"

[ -f "$SNAP/MANIFEST.sha256" ] && [ -d "$SNAP/state/content" ] || { echo "Not a snapshot folder: $SNAP" >&2; exit 1; }
# The local dev login never goes to a server.
[ ! -e "$SNAP/state/site/accounts" ] || { echo "$SNAP holds site/accounts. Make it with bin/backup.sh --snapshot --no-accounts." >&2; exit 1; }

SSH_OPTS=(-o ConnectTimeout=30 -o ServerAliveInterval=30 -o BatchMode=yes)
if [ -n "${DEPLOY_HOST:-}" ]; then
  remote() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }
  DEST="$DEPLOY_HOST:$BACKUP_DIR"
  RSYNC_SSH=(-e "ssh ${SSH_OPTS[*]}")
else
  remote() { bash -c "$*"; }
  DEST="$BACKUP_DIR"
  RSYNC_SSH=()
fi

remote "test -d '$DEPLOY_PATH/www/kirby'" || { echo "No code on the server: make kirby-deploy first" >&2; exit 1; }
if remote "test -d '$DEPLOY_PATH/www/content'"; then
  echo "== Snapshot of the server's state"
  remote "mkdir -p '$BACKUP_DIR' && cd '$BACKUP_DIR' && PHP=$RPHP bash -s -- --snapshot '$DEPLOY_PATH/www' '$BACKUP_DIR'" < bin/backup.sh
fi

echo "== Upload $SNAP"
remote "mkdir -p '$BACKUP_DIR'"
# -a keeps mtimes and modes, Kirby uses mtimes in media URLs. --partial: a
# second run continues a broken upload.
rsync -a --partial --stats ${RSYNC_SSH[@]+"${RSYNC_SSH[@]}"} "$SNAP/" "$DEST/.website-tmp-upload/"
NAME="website-$(date -u +%Y%m%dT%H%M%SZ)"
remote "cd '$BACKUP_DIR' && test ! -e '$NAME' && mv .website-tmp-upload '$NAME'"

echo "== Restore $NAME"
remote "bash -s -- '$BACKUP_DIR/$NAME' '$DEPLOY_PATH/www'" < bin/restore.sh
```

Run: `chmod +x bin/upload-state.sh && bash -n bin/upload-state.sh && echo ok`
Expected: `ok`

- [ ] **Schritt 2: Makefile ergänzen**

An den Block aus Aufgabe 32 anhängen:

```make

# Ersetzt die Inhalte auf dem Server durch einen lokalen Snapshot ohne Konten.
.PHONY: kirby-upload-state
kirby-upload-state:
	@test -n "$(SNAPSHOT)" || { echo "Aufruf: make kirby-upload-state SNAPSHOT=backups/website-..."; exit 1; }
	DEPLOY_HOST=$(KIRBY_DEPLOY_HOST) DEPLOY_PATH=$(KIRBY_DEPLOY_PATH) bin/upload-state.sh $(SNAPSHOT)
```

- [ ] **Schritt 3: Ein Snapshot mit Konten wird abgelehnt**

Run:
```bash
F=$(mktemp -d) && mkdir -p "$F/state/content" "$F/state/site/accounts" && touch "$F/MANIFEST.sha256" && DEPLOY_PATH="$F/server" bin/upload-state.sh "$F"; echo "exit $?"
```
Expected: `<F> holds site/accounts. Make it with bin/backup.sh --snapshot --no-accounts.` und `exit 1`.

- [ ] **Schritt 4: Upload in einen lokalen Ordner**

Braucht rund 12 GB unter `$TMPDIR` (Snapshot, Upload, Restore der lokalen Inhalte) und eine halbe Minute.

Run:
```bash
T="$(mktemp -d)/site" && mkdir -p "$T/www"
php -S localhost:8095 -t "$T/www" bin/dev-router.php >/dev/null 2>&1 &
DEPLOY_HOST= DEPLOY_PATH="$T" SITE_URL=http://localhost:8095 STAGING_USER=probe STAGING_PASSWORD=probe-password REMOTE_PHP=php bin/deploy.sh >/dev/null 2>&1; echo "deploy exit $?"
SNAP=$(bin/backup.sh --snapshot --no-accounts www "$T.local" | tail -1) && ls -A "$SNAP/state/site"
```
Expected: `deploy exit 0` und `config` (kein `accounts`).

Run: `DEPLOY_HOST= DEPLOY_PATH="$T" REMOTE_PHP=php bin/upload-state.sh "$SNAP" | grep -E '^(==|Restored|Total file size)'`
Expected (Größe und Zahl hängen vom lokalen Stand ab, im Probelauf 4389298677 B und 1798 Dateien):
```
== Upload <T>.local/website-<Zeit>
Total file size: 4389298677 B
== Restore website-<Zeit>
Restored 1798 files from <T>/backups/website-<Zeit>
```

Run: `DEPLOY_HOST= DEPLOY_PATH="$T" SITE_URL=http://localhost:8095 STAGING_USER=probe STAGING_PASSWORD=probe-password REMOTE_PHP=php bin/deploy.sh 2>&1 | grep -E '^(/|State|HTTP|Deploy)'`
Expected:
```
State unchanged since website-<Zeit>, no new snapshot
<T>/backups/website-<Zeit>
HTTP 200
Deploy done
```

Der Snapshot des Deploys erkennt den hochgeladenen Stand als gleich: Prüfsummen, Zeiten und Rechte stimmen, jede Datei wäre ein harter Link.

Danach `kill %1` und den Ordner ausgeben: `dirname "$T"`. **(Sven)** löscht diesen Ordner und `"$T.local"` (zusammen rund 12 GB).

- [ ] **Schritt 5: Alle Prüfungen**

Run: `make kirby-lint && make kirby-test && make kirby-restore-test`
Expected: `lint ok`, `OK (230 tests, …)`, `OK: 311 pages identical`.

- [ ] **Checkpoint:** Sven committet auf `kirby`: `git add bin/upload-state.sh Makefile` und `git commit -m "Phase 5a, Aufgabe 33: Lokalen Stand auf den Server legen"`.

---

### Aufgabe 34: Vorwärmen und URL-Prüfung mit Passwort

**Dateien:**
- Neu: `bin/warm.php`
- Ändern: `bin/check-urls.php` (Kopfkommentar, `check_get()`)
- Ändern: `Makefile` (`kirby-check-urls`, Block aus Aufgabe 32)

Kirby rechnet Vorschaubilder beim ersten Abruf (Tabelle „Rückbau", Zeile Bild-Cache). `bin/warm.php` ruft jede Seite einmal ab und danach jedes Vorschaubild, das die Seiten nennen. Das Passwort lesen beide Skripte aus der Umgebung, nie von der Kommandozeile.

- [ ] **Schritt 1: `bin/warm.php` anlegen**

```php
<?php

/**
 * Requests every page of a site once, then every thumbnail under /media/
 * that the pages name (src, srcset, href, content), so Kirby renders each
 * thumbnail before the first visitor asks for it. Originals stay out: Kirby
 * only copies them on their first request, and together they are 4 GB.
 * Pages come from a crawl from the start page: links on the same host,
 * without query and without a file extension. Four requests at a time. The
 * user agent says "bot", so "Besucher" does not count the requests.
 * The staging password comes from STAGING_USER and STAGING_PASSWORD in the
 * environment, never from the command line.
 *
 * Usage: php bin/warm.php <site-url>
 */

$site = rtrim($argv[1] ?? '', '/');
if (!preg_match('!^https?://[^/]+$!', $site)) {
    fwrite(STDERR, "Usage: php bin/warm.php <site-url>\n");
    exit(1);
}
$headers = ['User-Agent: website-warm (bot)'];
if (getenv('STAGING_USER') && getenv('STAGING_PASSWORD')) {
    $headers[] = 'Authorization: Basic ' . base64_encode(getenv('STAGING_USER') . ':' . getenv('STAGING_PASSWORD'));
}

/** GETs the URLs, four at a time, and calls $done(url, status, body) for each. */
function warm_get(array $urls, array $headers, Closure $done): void
{
    $multi = curl_multi_init();
    $start = function (string $url) use ($multi, $headers): void {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_PRIVATE => $url,
        ]);
        curl_multi_add_handle($multi, $handle);
    };
    $queue = array_values($urls);
    $active = 0;
    while ($queue !== [] || $active > 0) {
        while ($active < 4 && $queue !== []) {
            $start(array_shift($queue));
            $active++;
        }
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 1.0);
        while ($info = curl_multi_info_read($multi)) {
            $handle = $info['handle'];
            $done((string)curl_getinfo($handle, CURLINFO_PRIVATE), (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE), (string)curl_multi_getcontent($handle));
            curl_multi_remove_handle($multi, $handle);
            $active--;
        }
    }
    curl_multi_close($multi);
}

/** The paths on this site that the HTML names: [pages, thumbnails]. */
function warm_links(string $html, string $site): array
{
    preg_match_all('!(?:href|src|content)="([^"]+)"!', $html, $single);
    preg_match_all('!srcset="([^"]+)"!', $html, $sets);
    $urls = $single[1];
    foreach ($sets[1] as $set) {
        foreach (explode(',', $set) as $candidate) {
            $urls[] = strtok(trim($candidate), ' ');
        }
    }
    $pages = $media = [];
    foreach ($urls as $url) {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
        if (str_starts_with($url, $site . '/')) {
            $url = substr($url, strlen($site));
        }
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            continue;
        }
        $path = strtok($url, '#');
        if (str_starts_with($path, '/media/')) {
            // A thumbnail's name ends in its size: photo-600x-q80.webp, photo-480x480-crop-q80.webp.
            if (preg_match('!-\d+x\d*(-[a-z0-9-]+)?\.[a-z]+$!i', $path)) {
                $media[$path] = true;
            }
        } elseif (!str_contains($path, '?') && !preg_match('!^/(panel|api)(/|$)|\.[a-z0-9]+$!i', $path)) {
            $pages[$path] = true;
        }
    }
    return [array_keys($pages), array_keys($media)];
}

$started = microtime(true);
$seen = ['/' => true];
$media = [];
// Links in old posts may lead to a redirect or to nothing (404): noted, no failure.
$other = [];
$failed = [];
$level = ['/'];
while ($level !== []) {
    $next = [];
    warm_get(array_map(fn ($path) => $site . $path, $level), $headers, function (string $url, int $status, string $body) use ($site, &$seen, &$media, &$other, &$failed, &$next): void {
        if ($status !== 200) {
            if ($status >= 300 && $status < 500) {
                $other[] = "$status $url";
            } else {
                $failed[] = "$status $url";
            }
            return;
        }
        [$pages, $files] = warm_links($body, $site);
        foreach ($pages as $path) {
            if (!isset($seen[$path])) {
                $seen[$path] = true;
                $next[] = $path;
            }
        }
        foreach ($files as $path) {
            $media[$path] = true;
        }
    });
    $level = $next;
}
printf("%d pages in %.0f s, %d links to a redirect or 404\n", count($seen) - count($other), microtime(true) - $started, count($other));
foreach (array_slice($other, 0, 20) as $line) {
    echo "  $line\n";
}

$started = microtime(true);
warm_get(array_map(fn ($path) => $site . $path, array_keys($media)), $headers, function (string $url, int $status) use (&$failed): void {
    if ($status !== 200) {
        $failed[] = "$status $url";
    }
});
printf("%d thumbnails in %.0f s, %d failures\n", count($media), microtime(true) - $started, count($failed));
foreach (array_slice($failed, 0, 20) as $line) {
    echo "  $line\n";
}
exit($failed === [] ? 0 : 1);
```

`curl_close()` fehlt mit Absicht: Es ist seit PHP 8.0 ohne Wirkung und in PHP 8.5 veraltet.

- [ ] **Schritt 2: `bin/check-urls.php`: Passwort aus der Umgebung**

Im Kopfkommentar die Zeile ` * Runs against the local server (make kirby-dev).` ersetzen durch:

```php
 * Runs against the local server (make kirby-dev) or staging; the staging
 * password comes from STAGING_USER and STAGING_PASSWORD in the environment.
```

In `check_get()` die Zeile mit `stream_context_create` ersetzen durch:

```php
    $auth = getenv('STAGING_USER') && getenv('STAGING_PASSWORD')
        ? 'Authorization: Basic ' . base64_encode(getenv('STAGING_USER') . ':' . getenv('STAGING_PASSWORD'))
        : '';
    $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 60, 'header' => $auth]]);
```

Die Sitemap kommt weiter von der Live-Seite ohne Passwort, die Weiterleitungen aus dem lokalen `www/content/.redirects.json`. Gegen Staging stimmt das, solange lokal derselbe Import liegt wie auf dem Server (Aufgabe 37).

- [ ] **Schritt 3: Makefile anpassen**

Den Block von `kirby-check-urls` ersetzen durch:

```make
# Phase 4: die URLs der Live-Seite gegen Kirby (bin/check-urls.php). Läuft
# gegen make kirby-dev in einem zweiten Terminal. Gegen Staging:
# make kirby-check-urls SITE=$(KIRBY_SITE_URL), das Passwort aus STAGING_USER
# und STAGING_PASSWORD.
SITE ?= http://localhost:$(KIRBY_PORT)
.PHONY: kirby-check-urls
kirby-check-urls:
	@$(PHP) bin/check-urls.php $(SITE)
```

An den Block aus Aufgabe 32 anhängen:

```make

# Ruft jede Seite und jedes Bild unter /media/ einmal ab (bin/warm.php).
.PHONY: kirby-warm
kirby-warm:
	@$(PHP) bin/warm.php $(KIRBY_SITE_URL)
```

- [ ] **Schritt 4: Lokal prüfen**

In einem zweiten Terminal `make kirby-dev`. Dann:

Run: `php bin/warm.php; echo "exit $?"`
Expected: `Usage: php bin/warm.php <site-url>` und `exit 1`.

Run: `php bin/warm.php http://localhost:8010`
Expected (Zahlen aus dem Probelauf, ein neuer Beitrag ändert sie):
```
303 pages in <n> s, 7 links to a redirect or 404
  301 http://localhost:8010/log/
  …
3517 thumbnails in <n> s, 0 failures
```
Die 7 Links stehen so in alten Beiträgen (`/log/…`, `/posts`, `/topics`, `/about` ohne Schrägstrich), sie sind schon bei Hugo tot oder leiten weiter. Lokal dauert der erste Lauf rund 5 Minuten, ein zweiter ein paar Sekunden. `www/media/` wächst um rund 250 MB, es ist erzeugt und nicht in Git.

Run: `make kirby-check-urls`
Expected: `296 URLs from the sitemap, 539 redirects with their targets, 0 failures` (Zahlen wie in Phase 4).

- [ ] **Schritt 5: Alle Prüfungen**

Run: `make kirby-lint && make kirby-test && make kirby-restore-test`
Expected: `lint ok`, `OK (230 tests, …)`, `OK: 311 pages identical`.

- [ ] **Checkpoint:** Sven committet auf `kirby`: `git add bin/warm.php bin/check-urls.php Makefile` und `git commit -m "Phase 5a, Aufgabe 34: Vorwärmen, URL-Prüfung mit Passwort"`.

---

### Aufgabe 35: entfällt

Sven deployt in 5a vom Mac mit `make kirby-deploy` (Entscheidung vom 2026-10-13). Ein Workflow auf GitHub bräuchte die Datei auf `main`, eine Umgebung mit Secrets und PHP 8.5 auf dem Runner. Er kommt in 5b mit dem Merge in `main`, siehe „Was Phase 5b wissen muss".

---

### Aufgabe 36: Erster Deploy

- [ ] **Schritt 1 (Sven): „Vor dem Start" ist erledigt**, Punkte 1 bis 4.

- [ ] **Schritt 2 (SSH): Server ansehen**

Run: `ssh website 'php85 -v | head -1; command -v rsync; ls -A /www/htdocs/<konto>/kirby.eisenschmidt.website/'`
Expected: `PHP 8.5.… (cli) …`, ein Pfad zu `rsync`, dann `index.htm` und `www` (leer). Liegt dort mehr, hält der Controller an und fragt Sven.

- [ ] **Schritt 3 (Svens Terminal): Deploy**

Run: `time make kirby-deploy`
Expected: `lint ok` und `OK (230 tests, …)`, danach diese Zeilen:
```
./composer.json is valid
No live state yet, nothing to back up
public: false
url: https://kirby.eisenschmidt.website
assistantKey: set
Page cache cleared (0 hosts)
HTTP 404
Deploy done
```
Die Dauer kommt in die Messwerte.

- [ ] **Schritt 4 (Svens Terminal): Passwort und `/.well-known/`**

Run:
```bash
S=https://kirby.eisenschmidt.website
curl -s -o /dev/null -w '%{http_code} ohne Passwort\n' "$S/"
curl -s -o /dev/null -w '%{http_code} .well-known ohne Passwort\n' "$S/.well-known/acme-challenge/probe"
curl -s -o /dev/null -u "$STAGING_USER:$STAGING_PASSWORD" -w '%{http_code} mit Passwort\n' "$S/"
```
Expected:
```
401 ohne Passwort
404 .well-known ohne Passwort
404 mit Passwort
```
404 mit Passwort: Kirby läuft, Inhalte gibt es noch keine.

- [ ] **Schritt 5 (SSH): Ordner und Host-Config**

Run: `ssh website 'cd /www/htdocs/<konto>/kirby.eisenschmidt.website && ls -A && ls www/site/plugins && php85 -r "echo implode(\" \", array_keys(require \$argv[1])), \"\n\";" www/site/config/env.php'`
Expected: `.htaccess`, `.htpasswd`, `vendor`, `www` (dazu `storage` erst nach dem ersten Upload im Assistenten), dann `analytics assistant blog changelog markdown-field`, dann `debug public url assistantKey`.

---

### Aufgabe 37: Erster Stand auf Staging

- [ ] **Schritt 1 (Sven): Neue Beiträge von `main`**

```bash
git switch kirby
git merge main
```
Danach Run: `make kirby-lint && make kirby-test`
Expected: `lint ok`, `OK (230 tests, …)`.

- [ ] **Schritt 2: Import**

Run: `make kirby-seed FORCE=1`
Expected: `Imported <n> posts (… photo, … trip, … note, … essay), 2 drafts, <n> files, <n> redirects.` Im Probelauf: 297 Beiträge, 742 Dateien, 539 Weiterleitungen, mit neuen Beiträgen von `main` mehr.

Steht dort `Kept from the old site.txt: assistantPrompt, …`, liegt der Prompt in der lokalen `content/site.txt` und kommt mit dem Stand auf den Server. Er bleibt dabei Zustand, Git sieht ihn nie.

- [ ] **Schritt 3 (SSH, nur lesen): Kopie von Pops Datenbank**

```bash
POP=$(mktemp -d)
ssh website 'cd /www/htdocs/<konto>/pop.eisenschmidt.website/var && tar -cf - data.db*' | tar -x -C "$POP"
ls "$POP"
```
Expected: `data.db`, dazu eventuell `data.db-wal` und `data.db-shm`. Auf dem Server ändert sich nichts.

- [ ] **Schritt 4: Pop-Import**

Run: `php bin/pop-import.php "$POP/data.db"`
Expected: `<n> visits read, <n> days written, 0 days kept (Kirby had them)`, danach die meistbesuchten URLs ohne Seite. Die Zahlen kommen in die Messwerte. Meldet SQLite `database disk image is malformed`, hat Pop während der Kopie geschrieben: Schritt 3 wiederholen.

- [ ] **Schritt 5: Kopie löschen**

Die Kopie enthält Fingerprints.

Run: `rm -f "$POP"/data.db* && rmdir "$POP" && echo "Pop-Kopie gelöscht"`
Expected: `Pop-Kopie gelöscht`

- [ ] **Schritt 6: Snapshot ohne Konten**

Run: `SNAP=$(bin/backup.sh --snapshot --no-accounts www backups | tail -1) && echo "$SNAP" && ls -A "$SNAP/state/site" && grep files: "$SNAP/META"`
Expected: `backups/website-<Zeit>`, `config`, `files: <n>` (rund 1.810: 1.798 aus dem Import, die Monate aus Pop, neue Beiträge).

- [ ] **Schritt 7 (SSH): Hochladen und einspielen**

Run: `time make kirby-upload-state SNAPSHOT="$SNAP"`
Expected:
```
== Snapshot of the server's state            (nur, wenn Kirby beim Test in Aufgabe 36 content/ angelegt hat)
== Upload backups/website-<Zeit>
… Total file size: rund 4,4 GB …
== Restore website-<Zeit>
Restored <n> files from /www/htdocs/<konto>/kirby.eisenschmidt.website/backups/website-<Zeit>
```
Die Dauer hängt an Svens Upload, sie kommt in die Messwerte. Reißt die Verbindung ab, denselben Befehl noch einmal: rsync macht mit den fertigen Dateien weiter.

- [ ] **Schritt 8 (Svens Terminal): Startseite**

Run: `curl -s -o /dev/null -u "$STAGING_USER:$STAGING_PASSWORD" -w '%{http_code}\n' https://kirby.eisenschmidt.website/`
Expected: `200`

- [ ] **Schritt 9: Svens Konto**

`panel.install` ist aus, und das Dev-Konto unter `.test` kam nie hoch. Die Installation im Panel legt das erste Konto an, nur solange der Schalter an ist.

a. **(SSH)** Run: `ssh website 'php85 -- /www/htdocs/<konto>/kirby.eisenschmidt.website/www/site/config/env.php panel.install=true' < bin/set-env.php`
Expected: `panel.install: true`

b. **(Sven)** https://kirby.eisenschmidt.website/panel öffnen, Staging-Passwort eingeben. Kirby zeigt „Panel installieren". E-Mail (keine Adresse unter `.test`), Name, Passwort aus dem Passwort-Manager, Sprache Deutsch. Das Panel öffnet sich angemeldet.

c. **(SSH)** Run: `ssh website 'php85 -- /www/htdocs/<konto>/kirby.eisenschmidt.website/www/site/config/env.php panel.install=false' < bin/set-env.php`
Expected: `panel.install: false`

d. **(Sven)** Abmelden und wieder anmelden.

- [ ] **Schritt 10 (Sven): Assistent einrichten**

Website › Assistent: Der Schlüssel ist vorhanden, der Knopf zur Prüfung meldet eine Antwort von Claude. Ist das Feld Prompt leer, fügt Sven ihn selbst ein. Das ist Svens Schritt außerhalb dieses Plans. Modell: Claude Opus 5.5.

- [ ] **Schritt 11 (Svens Terminal, dann SSH): Deploy mit Zustand**

Sven startet `time make kirby-deploy` wie in Aufgabe 36. In der Ausgabe steht unter `== Snapshot of the live state` ein neuer Pfad `…/backups/website-<Zeit>`, denn das Konto ist neu.

Run: `ssh website 'cd /www/htdocs/<konto>/kirby.eisenschmidt.website/backups && du -sh website-*'`
Expected: der hochgeladene Snapshot rund 4,1 GB, der neue ein paar hundert KB. `du` zählt harte Links nur einmal. Die Dauer des Deploys mit 4 GB Zustand kommt in die Messwerte.

---

### Aufgabe 38: Vorschaubilder vorwärmen

- [ ] **Schritt 1 (Svens Terminal): Alle Seiten und Vorschaubilder abrufen**

Run: `time make kirby-warm`
Expected: `<rund 300> pages in <n> s, 7 links to a redirect or 404` mit den 7 Zeilen aus Aufgabe 34, dann `<rund 3500> thumbnails in <n> s, 0 failures`. Abbrechen und neu starten geht jederzeit, fertige Bilder kommen dann sofort. Die Dauer kommt in die Messwerte.

- [ ] **Schritt 2 (SSH): Platz auf dem Server**

Run: `ssh website 'cd /www/htdocs/<konto>/kirby.eisenschmidt.website && du -sh www/content www/media backups vendor'`
Expected: `www/content` rund 4,1 GB, `www/media` rund 250 MB, `backups` rund 4,1 GB, `vendor` ein paar MB. Werte in die Messwerte.

---

### Aufgabe 39: Abnahme auf Staging

Jede Messung kommt in die Tabelle „Messwerte" am Ende dieses Plans. Weicht ein Wert von „Expected" ab, hält der Controller an und fragt Sven.

- [ ] **Schritt 1 (Svens Terminal): Gesperrte Pfade**

Run:
```bash
S=https://kirby.eisenschmidt.website
for p in /vendor/autoload.php /storage/assistant/.htaccess /www/content/site.txt /content/site.txt /www/site/config/env.php /site/config/env.php /backups/ /.htpasswd /www/.htaccess /www/index.php; do
  printf '%s %s\n' "$(curl -s -o /dev/null -u "$STAGING_USER:$STAGING_PASSWORD" -w '%{http_code}' "$S$p")" "$p"
done
```
Expected: `404` bei jedem Pfad, bei `/.htpasswd` und `/www/.htaccess` `403` oder `404`. Nie `200`.

- [ ] **Schritt 2 (Svens Terminal): Seiten, Weiterleitungen, Bilder, kein `/www/`**

Run:
```bash
for p in / /2026-10-09-green-woodpecker/ /2026-10-09-green-woodpecker /photos/ /cv/ /archive/ '/search/?q=woodpecker' /index.xml /sitemap.xml /robots.txt /llms.txt /gibt-es-nicht/; do
  printf '%s %s\n' "$(curl -s -o /dev/null -u "$STAGING_USER:$STAGING_PASSWORD" -w '%{http_code} %{redirect_url}' "$S$p")" "$p"
done
```
Expected:
```
200  /
200  /2026-10-09-green-woodpecker/
301 https://kirby.eisenschmidt.website/2026-10-09-green-woodpecker/ /2026-10-09-green-woodpecker
301 https://kirby.eisenschmidt.website/archive/photos/ /photos/
301 https://kirby.eisenschmidt.website/about/ /cv/
200  /archive/
200  /search/?q=woodpecker
200  /index.xml
200  /sitemap.xml
200  /robots.txt
200  /llms.txt
404  /gibt-es-nicht/
```

Run:
```bash
for p in / /2026-10-09-green-woodpecker/ /sitemap.xml /index.xml /llms.txt; do
  printf '%s %s\n' "$(curl -s -u "$STAGING_USER:$STAGING_PASSWORD" "$S$p" | grep -cE 'eisenschmidt\.website/www/|="/www/')" "$p"
done
curl -s -u "$STAGING_USER:$STAGING_PASSWORD" "$S/robots.txt"
IMG=$(curl -s -u "$STAGING_USER:$STAGING_PASSWORD" "$S/" | grep -o 'src="[^"]*/media/[^"]*"' | head -1 | cut -d'"' -f2)
curl -s -o /dev/null -u "$STAGING_USER:$STAGING_PASSWORD" -w '%{http_code} %{content_type}\n' "$IMG"
```
Expected: `0` vor jedem der fünf Pfade. `robots.txt` erlaubt alles und nennt die Sitemap, wie bei Hugo. Das Bild: `200 image/webp`.

Das Muster sucht nur Links: Die Notiz `building-with-hugo-pt2` nennt `/www/htdocs` im Text, das zählt nicht.

- [ ] **Schritt 3 (Sven): Panel**

Im Panel einen Beitrag öffnen, „Öffnen" führt zu `https://kirby.eisenschmidt.website/<datum>-<slug>/`, ohne `/www/`. Eine Vorschau im Panel zeigt Bilder.

- [ ] **Schritt 4 (SSH und Svens Terminal): Geschwindigkeit**

Erst den Cache leeren (SSH):
Run: `ssh website 'php85 -- /www/htdocs/<konto>/kirby.eisenschmidt.website/www' < bin/clear-cache.php`
Expected: `Page cache cleared (1 host)`

Dann sofort (Svens Terminal):
```bash
for p in / /2026-10-09-green-woodpecker/ /2026-08-04-same-again/ /archive/ /index.xml '/search/?q=woodpecker'; do
  cold=$(curl -s -o /dev/null -u "$STAGING_USER:$STAGING_PASSWORD" -w '%{http_code} %{time_starttransfer}' "$S$p")
  cached=$(curl -s -o /dev/null -u "$STAGING_USER:$STAGING_PASSWORD" -w '%{time_starttransfer}' "$S$p")
  echo "$p  kalt $cold  Cache $cached"
done
curl -s -o /dev/null -w 'Hugo live / %{time_starttransfer}\n' https://sven.eisenschmidt.website/
```
Expected: jede Zeile mit `200`, aus dem Cache deutlich schneller als kalt. Die Suche hat keinen Cache, beide Werte gleich. Die Zeile von Hugo dient zum Vergleich.

- [ ] **Schritt 5 (SSH und Sven): Grenzen von Web-PHP**

Die Datei liegt nur für diese Messung auf dem Server, nie im Repo. Der Controller schreibt sie in einen Temp-Ordner außerhalb des Repos: `L=$(mktemp -d)`, dann `$L/limits.php` mit diesem Inhalt:

```php
<?php

// Phase 5a, temporary: limits of the web PHP, for a logged-in admin only.
// Never in git; the next deploy's rsync --delete removes it as well.
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/kirby/bootstrap.php';
$kirby = new Kirby(['roots' => ['index' => __DIR__]]);
if ($kirby->user()?->isAdmin() !== true) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
foreach (['upload_max_filesize', 'post_max_size', 'max_file_uploads', 'memory_limit', 'max_execution_time', 'max_input_time'] as $key) {
    echo "$key: ", ini_get($key), "\n";
}
echo 'ini_set memory_limit 1024M: ', @ini_set('memory_limit', '1024M') === false ? 'refused' : ini_get('memory_limit'), "\n";
echo 'set_time_limit 180: ', @set_time_limit(180) ? 'ok' : 'refused', "\n";
echo 'php: ', PHP_VERSION, ' ', PHP_SAPI, "\n";
foreach (['gd', 'exif', 'fileinfo', 'xmlreader', 'curl', 'mbstring', 'intl'] as $extension) {
    echo "$extension: ", extension_loaded($extension) ? 'yes' : 'MISSING', "\n";
}
```

a. **(SSH)** Run: `ssh website 'cat > /www/htdocs/<konto>/kirby.eisenschmidt.website/www/limits.php' < "$L/limits.php"`

b. **(Svens Terminal)** Run: `curl -s -o /dev/null -u "$STAGING_USER:$STAGING_PASSWORD" -w '%{http_code}\n' "$S/limits.php"`
Expected: `404` (ohne Anmeldung im Panel).

c. **(Sven)** Im Browser angemeldet https://kirby.eisenschmidt.website/limits.php öffnen und die Zeilen in den Chat kopieren. Im Probelauf (lokal, Werte des Macs) sah das so aus:
```
upload_max_filesize: 64M
post_max_size: 64M
max_file_uploads: 20
memory_limit: 512M
max_execution_time: 30
max_input_time: 60
ini_set memory_limit 1024M: refused
set_time_limit 180: ok
php: 8.5.11 fpm-fcgi
gd: yes
…
```

d. **(SSH)** Run: `ssh website 'rm /www/htdocs/<konto>/kirby.eisenschmidt.website/www/limits.php'`, danach Schritt b noch einmal: `404`.

Der Assistent braucht: `upload_max_filesize` und `post_max_size` über 60 MB (eine Datei pro Anfrage), 320 MB Speicher für die Vorschau eines Fotos mit 64 Megapixeln (`uploads.php` setzt 1024M per `ini_set`), `set_time_limit` für 60 s (Upload) und 180 s (Claude), alle Erweiterungen `yes`. Liegt ein Wert darunter, kommt er in die Messwerte, die Lösung entscheidet Sven vor 5b.

- [ ] **Schritt 6 (Sven, Controller notiert): Assistent**

Im Panel unter Assistent, jeweils mit Stoppuhr:
1. **20 Fotos:** 20 JPEGs aus der Kamera in einem Zug ablegen, darunter die größte Datei, die Sven hat. Messen: Zeit bis alle 20 Vorschauen da sind, größte Datei in MB, jede Fehlermeldung wörtlich.
2. **Opus-Runde mit Fotos:** „Neuer Entwurf" mit 4 der Fotos, Modell Opus 5.5. Messen: Zeit bis zur Karte, Tokens und Dauer aus Assistent › Protokoll. Karte verwerfen.
3. **OSRM aus dem Web-PHP:** eine Route zwischen zwei Orten vorschlagen lassen. Messen: Zeit bis zur Karte „Route", km auf der Karte. Verwerfen.
4. **GPX:** die GPX-Datei einer Fahrt hochladen und als Route vorschlagen lassen. Messen: Zeit bis zur Karte. Verwerfen.
5. „Neuer Chat": die Uploads des Chats gehen.

**(SSH)** Danach Run: `ssh website 'ls -A /www/htdocs/<konto>/kirby.eisenschmidt.website/storage/assistant'`
Expected: `.htaccess` und keine oder wenige Ordner mit 32 Hex-Zeichen (nach „Neuer Chat" ist der Ordner des Chats weg). Schritt 1 hat `/storage/assistant/.htaccess` schon als nicht erreichbar geprüft.

- [ ] **Schritt 7 (Svens Terminal): URLs gegen Staging**

Lokal liegt derselbe Import wie auf dem Server (Aufgabe 37).

Run: `time make kirby-check-urls SITE=https://kirby.eisenschmidt.website`
Expected: `<n> URLs from the sitemap, <n> redirects with their targets, 0 failures` (in Phase 4: 296 und 539, mit neuen Beiträgen mehr URLs).

- [ ] **Schritt 8 (SSH): Pull**

Holt den Stand von Staging nach `www/`. Danach gilt lokal Svens Konto vom Server, das Dev-Konto ist weg.

Run: `time make kirby-pull`
Expected: am Ende `Restored <n> files from backups/website-<Zeit>.tar.gz` und `META` mit `php: 8.5.…`. Dauer und Größe des Archivs (`ls -lh backups/*.tar.gz`) in die Messwerte.

---

### Aufgabe 40: Abschluss

- [ ] **Schritt 1: Alle Prüfungen**

Run: `make kirby-lint && make kirby-test && make kirby-restore-test`
Expected: `lint ok`, `OK (230 tests, …)`, `OK: <n> pages identical`.

- [ ] **Schritt 2: Hugo prüfen**

Run: `hugo --renderToMemory --quiet && echo hugo ok`
Expected: `hugo ok`

- [ ] **Schritt 3: Keine Geviertstriche**

Run: `grep -rn "$(printf '\342\200\224')" bin server Makefile docs/superpowers/plans/2026-10-13-kirby-phase-5a-staging.md | wc -l`
Expected: `0`

- [ ] **Schritt 4: Git prüfen**

Run: `git status --short`
Expected: höchstens dieser Plan. Keine Pfade unter `www/content`, `www/media`, `backups`, kein `limits.php`, keine `data.db`.

- [ ] **Checkpoint:** Sven committet auf `kirby`: `git add docs/superpowers/plans/2026-10-13-kirby-phase-5a-staging.md` und `git commit -m "Plan Phase 5a abgehakt, Messwerte von Staging"`. Phase 5a ist fertig.

---

## Was Phase 5b wissen muss

- **Hugos Deploy zuerst abschalten:** `.github/workflows/deploy.yml` läuft bei jedem Push auf `main` und alle 2 Stunden. Es lädt `public/` mit `--delete` in den Ordner aus dem Secret `DEPLOY_PATH`, ohne Ausschlüsse. Liegt Kirby in diesem Ordner, löscht der nächste Lauf `www/`, `vendor/`, `storage/` und `backups/`. Der Workflow geht also weg, bevor Kirby den Ordner von Hugo bekommt, spätestens im selben Merge.
- **Umschalten, zwei Wege:** (a) Die Domain sven.eisenschmidt.website zeigt in KAS auf den Ordner `kirby.eisenschmidt.website/`, Document Root wieder der Ordner selbst. Hugos Ordner bleibt unberührt, der Rückweg ist dieselbe Einstellung zurück. (b) Kirby zieht in den Ordner `sven.eisenschmidt.website/` mit derselben Anordnung (`www/`, `vendor/`, `server/root.htaccess` als `.htaccess`). Hugos `.htaccess` vorher als `.htaccess.hugo` sichern, Hugos übrige Dateien sind dann nicht mehr erreichbar. Rückweg: `.htaccess.hugo` zurück. In beiden Fällen bleibt das Document Root der Ordner selbst, KAS bleibt einheitlich.
- **Deploy für live:** `PUBLIC=1` (löscht `.htpasswd`, lädt `www/.htaccess` ohne Staging-Block), `KIRBY_SITE_URL=https://sven.eisenschmidt.website`, bei Weg (b) auch `KIRBY_DEPLOY_PATH`. Eine eigene Umgebung `production` mit eigenem Workflow oder Eingabe.
- **Workflow auf GitHub:** kommt mit dem Merge in `main`. `workflow_dispatch` startet nur, wenn die Datei auf dem Standard-Branch liegt. Dazu eine Umgebung mit `STAGING_USER`, `STAGING_PASSWORD`, `ANTHROPIC_API_KEY`, kein `pull_request`-Trigger (öffentliches Repo, eigener Runner), `setup-php` mit PHP 8.5 auf dem Runner. Das Repository-Secret `DEPLOY_PATH` zeigt auf Hugos Ordner.
- **Kirby-Lizenz:** Basic, 99 EUR, vor dem Umschalten kaufen, binnen einer Woche aktivieren. `.license` ist Zustand und geht in die Snapshots.
- **Letzter Import:** `git merge main`, `make kirby-seed FORCE=1`, frische Kopie von Pops `data.db` und `bin/pop-import.php` (danach Kopie löschen), `bin/backup.sh --snapshot --no-accounts`, `make kirby-upload-state` gegen den Ordner von live. Der Restore leert `site/accounts`: Svens Konto danach neu wie in Aufgabe 37, Schritt 9. Den Prompt prüfen wie in Schritt 10. Was auf Staging im Panel entstand, geht dabei verloren.
- **Objektiv bei 5 Fotos:** nach dem letzten Import im Panel eintragen (`2026-02-19-dawn`, `IMG_0957`, `IMG_0972`, `IMG_0996`, `IMG_1009`, `IMG_1024`: `EF-S55-250mm f/4-5.6 IS STM`).
- **Vorwärmen nach dem Umschalten:** `make kirby-warm KIRBY_SITE_URL=https://sven.eisenschmidt.website`. Der User-Agent sagt „bot", „Besucher" zählt die Abrufe nicht.
- **Pop:** zwei Wochen nach dem Umschalten abschalten, die Rohdaten mit Fingerprints auf dem Server löschen (Sven).
- **Hugo-Reste löschen** nach dem Rückweg: `assets/`, `static/`, `layouts/`, `scripts/check-urls.sh`, `scripts/to-srgb.sh`, `data/redirects.yaml`, Pagefind, `hugo.toml`, `archetypes/`, `resources/_gen`, `content/`, `public/`, `data/`, `.hugo_build.lock`, `.srgb-originals`, `.parity`, der Hugo-Eintrag in `.claude/launch.json`, Hugo in der README, `make push`, `check-hugo`, `srgb`, der Ordner von Hugo auf dem Server. Die Skills `write-post` und `journey-post`, wenn Sven das entscheidet.
- **IndexNow:** Schlüsseldatei von `static/` nach `www/`, in `.github/workflows/indexnow.yml` `sparse-checkout: www` und `basename www/*-*-*-*-*.txt .txt`.
- **Beim Merge in `main`:** den Block `kirby-state` aus `.git/info/exclude` entfernen.
- **Einmal-Werkzeuge nach dem letzten Import löschen:** `bin/lib/hugo.php`, `bin/seed.php`, `bin/lib/seed.php`, `bin/pop-import.php`, `bin/lib/pop.php`, `bin/parity.php`, die Make-Ziele `kirby-seed` und `kirby-parity`. Die Tests bauen ihre Testseite heute über den Importer (`tests/Support/HugoFixture.php`). Sie brauchen vorher eigene Kirby-Testinhalte, der Restore-Test einen Snapshot statt des Seeds.
- **Präfix `kirby-`:** an allen Make-Zielen entfernen, sobald die Hugo-Ziele weg sind.
- **Nachbauten von Hugo:** Bestandsaufnahme vom 2026-10-13, Sven entscheidet pro Punkt, ob der Nachbau bleibt oder Kirbys eigener Weg kommt. Danach die Kommentare der Art „as Hugo's …" neu formulieren.
- **Prompt-Datei:** den lokalen Entwurf löschen, sobald der Prompt auf dem Server im Panel steht.
- **About:** den Abschnitt „About this Website" (nennt Hugo und Pop) im Panel ändern.
- **Snapshots und Zeit:** Jeder Deploy liest den ganzen Zustand zweimal (Prüfsummen für `MANIFEST.sha256`, `rsync -c` gegen den letzten Snapshot). Die Dauer aus den Messwerten zeigt, ob das bei 4 GB auf All-Inkl reicht.
- **Staging danach:** abschalten oder als Staging behalten. Bei Weg (a) wird der Ordner von Staging zu live.
- **Externe Sicherung:** Luisas Zustand liegt zusätzlich auf dem NAS. Für diese Seite fehlt das noch.

---

## Messwerte (Aufgaben 36 bis 39)

Der Controller trägt die Werte während der Abnahme ein.

| Messung | Wert |
|---|---|
| Erster Deploy, Dauer (36) | 22,3 s (Lint und Tests eingeschlossen) |
| Pop-Import: Besuche, Tage, URLs ohne Seite (37) | 9.038 Besuche, 283 Tage, 381 URLs ohne Seite (alle von Testservern wie `localhost:1313`) |
| Upload des ersten Stands, Dauer und Größe (37) | 2:50 min, 4,39 GB, 1.807 Dateien, 26,8 MB/s |
| Deploy mit 4 GB Zustand, Dauer; Größe des neuen Snapshots (37) | 32,1 s; 1,5 MB |
| Vorwärmen: Seiten, Vorschaubilder, Dauer (38) | 303 Seiten in 8 s, 3.517 Vorschaubilder in 404 s, 0 Fehler |
| Platz: `www/content`, `www/media`, `backups`, `vendor` (38) | 4,1 GB, 260 MB, 4,1 GB, 3,8 MB |
| Startseite kalt / Cache (39) | 0,23 s / 0,11 s |
| Foto-Beitrag kalt / Cache (39) | 0,13 s / 0,10 s |
| Reise mit Karte kalt / Cache (39) | 0,14 s / 0,10 s |
| Archiv kalt / Cache (39) | 0,69 s / 0,10 s |
| Feed kalt / Cache (39) | 1,85 s / 0,10 s |
| Suche (39) | 0,17 s (ohne Cache) |
| Hugo live, Startseite (39) | 0,12 s |
| Web-PHP: `upload_max_filesize`, `post_max_size`, `memory_limit`, `ini_set` 1024M, `max_execution_time`, `set_time_limit` (39) || 512M, 512M, 512M, `ini_set` 1024M ok, `max_execution_time` 60, `set_time_limit` ok, `max_file_uploads` 20, alle Erweiterungen da (gemessen hinter dem Staging-Passwort, Datei danach gelöscht) |
| Upload von 20 Fotos, größte Datei (39) | |
| Opus-Runde mit 4 Fotos: Dauer, Tokens (39) | |
| OSRM-Route: Dauer (39) | |
| GPX-Import: Dauer (39) | |
| `make kirby-check-urls` gegen Staging: URLs, Weiterleitungen, Fehler, Dauer (39) || 296 URLs, 539 Weiterleitungen, 0 Fehler, 6:42 min (1.374 Anfragen einzeln über HTTPS) |
| `make kirby-pull`: Dauer, Größe des Archivs (39) || 2:52 min, 4,1 GB, 1.812 Dateien |

---

## Abdeckung

| Anforderung | Aufgabe |
|---|---|
| Deploy aus Luisas Skript: Build mit `composer install --no-dev`, `www/` und `vendor/` per rsync, `markdown-field` und `www/kirby` mit hoch | 32 |
| Zustand, Host-Config und Erzeugtes ausgeschlossen, `--delete` löscht sie nie | 32 |
| Snapshot vor dem Deploy (`backup.sh --snapshot`), neueste 30, `.website-tmp-*` aufräumen | 31, 32 |
| `php85` für jedes PHP auf dem Server, `PHP` in `backup.sh` | 31, 32 |
| `env.php` anlegen, `public=false`, `url` fest, `assistantKey` über stdin | 32 |
| `.salt` nur für eine neue Seite, Abbruch bei Inhalten ohne `.salt` | 32 |
| Inhaltsmigrationen | entfallen, das Repo hat keine |
| Staging-Passwort: `.htpasswd` neben `www/`, Block in `www/.htaccess`, `/.well-known/` frei | 31, 32, 36 |
| Document Root ist der Ordner, `server/root.htaccess`, Platzhalter `index.htm` weg | 31, 32, 36 |
| Seiten-Cache unter der Sperre (`BlogFileCache`), Prüfung der Startseite mit Passwort | 31, 32 |
| `make kirby-deploy`, `make kirby-pull` | 32, 39 |
| Deploy vom Mac, Lint und Tests vor dem Deploy; Workflow auf GitHub erst in 5b | 32, 35, „Was Phase 5b wissen muss" |
| Erster Stand: Merge von `main`, Seed, Pop-Import aus lokaler Kopie, Kopie gelöscht, Snapshot ohne Konten, Upload, Restore | 33, 37 |
| Erstes Konto auf dem Server ohne Passwort auf einer Kommandozeile | 37 |
| Prompt von Sven, nie in Git oder Plan | 37 (Hinweis) |
| Vorschaubilder vorwärmen mit Passwort | 34, 38 |
| Abnahme: Geschwindigkeit kalt und aus dem Cache | 39 |
| Abnahme: 20 Fotos, Opus-Runde, OSRM, GPX | 39 |
| Abnahme: Grenzen von Web-PHP | 39 |
| Abnahme: `make kirby-check-urls` gegen Staging mit Passwort | 34, 39 |
| Abnahme: gesperrte Pfade, Panel, Bilder, Schrägstrich, Weiterleitung, kein `/www/` | 39 |
| Notizen für 5b | „Was Phase 5b wissen muss" |
