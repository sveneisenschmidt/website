.PHONY: dev build publish check-deps

include .env
export

check-deps:
	@command -v hugo >/dev/null 2>&1 || { echo "hugo is required but not installed. Install with: brew install hugo"; exit 1; }
	@command -v sips >/dev/null 2>&1 || { echo "sips is required but not installed (comes with macOS)"; exit 1; }
	@command -v fswatch >/dev/null 2>&1 || { echo "fswatch is required but not installed. Install with: brew install fswatch"; exit 1; }
	@command -v npx >/dev/null 2>&1 || { echo "npx is required but not installed. Install Node.js first"; exit 1; }
	@command -v lftp >/dev/null 2>&1 || { echo "lftp is required but not installed. Install with: brew install lftp"; exit 1; }

dev: check-deps
	@trap 'kill 0' EXIT; ./scripts/convert-heic.sh --watch & hugo server --buildDrafts

build:
	rm -rf public/*
	hugo --minify
	npx pagefind --site public --glob 'posts/*/**/*.html'
	rm -f public/pagefind/pagefind-ui.* public/pagefind/pagefind-modular-ui.* public/pagefind/pagefind-highlight.js

publish: build
	git add -A
	git commit -m "Update site $$(date +%Y-%m-%d\ %H:%M)"
	git push origin main
	lftp -e "set ssl:verify-certificate no; mirror -R --delete --parallel=10 public/ $(FTP_PATH); quit" -u $(FTP_USER),$(FTP_PASS) $(FTP_HOST)
