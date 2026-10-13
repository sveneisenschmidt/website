# sven.eisenschmidt.website

My personal website, built with [Kirby](https://getkirby.com/).

## Content

Posts, pages, images and accounts are state on the server. They are never in git. I write in the Panel at `/panel`. Git holds the code: blueprints, templates, plugins and the scripts in `bin/`.

## Local setup

Needs PHP 8.5 (see `.php-version`), Composer and the SSH alias `website`.

```bash
make setup
make pull
make dev
```

`make setup` runs `composer install`, creates the local `env.php` and installs git hooks that keep the prompt of the Panel assistant out of commits and pushes. `make pull` replaces the content and accounts in `www/` with the state of the server. `make dev` serves the site at http://localhost:8010; `make` alone does the same.

## Tests

```bash
make lint
make test
```

The workflow `Test` runs both for every pull request.

## Deploy

Every push to `main` deploys: the workflow `Deploy` runs on a self-hosted runner. By hand:

```bash
make deploy
```

The workflow runs lint and tests first; `make deploy` by hand runs neither. The deploy takes a snapshot of the live state, then uploads the code. It never touches the content. At the end the start page must answer 200, and every page is requested once to fill the page cache.

The workflow reads the host key of the server from the secret `DEPLOY_KNOWN_HOSTS` (the output of `ssh-keyscan` for the deploy host).

`make deploy` and `make pull` read the server folder from a local `.env`, which is not in git:

```
LIVE_PATH=/www/htdocs/<account>/sven.eisenschmidt.website
```

## Backups

Every deploy and every `make pull` takes a snapshot of the live state on the server. The deploy keeps the newest 30. `make backup` writes the local state to `backups/`. `make restore BACKUP=backups/website-....tar.gz` puts it back. `make restore-test` proves that a backup restores the same site.

## License

Content is mine. Do what you want with the code.
