.PHONY: dev build publish

include .env
export

dev:
	hugo server --buildDrafts

build:
	rm -rf public/*
	hugo --minify

publish: build
	git add -A
	git commit -m "Update site $$(date +%Y-%m-%d\ %H:%M)"
	git push origin main
	lftp -e "set ssl:verify-certificate no; mirror -R --delete public/ $(FTP_PATH); quit" -u $(FTP_USER),$(FTP_PASS) $(FTP_HOST)
