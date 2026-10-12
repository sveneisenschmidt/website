.PHONY: dev build publish check-deps check-hugo srgb logs check-main

check-deps:
	@command -v hugo >/dev/null 2>&1 || { echo "hugo is required but not installed. Install with: brew install hugo"; exit 1; }
	@command -v npx >/dev/null 2>&1 || { echo "npx is required but not installed. Install Node.js first"; exit 1; }

# Hugo verwirft beim Verkleinern das Farbprofil. Bilder in Display P3 wirken auf
# der Seite darum blass. Das Skript rechnet sie vorher nach sRGB um.
srgb:
	@bash scripts/to-srgb.sh

dev: check-deps srgb
	$(eval IP := $(shell ipconfig getifaddr en0))
	@echo "Mobile: http://$(IP):1313"
	@trap 'kill 0' EXIT; (until curl -s http://localhost:1313 >/dev/null 2>&1; do sleep 0.5; done; open http://localhost:1313) & hugo server --buildDrafts --buildFuture --bind 0.0.0.0 --baseURL http://$(IP):1313

build:
	rm -rf public/*
	hugo --minify
	npx -y pagefind@1.5.2 --site public

# resources/_gen ist eingecheckt und kommt aus `hugo --gc` in push. Baut der Mac
# mit einer anderen Version als der Runner, findet der Runner den Bild-Cache nicht.
check-hugo: check-deps
	@want=$$(sed -n 's/.*hugo-version: "\(.*\)"/\1/p' .github/workflows/deploy.yml); \
	have=$$(hugo version | sed -n 's/^hugo v\([0-9.]*\).*/\1/p'); \
	test "$$have" = "$$want" || { echo "hugo $$have on this Mac, deploy.yml builds with $$want. Install $$want, or raise deploy.yml in the same commit as resources/_gen."; exit 1; }

# make push committet alles und pusht main. Auf einem anderen Branch landet der
# Commit dort, und gepusht wird ein alter Stand von main.
check-main:
	@test "$$(git branch --show-current)" = main || { echo "make push nur auf main, gerade: $$(git branch --show-current)"; exit 1; }

push: check-main check-hugo srgb
	hugo --gc
	git add -A
	git commit -m "Update site $$(date +%Y-%m-%d\ %H:%M)" || true
	git pull --rebase origin main
	git push origin main

pull:
	git fetch --all
	git checkout main
	git stash
	git pull --rebase origin main
	git stash pop || true


logs:
	@command -v goaccess >/dev/null 2>&1 || { echo "goaccess is required but not installed. Install with: brew install goaccess"; exit 1; }
	@mkdir -p logs
	@echo "Fetching logs from server..."
	@ssh website 'zcat /www/htdocs/*/logs/access_log_sven_eisenschmidt_website*.gz' > logs/access.log
	@echo "Analyzing logs with GoAccess..."
	@goaccess logs/access.log -o logs/report.html --log-format='%h - - [%d:%t %^] "%r" %s %b "%R" "%u" "%^" "%^"' --date-format='%d/%b/%Y' --time-format='%H:%M:%S'
	@echo "Report generated: logs/report.html"
	@open logs/report.html

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

.PHONY: kirby-test
kirby-test:
	@test -x vendor/bin/phpunit || { echo "PHPUnit fehlt. Erst: composer install"; exit 1; }
	@$(PHP) vendor/bin/phpunit

.PHONY: kirby-seed
kirby-seed:
	@test -f vendor/autoload.php || { echo "Kirby fehlt. Erst: composer install"; exit 1; }
	$(PHP) bin/seed.php $(if $(filter 1,$(FORCE)),--force,)

# Nur localhost: der PHP-Server liefert jede Datei unter www/ aus, auch
# content/ und site/. Anders als Hugos make dev nie an 0.0.0.0 binden.
# Uploads im Assistenten: bis 60 MB pro Datei, die Vorschau eines Fotos mit
# 64 Megapixeln braucht rund 320 MB Speicher (plugins/assistant/uploads.php).
.PHONY: kirby-dev
kirby-dev:
	@test -f vendor/autoload.php || { echo "Kirby fehlt. Erst: composer install"; exit 1; }
	@test -f $(WWW)/site/config/env.php || { echo "Keine Konfiguration. Erst: make kirby-config"; exit 1; }
	@test -d $(WWW)/content || { echo "Keine Inhalte. Erst: make kirby-seed"; exit 1; }
	@$(PHP) -r 'if (PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION !== trim(file_get_contents(".php-version"))) fwrite(STDERR, "Hinweis: hier PHP ".PHP_VERSION.", .php-version sagt ".trim(file_get_contents(".php-version"))."\n");'
	@echo "Seite: http://localhost:$(KIRBY_PORT)  Panel: http://localhost:$(KIRBY_PORT)/panel  (Ende mit Ctrl+C)"
	@set -o pipefail; PHP_CLI_SERVER_WORKERS=4 $(PHP) -d upload_max_filesize=64M -d post_max_size=64M -d memory_limit=1024M -S localhost:$(KIRBY_PORT) -t $(WWW) $(WWW)/kirby/router.php 2>&1 | grep --line-buffered -v -E '(Accepted|Closing)$$'

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

.PHONY: kirby-lint
kirby-lint:
	@find $(WWW)/site $(WWW)/index.php bin tests -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null && echo "lint ok"
