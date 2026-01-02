.PHONY: dev build deploy

dev:
	hugo server --buildDrafts

build:
	rm -rf public/*
	hugo --minify

deploy: build
	git add -A
	git commit -m "Update site $$(date +%Y-%m-%d\ %H:%M)"
	git push origin main
