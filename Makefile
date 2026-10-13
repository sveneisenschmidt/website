WWW := www
# make ohne Ziel startet die Seite lokal.
.DEFAULT_GOAL := dev
# PHP für alle Ziele. Die Seite läuft auf dem Server mit PHP 8.5
# (.php-version), lokal ist es dieselbe Version. Anderes PHP: make PHP=...
PHP ?= php
# Port von make dev. Luisas Seite nutzt lokal 8000.
PORT := 8010

# Einmal nach dem Klonen: Abhängigkeiten, lokale Konfiguration, Git-Hooks.
# Danach holt make pull die Inhalte vom Server.
.PHONY: setup
setup:
	composer install
	@$(MAKE) --no-print-directory config hooks

.PHONY: config
config:
	@$(PHP) bin/init-config.php $(WWW)

.PHONY: test
test:
	@test -x vendor/bin/phpunit || { echo "PHPUnit fehlt. Erst: composer install"; exit 1; }
	@$(PHP) vendor/bin/phpunit

# Nur localhost: der PHP-Server liefert jede Datei unter www/ aus, auch
# content/ und site/. Nie an 0.0.0.0 binden.
# Uploads im Assistenten: bis 60 MB pro Datei, die Vorschau eines Fotos mit
# 64 Megapixeln braucht rund 320 MB Speicher (plugins/assistant/uploads.php).
# bin/dev-router.php sperrt wie www/.htaccess content/, site/ und kirby/.
# Der Seiten-Cache sieht keine Änderung am Code: der Start leert ihn.
.PHONY: dev
dev:
	@test -f vendor/autoload.php || { echo "Kirby fehlt. Erst: composer install"; exit 1; }
	@test -f $(WWW)/site/config/env.php || { echo "Keine Konfiguration. Erst: make config"; exit 1; }
	@test -d $(WWW)/content || { echo "Keine Inhalte. Erst: make pull"; exit 1; }
	@$(PHP) -r 'if (PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION !== trim(file_get_contents(".php-version"))) fwrite(STDERR, "Hinweis: hier PHP ".PHP_VERSION.", .php-version sagt ".trim(file_get_contents(".php-version"))."\n");'
	@rm -rf $(WWW)/site/cache/localhost_$(PORT)
	@echo "Seite: http://localhost:$(PORT)  Panel: http://localhost:$(PORT)/panel  (Ende mit Ctrl+C)"
	@set -o pipefail; PHP_CLI_SERVER_WORKERS=4 $(PHP) -d upload_max_filesize=64M -d post_max_size=64M -d memory_limit=1024M -S localhost:$(PORT) -t $(WWW) bin/dev-router.php 2>&1 | grep --line-buffered -v -E '(Accepted|Closing)$$'

.PHONY: backup
backup:
	bin/backup.sh $(WWW) backups

.PHONY: restore
restore:
	@test -n "$(BACKUP)" || { echo "Aufruf: make restore BACKUP=backups/website-....tar.gz"; exit 1; }
	bin/restore.sh $(BACKUP) $(WWW)

.PHONY: restore-test
restore-test:
	PHP=$(PHP) bin/restore-test.sh $(WWW)

.PHONY: lint
lint:
	@find src $(WWW)/site $(WWW)/index.php bin tests -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null && echo "lint ok"

# Formatiert den Code nach PER-CS 2.0 (.php-cs-fixer.dist.php). CI prüft das.
.PHONY: format
format:
	@$(PHP) vendor/bin/php-cs-fixer fix --show-progress=none

# Statische Analyse (phpstan.neon). CI prüft das.
.PHONY: analyse
analyse:
	@$(PHP) vendor/bin/phpstan analyse --no-progress

# Pre-commit- und Pre-push-Hook gegen den Prompt des Assistenten in Git
# (bin/prompt-guard.php). Die Hooks laufen auf jedem Branch, auch auf main.
# Nach einer Änderung am Skript noch einmal aufrufen.
.PHONY: hooks
hooks:
	@hooks="$$(git rev-parse --git-path hooks)"; \
	for hook in pre-commit pre-push; do \
	  if [ -f "$$hooks/$$hook" ] && ! grep -q prompt-guard.php "$$hooks/$$hook"; then echo "$$hooks/$$hook gibt es schon, nicht überschrieben"; exit 1; fi; \
	done; \
	cp bin/prompt-guard.php "$$hooks/prompt-guard.php"; \
	cp $(WWW)/site/plugins/assistant/words.php "$$hooks/prompt-words.php"; \
	for hook in pre-commit pre-push; do \
	  printf '#!/bin/sh\n# make hooks: no text from the assistant prompt in git.\nexec %s "$$(git rev-parse --git-path hooks)/prompt-guard.php" %s\n' "$(PHP)" "$$hook" > "$$hooks/$$hook"; \
	  chmod +x "$$hooks/$$hook"; \
	done; \
	echo "Hooks eingerichtet: pre-commit, pre-push"

# Die URLs der Live-Seite gegen Kirby (bin/check-urls.php). Läuft gegen
# make dev in einem zweiten Terminal. Gegen die Live-Seite:
# make check-urls SITE=$(LIVE_URL).
SITE ?= http://localhost:$(PORT)
.PHONY: check-urls
check-urls:
	@$(PHP) bin/check-urls.php $(SITE)

# Der Server über den SSH-Alias website. Den Ordner auf dem Server nennt die
# lokale .env (nie in Git, sie nennt das Hosting-Konto):
#   LIVE_PATH=/www/htdocs/<konto>/sven.eisenschmidt.website
# Deployt wird nur über den Workflow CI.
LIVE_HOST ?= website
-include .env
LIVE_URL ?= https://sven.eisenschmidt.website

.PHONY: live-path
live-path:
	@test -n "$(LIVE_PATH)" || { echo "LIVE_PATH fehlt: .env anlegen, siehe Kommentar im Makefile" >&2; exit 1; }
	@test "$(notdir $(LIVE_PATH))" = sven.eisenschmidt.website || { echo "LIVE_PATH zeigt nicht auf den Ordner sven.eisenschmidt.website" >&2; exit 1; }

# Ersetzt Inhalte und Konten in www/ durch den Stand des Servers.
.PHONY: pull
pull: live-path
	DEPLOY_HOST=$(LIVE_HOST) DEPLOY_PATH=$(LIVE_PATH) bin/pull.sh

# Ruft jede Seite und jedes Bild unter /media/ einmal ab (bin/warm.php).
.PHONY: warm
warm:
	@$(PHP) bin/warm.php $(LIVE_URL)
