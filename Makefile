WWW := www
# make without a target serves the site locally.
.DEFAULT_GOAL := dev
# PHP for every target. The server runs PHP 8.5 (.php-version), and the
# local PHP should be the same version. make PHP=... picks another PHP.
PHP ?= php
# The port of make dev. Another local project uses port 8000.
PORT := 8010

# Local values and keys from .env (template: .env.example). make passes the
# values to every target as environment variables, and each key only to the
# target that uses it: ANTHROPIC_API_KEY to dev, PAGESPEED_API_KEY to speed.
-include .env
export SITE_URL DEPLOY_HOST DEPLOY_PATH DEBUG

# Once after cloning: dependencies, .env and .salt, git hooks. Then fill in
# the values in .env, and make pull fetches the content from the server.
.PHONY: setup
setup:
	composer install
	@$(MAKE) --no-print-directory config hooks

.PHONY: config
config:
	@$(PHP) bin/init-config.php $(WWW)

.PHONY: test
test:
	@test -x vendor/bin/phpunit || { echo "PHPUnit is missing. Run composer install first."; exit 1; }
	@$(PHP) vendor/bin/phpunit

# localhost only, never 0.0.0.0: the PHP manual says the built-in server is
# not meant for a public network, and Kirby runs in debug mode locally.
# The upload limits fit the assistant: up to 60 MB per file, and the
# preview of a 64-megapixel photo needs about 320 MB of memory
# (src/Assistant/Uploads.php).
# bin/dev-router.php blocks content/, site/ and kirby/ as www/.htaccess does.
# The page cache holds the HTML of every page. Kirby clears it on every
# change in the Panel, but not on a change of the code, so make dev clears
# it at the start.
.PHONY: dev
dev: export ANTHROPIC_API_KEY := $(ANTHROPIC_API_KEY)
dev:
	@test -f vendor/autoload.php || { echo "Kirby is missing. Run composer install first."; exit 1; }
	@test -f .env || { echo ".env is missing. Run make config first."; exit 1; }
	@test -d $(WWW)/content || { echo "The content is missing. Run make pull first."; exit 1; }
	@$(PHP) -r 'if (PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION !== trim(file_get_contents(".php-version"))) fwrite(STDERR, "This is PHP ".PHP_VERSION.", .php-version asks for ".trim(file_get_contents(".php-version")).".\n");'
	@rm -rf $(WWW)/site/cache/localhost_$(PORT)
	@echo "Site: http://localhost:$(PORT)  Panel: http://localhost:$(PORT)/panel  (stop with Ctrl+C)"
	@set -o pipefail; PHP_CLI_SERVER_WORKERS=4 $(PHP) -d upload_max_filesize=64M -d post_max_size=64M -d memory_limit=1024M -S localhost:$(PORT) -t $(WWW) bin/dev-router.php 2>&1 | grep --line-buffered -v -E '(Accepted|Closing)$$'

.PHONY: backup
backup:
	bin/backup.sh $(WWW) backups

.PHONY: restore
restore:
	@test -n "$(BACKUP)" || { echo "Usage: make restore BACKUP=backups/website-....tar.gz"; exit 1; }
	bin/restore.sh $(BACKUP) $(WWW)

.PHONY: restore-test
restore-test:
	PHP=$(PHP) bin/restore-test.sh $(WWW)

.PHONY: lint
lint:
	@find src $(WWW)/site $(WWW)/index.php bin tests -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null && echo "lint ok"

# Formats the code to PER-CS 2.0 (.php-cs-fixer.dist.php). The workflow CI
# checks the format.
.PHONY: format
format:
	@$(PHP) vendor/bin/php-cs-fixer fix --show-progress=none

# Static analysis with PHPStan (phpstan.neon). The workflow CI runs it too.
.PHONY: analyse
analyse:
	@$(PHP) vendor/bin/phpstan analyse --no-progress

# Installs the git hooks pre-commit and pre-push. They stop a commit or push
# that contains text from the assistant's prompt (bin/prompt-guard.php). The
# hooks run on every branch, main included. make hooks copies
# bin/prompt-guard.php and src/Assistant/Words.php into the hooks folder of
# git, so run it again after a change of either file.
.PHONY: hooks
hooks:
	@hooks="$$(git rev-parse --git-path hooks)"; \
	for hook in pre-commit pre-push; do \
	  if [ -f "$$hooks/$$hook" ] && ! grep -q prompt-guard.php "$$hooks/$$hook"; then echo "$$hooks/$$hook exists already and stays as it is."; exit 1; fi; \
	done; \
	cp bin/prompt-guard.php "$$hooks/prompt-guard.php"; \
	cp src/Assistant/Words.php "$$hooks/prompt-words.php"; \
	for hook in pre-commit pre-push; do \
	  printf '#!/bin/sh\n# Written by make hooks. Stops a commit or push with text from the assistant prompt.\nexec %s "$$(git rev-parse --git-path hooks)/prompt-guard.php" %s\n' "$(PHP)" "$$hook" > "$$hooks/$$hook"; \
	  chmod +x "$$hooks/$$hook"; \
	done; \
	echo "The hooks pre-commit and pre-push are installed."

# Checks every URL of the sitemap of the live site and every redirect
# (bin/check-urls.php). Without SITE it checks make dev, which must run in a
# second terminal. Against the live site: make check-urls SITE=$(SITE_URL).
SITE ?= http://localhost:$(PORT)
.PHONY: check-urls
check-urls: site-url
	@$(PHP) bin/check-urls.php $(SITE) $(SITE_URL)/sitemap.xml

.PHONY: site-url
site-url:
	@test -n "$(SITE_URL)" || { echo "SITE_URL is missing in .env (template: .env.example)." >&2; exit 1; }

# Replaces the content and accounts in www/ with the state of the server
# (bin/pull.sh). A deploy runs only in the workflow CI.
.PHONY: pull
pull:
	bin/pull.sh

# Requests every page of the live site and every thumbnail under /media/
# once, so Kirby creates them before the first visit (bin/warm.php).
.PHONY: warm
warm: site-url
	@$(PHP) bin/warm.php $(SITE_URL)

# Measures the live site with PageSpeed Insights (bin/pagespeed.php). It
# needs PAGESPEED_API_KEY in .env.
.PHONY: speed
speed: export PAGESPEED_API_KEY := $(PAGESPEED_API_KEY)
speed: site-url
	@$(PHP) bin/pagespeed.php $(SITE_URL)
