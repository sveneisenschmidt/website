.PHONY: dev build publish

include .env
export

dev:
	hugo server --buildDrafts

build:
	rm -rf public/*
	hugo --minify
	npx pagefind --site public --glob 'posts/*/**/*.html'
	rm -f public/pagefind/pagefind-ui.* public/pagefind/pagefind-modular-ui.* public/pagefind/pagefind-highlight.js

publish: build
	git add -A
	git commit -m "Update site $$(date +%Y-%m-%d\ %H:%M)"
	git push origin main
	lftp -e "mirror -R --delete public/ $(FTP_PATH); quit" -u $(FTP_USER),$(FTP_PASS) $(FTP_HOST)
