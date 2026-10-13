# sven.eisenschmidt.website

My personal website, built with [Kirby](https://getkirby.com/).

## Content

Posts, pages, images and accounts are state on the server. They are never in git. I write in the Panel at `/panel`. Git holds the code: blueprints, templates, plugins, the classes in `src/` (namespace `Website\`: the page models and the blog) and the scripts in `bin/`.

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
make format
make analyse
make test
```

`make format` formats the code to PER-CS 2.0, `make analyse` runs PHPStan. The workflow `CI` checks all four for every pull request and every push to `main`. Dependabot opens one pull request a week each for Composer packages and actions; CI merges it when the checks pass and deploys it.

## Deploy

Every push to `main` deploys: the workflow `CI` runs the checks, then the deploy, on a self-hosted runner. There is no deploy by hand.

The deploy builds the code once and uploads it as a new release next to the old ones. The site folder on the server holds:

```
.htaccess        names the live release
releases/<id>/   the code of a release: www/, vendor/
shared/          the state: content, media, accounts, cache, sessions, env.php, .salt, storage
backups/         snapshots of the state
```

A release links its state paths to `shared/`. The switch replaces `.htaccess` in one rename. If the start page then does not answer 200, the site switches back to the release before and the deploy fails. The newest 5 releases stay. To go back to an older version, run the deploy of its commit again in GitHub Actions.

`bin/deploy-test.sh` runs the deploy against a folder on this machine, a way back included; CI runs it too.

The workflow reads the host key of the server from the secret `DEPLOY_KNOWN_HOSTS` (the output of `ssh-keyscan` for the deploy host) and the key of the Panel assistant from the secret `ANTHROPIC_API_KEY`.

`make pull` reads the server folder from a local `.env`, which is not in git:

```
LIVE_PATH=/www/htdocs/<account>/sven.eisenschmidt.website
```

## Backups

Every deploy and every `make pull` takes a snapshot of the live state on the server. The deploy keeps the newest 30. `make backup` writes the local state to `backups/`. `make restore BACKUP=backups/website-....tar.gz` puts it back. `make restore-test` proves that a backup restores the same site.

## License

Content is mine. Do what you want with the code.
