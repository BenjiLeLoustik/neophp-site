# neophp-site

The website of the NeoPHP framework: https://neophp.fr.
Built with NeoPHP, Twig and Tailwind CSS; the documentation is read from the `vX.x` branches of the framework repository.

## Summary

- [Requirements](#requirements)
- [Installation](#installation)
- [Features](#features)
- [Documentation](#documentation)
- [Analytics](#analytics)
- [Deployment](#deployment)

## Requirements

- PHP 8.2 or higher with `pdo_mysql`, `pdo_sqlite`, `dom` and `mbstring`
- Composer, Git
- MySQL / MariaDB

## Installation

```bash
git clone git@github.com:BenjiLeLoustik/neophp-site.git
cd neophp-site
composer install
```

`.env.local`:

```dotenv
DATABASE_URL="mysql://root:@127.0.0.1:3306/neophp_site?charset=utf8mb4"
ADMIN_PASSWORD='hash from php bin/neo security:hash-password'
```

```bash
php bin/neo database:create --if-not-exists
php bin/neo analytics:install
php bin/neo docs:sync
php bin/neo tailwind:install
php bin/neo tailwind:run --watch
php bin/neo serve
```

## Features

| Page | Route |
|---|---|
| Home page (features, code sample, ecosystem, latest releases) | `/` |
| Documentation catalog with instant search | `/docs`, `/docs/{version}/all` |
| Guide | `/docs/{version}` |
| Feature documentation | `/docs/{version}/{group}/{slug}` |
| Analytics dashboard (admin) | `/analytics` |
| Error page preview (debug only) | `/_error/{code}` |

Icons are inline [Lucide](https://lucide.dev) SVGs (`templates/_partials/icons.html.twig`): `{{ ui.icon('name', 'size-5') }}`.

## Documentation

`php bin/neo docs:sync` mirrors the framework repository (`FRAMEWORK_REPOSITORY` in `.env`) into `var/framework/` and exports the `docs/` and `src/` folders of every `vX.x` branch into `var/framework/vX.x/`. The most recent version is the default one.

Without synchronized docs, the website reads `vendor/neophp/framework`.

Framework branches:

- `main`: stable version, tagged and published on Packagist (used by `composer.json`)
- `dev`: version in progress, never published on the website
- `vX.x`: documentation of each version

## Analytics

Page views are stored in SQLite (connection `analytics`, `var/analytics.db`) by a listener of `TerminateEvent`, after the response is sent. No IP address and no cookie: the visitor is a daily hash of the IP, the user agent and `APP_SECRET`. Bots, assets and the admin pages are ignored.

The dashboard `/analytics` requires the `ROLE_ADMIN` user defined in `config/packages/security.yaml`.

## Deployment

The server runs `deploy.sh`, which clones this repository, builds a new release and switches to it when every step succeeds:

1. `composer install --no-dev`
2. migrations, `tailwind:run --minify`, `asset:reload --minify`
3. `.deploy/post-deploy.sh`: `analytics:install`, `docs:sync` and a cron job running `docs:sync` every 15 minutes
4. switch of `current`, `cache:clear`, PHP-FPM reload

`.env.local` and `var/` are shared between the releases. To publish a change: push on `master`, then run `./deploy.sh` on the server.