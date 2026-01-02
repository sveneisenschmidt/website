.PHONY: dev build

dev:
	hugo server --buildDrafts

build:
	rm -rf public/*
	hugo --minify
