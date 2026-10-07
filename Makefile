.PHONY: dev build publish check-deps check-hugo srgb logs

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

push: check-hugo srgb
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
