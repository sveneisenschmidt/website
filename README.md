# Website

My personal website, built with [Kirby](https://getkirby.com/).

## Content

Posts, pages, images and accounts are state on the server. They are never in git. I write in the Panel at `/panel`. Git holds the code: blueprints, templates, plugins, the classes in `src/` (namespace `Website\`: the page models, the blog and the Panel assistant) and the scripts in `bin/`.

## Local setup

Needs PHP 8.5 (see `.php-version`), Composer and the SSH alias `website`.

```bash
make setup
make pull
make dev
```

`make setup` runs `composer install`, creates `.env` from `.env.example` and the local `.salt`, and installs git hooks. `.env` holds every local value and key under the same names as the secrets and variables of the workflows: `SITE_URL`, `DEPLOY_HOST`, `DEPLOY_PATH`, `DEBUG`, `ANTHROPIC_API_KEY` and `PAGESPEED_API_KEY`. make passes them to every target, and Kirby reads `DEBUG` and `ANTHROPIC_API_KEY` from there. `make speed` measures the live site with PageSpeed Insights. The hooks stop a commit or push that contains text from the prompt of the Panel assistant. `make pull` replaces the content and accounts in `www/` with the state of the server. `make dev` serves the site at http://localhost:8010; `make` alone does the same.

## Tests

```bash
make lint
make format
make analyse
make test
```

`make format` formats the code to PER-CS 2.0, `make analyse` runs PHPStan. The workflow `CI` checks all four for every pull request and every push to `main`. Dependabot opens one pull request a week for the Composer packages and one for the GitHub Actions. CI merges such a pull request when the checks pass, and deploys it.

## Deploy

Every push to `main` deploys. The workflow `CI` runs the checks and then `bin/deploy.sh` on a self-hosted runner, a machine outside GitHub. There is no deploy by hand.

The site runs on shared hosting at All-Inkl with Apache and PHP 8.5. A deploy builds the code once and uploads it as a new release next to the old ones. A release is the code of one deploy. The site folder on the server holds:

```
.htaccess        sends every request to the live release
current          a symlink to the live release, for the scripts
releases/<id>/   the releases: www/, src/, vendor/
shared/          the files that no deploy replaces: state, env.php, generated files, uploads
backups/         the snapshots of the state
```

The state is what only a backup can restore: content, accounts, `.salt` and `.license`. `env.php` is the configuration of one host. Kirby makes the generated files (media, cache, sessions) again from the state. The uploads are the files of the Panel assistant's chats.

A release has a symlink into `shared/` for each of these paths. The switch replaces `.htaccess` in one rename. Then a health check requests the start page. If it does not answer 200, the site switches back to the previous release and the deploy fails. The newest 5 releases stay. To go back to an older version, run the deploy of its commit again in GitHub Actions.

After every deploy the workflow `Speed` measures the live site with PageSpeed Insights (`bin/pagespeed.php`, key in the secret `PAGESPEED_API_KEY`) and shows the scores on the page of the run.

`bin/deploy-test.sh` runs the deploy against a folder on this machine, a switch back after a failed health check included. CI runs it too.

The workflow reads the host key of the server from the secret `DEPLOY_KNOWN_HOSTS` (the output of `ssh-keyscan` for the deploy host) and the key of the Panel assistant from the secret `ANTHROPIC_API_KEY`. The URL of the site comes from the repository variable `SITE_URL`.

`make pull` reads `SITE_URL`, `DEPLOY_HOST` and `DEPLOY_PATH` from `.env`. The folder on the server must have the name of the host of `SITE_URL`.

## Backups

Every deploy and every `make pull` makes a snapshot on the server: a copy of the live state in `backups/`. A file that has not changed since the previous snapshot is a hard link to it and takes no new space. The deploy keeps the newest 30 snapshots. `make backup` writes the local state to an archive in `backups/`. `make restore BACKUP=backups/website-....tar.gz` puts it back. `make restore-test` checks that a backup restores the same site.

## License

Content is mine. Do what you want with the code.
