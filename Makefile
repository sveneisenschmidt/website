WWW := www
# make ohne Ziel startet die Seite lokal.
.DEFAULT_GOAL := dev
# PHP für alle Ziele. Der Server nutzt PHP 8.5 (.php-version), lokal soll
# es dieselbe Version sein. Ein anderes PHP wählt make PHP=...
PHP ?= php
# Port von make dev. Den Port 8000 nutzt lokal ein anderes Projekt.
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
	@test -x vendor/bin/phpunit || { echo "PHPUnit fehlt. Zuerst composer install ausführen."; exit 1; }
	@$(PHP) vendor/bin/phpunit

# Nur localhost, nie 0.0.0.0: Der PHP-Server ist laut PHP-Handbuch nicht für
# ein öffentliches Netz gedacht, und lokal läuft Kirby im Debug-Modus.
# Die Upload-Grenzen passen zum Assistenten: bis 60 MB pro Datei, und die
# Vorschau eines Fotos mit 64 Megapixeln braucht rund 320 MB Speicher
# (src/Assistant/Uploads.php).
# bin/dev-router.php sperrt content/, site/ und kirby/ wie www/.htaccess.
# Der Seiten-Cache hält das HTML jeder Seite. Kirby leert ihn bei jeder
# Änderung im Panel, aber nicht bei einer Änderung am Code. Darum leert
# make dev ihn beim Start.
.PHONY: dev
dev:
	@test -f vendor/autoload.php || { echo "Kirby fehlt. Zuerst composer install ausführen."; exit 1; }
	@test -f $(WWW)/site/config/env.php || { echo "env.php fehlt. Zuerst make config ausführen."; exit 1; }
	@test -d $(WWW)/content || { echo "Die Inhalte fehlen. Zuerst make pull ausführen."; exit 1; }
	@$(PHP) -r 'if (PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION !== trim(file_get_contents(".php-version"))) fwrite(STDERR, "Hier läuft PHP ".PHP_VERSION.", .php-version verlangt ".trim(file_get_contents(".php-version")).".\n");'
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

# Formatiert den Code nach PER-CS 2.0 (.php-cs-fixer.dist.php). Der Workflow
# CI prüft das Format.
.PHONY: format
format:
	@$(PHP) vendor/bin/php-cs-fixer fix --show-progress=none

# Statische Analyse mit PHPStan (phpstan.neon). Der Workflow CI führt sie
# auch aus.
.PHONY: analyse
analyse:
	@$(PHP) vendor/bin/phpstan analyse --no-progress

# Richtet die Git-Hooks pre-commit und pre-push ein. Sie stoppen einen Commit
# oder Push mit Text aus dem Prompt des Assistenten (bin/prompt-guard.php).
# Die Hooks laufen auf jedem Branch, auch auf main. make hooks kopiert
# bin/prompt-guard.php und src/Assistant/Words.php in den Hook-Ordner von Git.
# Nach einer Änderung an einer der beiden Dateien noch einmal aufrufen.
.PHONY: hooks
hooks:
	@hooks="$$(git rev-parse --git-path hooks)"; \
	for hook in pre-commit pre-push; do \
	  if [ -f "$$hooks/$$hook" ] && ! grep -q prompt-guard.php "$$hooks/$$hook"; then echo "$$hooks/$$hook gibt es schon und bleibt unverändert."; exit 1; fi; \
	done; \
	cp bin/prompt-guard.php "$$hooks/prompt-guard.php"; \
	cp src/Assistant/Words.php "$$hooks/prompt-words.php"; \
	for hook in pre-commit pre-push; do \
	  printf '#!/bin/sh\n# Written by make hooks. Stops a commit or push with text from the assistant prompt.\nexec %s "$$(git rev-parse --git-path hooks)/prompt-guard.php" %s\n' "$(PHP)" "$$hook" > "$$hooks/$$hook"; \
	  chmod +x "$$hooks/$$hook"; \
	done; \
	echo "Die Hooks pre-commit und pre-push sind eingerichtet."

# Prüft jede URL der Sitemap der Live-Seite und jede Weiterleitung
# (bin/check-urls.php). Ohne SITE prüft es make dev, das dafür in einem
# zweiten Terminal laufen muss. Gegen die Live-Seite:
# make check-urls SITE=$(LIVE_URL).
SITE ?= http://localhost:$(PORT)
.PHONY: check-urls
check-urls:
	@$(PHP) bin/check-urls.php $(SITE)

# make pull erreicht den Server über den SSH-Alias website. Den Ordner auf
# dem Server nennt die lokale Datei .env. Sie ist nicht in Git, weil der Pfad
# das Hosting-Konto nennt:
#   LIVE_PATH=/www/htdocs/<konto>/sven.eisenschmidt.website
# Ein Deploy läuft nur über den Workflow CI.
LIVE_HOST ?= website
-include .env
LIVE_URL ?= https://sven.eisenschmidt.website

.PHONY: live-path
live-path:
	@test -n "$(LIVE_PATH)" || { echo "LIVE_PATH fehlt. Der Kommentar über LIVE_HOST im Makefile zeigt, was in .env steht." >&2; exit 1; }
	@test "$(notdir $(LIVE_PATH))" = sven.eisenschmidt.website || { echo "LIVE_PATH zeigt nicht auf den Ordner sven.eisenschmidt.website." >&2; exit 1; }

# Ersetzt Inhalte und Konten in www/ durch den Stand des Servers.
.PHONY: pull
pull: live-path
	DEPLOY_HOST=$(LIVE_HOST) DEPLOY_PATH=$(LIVE_PATH) bin/pull.sh

# Ruft jede Seite der Live-Seite und jedes Vorschaubild unter /media/ einmal
# ab, damit Kirby sie vor dem ersten Besuch erzeugt (bin/warm.php).
.PHONY: warm
warm:
	@$(PHP) bin/warm.php $(LIVE_URL)
