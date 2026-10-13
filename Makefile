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

# Nur localhost: der PHP-Server liefert jede Datei unter www/ aus, auch
# content/ und site/. Nie an 0.0.0.0 binden.
# Uploads im Assistenten: bis 60 MB pro Datei, die Vorschau eines Fotos mit
# 64 Megapixeln braucht rund 320 MB Speicher (plugins/assistant/uploads.php).
# bin/dev-router.php sperrt wie www/.htaccess content/, site/ und kirby/.
# Der Seiten-Cache sieht keine Änderung am Code: der Start leert ihn.
.PHONY: kirby-dev
kirby-dev:
	@test -f vendor/autoload.php || { echo "Kirby fehlt. Erst: composer install"; exit 1; }
	@test -f $(WWW)/site/config/env.php || { echo "Keine Konfiguration. Erst: make kirby-config"; exit 1; }
	@test -d $(WWW)/content || { echo "Keine Inhalte. Erst: make kirby-pull"; exit 1; }
	@$(PHP) -r 'if (PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION !== trim(file_get_contents(".php-version"))) fwrite(STDERR, "Hinweis: hier PHP ".PHP_VERSION.", .php-version sagt ".trim(file_get_contents(".php-version"))."\n");'
	@rm -rf $(WWW)/site/cache/localhost_$(KIRBY_PORT)
	@echo "Seite: http://localhost:$(KIRBY_PORT)  Panel: http://localhost:$(KIRBY_PORT)/panel  (Ende mit Ctrl+C)"
	@set -o pipefail; PHP_CLI_SERVER_WORKERS=4 $(PHP) -d upload_max_filesize=64M -d post_max_size=64M -d memory_limit=1024M -S localhost:$(KIRBY_PORT) -t $(WWW) bin/dev-router.php 2>&1 | grep --line-buffered -v -E '(Accepted|Closing)$$'

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

# Pre-commit- und Pre-push-Hook gegen den Prompt des Assistenten in Git
# (bin/prompt-guard.php). Die Hooks laufen auf jedem Branch, auch auf main.
# Nach einer Änderung am Skript noch einmal aufrufen.
.PHONY: kirby-hooks
kirby-hooks:
	@hooks="$$(git rev-parse --git-path hooks)"; \
	for hook in pre-commit pre-push; do \
	  if [ -f "$$hooks/$$hook" ] && ! grep -q prompt-guard.php "$$hooks/$$hook"; then echo "$$hooks/$$hook gibt es schon, nicht überschrieben"; exit 1; fi; \
	done; \
	cp bin/prompt-guard.php "$$hooks/prompt-guard.php"; \
	cp $(WWW)/site/plugins/assistant/words.php "$$hooks/prompt-words.php"; \
	for hook in pre-commit pre-push; do \
	  printf '#!/bin/sh\n# make kirby-hooks: no text from the assistant prompt in git.\nexec %s "$$(git rev-parse --git-path hooks)/prompt-guard.php" %s\n' "$(PHP)" "$$hook" > "$$hooks/$$hook"; \
	  chmod +x "$$hooks/$$hook"; \
	done; \
	echo "Hooks eingerichtet: pre-commit, pre-push"

# Phase 4: die URLs der Live-Seite gegen Kirby (bin/check-urls.php). Läuft
# gegen make kirby-dev in einem zweiten Terminal. Gegen Staging:
# make kirby-check-urls SITE=$(KIRBY_SITE_URL), das Passwort aus STAGING_USER
# und STAGING_PASSWORD.
SITE ?= http://localhost:$(KIRBY_PORT)
.PHONY: kirby-check-urls
kirby-check-urls:
	@$(PHP) bin/check-urls.php $(SITE)

# Deploy der Live-Seite. Host und Pfad sind nicht geheim, es gilt der
# SSH-Alias website.
# Den API-Schlüssel holt bin/deploy-env.sh aus der lokalen env.php. Ein
# Staging-Passwort kommt nur, wenn STAGING_USER und STAGING_PASSWORD gesetzt
# sind. Lint und Tests laufen vorher.
KIRBY_DEPLOY_HOST ?= website
KIRBY_DEPLOY_PATH ?= /www/htdocs/w00611ad/sven.eisenschmidt.website
KIRBY_SITE_URL ?= https://sven.eisenschmidt.website

.PHONY: kirby-deploy
kirby-deploy: kirby-lint kirby-test
	. bin/deploy-env.sh && DEPLOY_HOST=$(KIRBY_DEPLOY_HOST) DEPLOY_PATH=$(KIRBY_DEPLOY_PATH) SITE_URL=$(KIRBY_SITE_URL) bin/deploy.sh

# Ersetzt Inhalte und Konten in www/ durch den Stand des Servers.
.PHONY: kirby-pull
kirby-pull:
	DEPLOY_HOST=$(KIRBY_DEPLOY_HOST) DEPLOY_PATH=$(KIRBY_DEPLOY_PATH) bin/pull.sh

# Ruft jede Seite und jedes Bild unter /media/ einmal ab (bin/warm.php).
.PHONY: kirby-warm
kirby-warm:
	@$(PHP) bin/warm.php $(KIRBY_SITE_URL)

# Holt die Zugriffslogs vom Server und öffnet den Bericht von GoAccess.
.PHONY: logs
logs:
	@command -v goaccess >/dev/null 2>&1 || { echo "goaccess is required but not installed. Install with: brew install goaccess"; exit 1; }
	@mkdir -p logs
	@echo "Fetching logs from server..."
	@ssh website 'zcat /www/htdocs/*/logs/access_log_sven_eisenschmidt_website*.gz' > logs/access.log
	@echo "Analyzing logs with GoAccess..."
	@goaccess logs/access.log -o logs/report.html --log-format='%h - - [%d:%t %^] "%r" %s %b "%R" "%u" "%^" "%^"' --date-format='%d/%b/%Y' --time-format='%H:%M:%S'
	@echo "Report generated: logs/report.html"
	@open logs/report.html
