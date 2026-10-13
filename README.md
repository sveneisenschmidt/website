# sven.eisenschmidt.website

My personal website, built with [Kirby](https://getkirby.com/).

## Content

Posts, pages, images and accounts are state on the server. They are never in git. I write in the Panel at `/panel`. Git holds the code: blueprints, templates, plugins and the scripts in `bin/`.

## Local setup

Needs PHP 8.5 (see `.php-version`), Composer and the SSH alias `website`.

```bash
composer install
make kirby-config
make kirby-pull
make kirby-dev
```

`make kirby-config` creates the local `env.php`. `make kirby-pull` replaces the content and accounts in `www/` with the state of the server. `make kirby-dev` serves the site at http://localhost:8010.

## Tests

```bash
make kirby-lint
make kirby-test
```

`make kirby-hooks` installs a git hook that keeps the prompt of the Panel assistant out of commits.

## Deploy

```bash
make kirby-deploy
```

Lint and tests run first. The deploy takes a snapshot of the live state, then uploads the code. It never touches the content.

## Backups

Every deploy and every `make kirby-pull` takes a snapshot of the live state on the server. The deploy keeps the newest 30. `make kirby-backup` writes the local state to `backups/`. `make kirby-restore BACKUP=backups/website-....tar.gz` puts it back. `make kirby-restore-test` proves that a backup restores the same site.

## License

Content is mine. Do what you want with the code.
