.PHONY: dev build publish check-deps logs

check-deps:
	@command -v hugo >/dev/null 2>&1 || { echo "hugo is required but not installed. Install with: brew install hugo"; exit 1; }
	@command -v sips >/dev/null 2>&1 || { echo "sips is required but not installed (comes with macOS)"; exit 1; }
	@command -v fswatch >/dev/null 2>&1 || { echo "fswatch is required but not installed. Install with: brew install fswatch"; exit 1; }
	@command -v npx >/dev/null 2>&1 || { echo "npx is required but not installed. Install Node.js first"; exit 1; }

dev: check-deps
	@trap 'kill 0' EXIT; ./scripts/convert-heic.sh --watch & hugo server --buildDrafts --buildFuture

build:
	rm -rf public/*
	hugo --minify
	npx pagefind --site public --glob 'posts/*/**/*.html'
	rm -f public/pagefind/pagefind-ui.* public/pagefind/pagefind-modular-ui.* public/pagefind/pagefind-highlight.js

push:
	git add -A
	git commit -m "Update site $$(date +%Y-%m-%d\ %H:%M)"
	git push origin main

pull:
	git fetch --all
	git checkout main


logs:
	@command -v goaccess >/dev/null 2>&1 || { echo "goaccess is required but not installed. Install with: brew install goaccess"; exit 1; }
	@mkdir -p logs
	@echo "Fetching logs from server..."
	@ssh website 'zcat /www/htdocs/*/logs/access_log_sven_eisenschmidt_website*.gz' > logs/access.log
	@echo "Analyzing logs with GoAccess..."
	@goaccess logs/access.log -o logs/report.html --log-format='%h - - [%d:%t %^] "%r" %s %b "%R" "%u" "%^" "%^"' --date-format='%d/%b/%Y' --time-format='%H:%M:%S'
	@echo "Report generated: logs/report.html"
	@open logs/report.html
