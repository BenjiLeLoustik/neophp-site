# neophp-site

The website of the NeoPHP framework: https://neophp.fr.
Built with NeoPHP 2, Twig and Tailwind CSS; the documentation is read from the `vX.x` branches of the framework repository.

## Summary

- [Requirements](#requirements)
- [Installation](#installation)
- [Features](#features)
- [Documentation](#documentation)
- [NeoIDE and marketplace](#neoide-and-marketplace)
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
ANALYTICS_DATABASE_URL="sqlite:///%kernel.root_path%/var/analytics.db"
ADMIN_PASSWORD='hash from php bin/neo security:hash-password'
```

```bash
php bin/neo database:create --if-not-exists
php bin/neo analytics:install
php bin/neo docs:sync
php bin/neo marketplace:sync
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
| NeoIDE download page | `/neoide` |
| NeoIDE download (counted, redirects to the `.exe` of the latest GitHub release) | `/neoide/download` |
| Marketplace of NeoIDE plugins and themes | `/marketplace` |
| Extension page (`plugin-git-tools` => `/marketplace/plugins/git-tools`) | `/marketplace/{plugins\|themes}/{name}` |
| Extension download (counted, .zip) | `/marketplace/{plugins\|themes}/{name}/download/{version\|latest}` |
| API read by NeoIDE | `/api/v1/...` |
| Analytics dashboard (admin) | `/analytics` |
| Downloads dashboard (admin) | `/admin/downloads` |
| Error page preview (debug only) | `/_error/{code}` |

Icons are inline [Lucide](https://lucide.dev) SVGs (`templates/_partials/icons.html.twig`): `{{ ui.icon('name', 'size-5') }}`.

## Documentation

`php bin/neo docs:sync` mirrors the framework repository (`FRAMEWORK_REPOSITORY` in `.env`) into `var/framework/` and exports the `docs/` and `src/` folders and the `CHANGELOG.md` file of every `vX.x` branch into `var/framework/vX.x/`. The most recent version is the default one; the latest releases of the home page are read from its `CHANGELOG.md`.

Without synchronized docs, the website reads `vendor/neophp/framework`.

Framework branches:

- `main`: stable version, tagged and published on Packagist (used by `composer.json`)
- `dev`: version in progress, never published on the website
- `vX.x`: documentation of each version (`v1.x`, `v2.x`...)

## NeoIDE and marketplace

**NeoIDE** is downloaded from the latest release of [NeoPHP-Framework/neo-ide](https://github.com/NeoPHP-Framework/neo-ide) (`NEOIDE_REPOSITORY`): the `.exe` attached to it (`neoide.exe` first). No release, or no `.exe` in it = NeoIDE is not available. `marketplace:sync` reads the GitHub API and stores the release in `var/marketplace/neoide.json`; when GitHub does not answer, the last known release is kept.

**The marketplace** is [NeoPHP-Framework/neo-ide-marketplace](https://github.com/NeoPHP-Framework/neo-ide-marketplace) (`MARKETPLACE_REPOSITORY`). Every folder at its root named `plugin-<name>` is a plugin, `theme-<name>` a theme (lowercase letters, digits and dashes); the folder name is the id of the extension.

```
plugin-git-tools/
├── manifest.json     required
├── README.md         optional: the page of the extension (raw HTML escaped)
├── icon.png          optional, square
├── screenshots/      optional: .png / .jpg / .webp
└── ...               the files of the plugin, packaged as they are
```

```json
{
    "version": "1.0.0",
    "name": "Git Tools",
    "description": "One sentence shown in the catalog.",
    "author": "NeoPHP",
    "tags": ["git"],
    "license": "MIT",
    "homepage": "https://...",
    "ide": ">=0.4 <1.0",
    "changelog": "What changed in this version."
}
```

Only `version` is required (semantic version). `name` defaults to the folder name, `author` to NeoPHP, `ide` (NeoIDE versions supported) to `*`: `0.4.2`, `>=0.4 <1.0`, `>=0.4 <0.6 || >=1.0`.

`php bin/neo marketplace:sync` mirrors the repository into `var/marketplace/`, exports its `plugin-*` / `theme-*` folders into `var/marketplace/current/` and, for every new `version`, builds the `.zip` of the folder in `var/marketplace/packages/<folder>/<version>.zip` with its SHA-256: the previous versions stay downloadable. To publish a change, increase `version` (the command warns when the content changed without a new version).

API for NeoIDE (JSON, `ETag` + `Cache-Control: max-age=300`, errors in `application/problem+json`; NeoIDE should send `User-Agent: NeoIDE/x.y.z` so its downloads are counted as `ide`):

| Method | Route | |
|---|---|---|
| GET | `/api/v1/extensions?type=plugin\|theme&q=&ide=0.4.0` | Catalog; with `ide`, only the extensions with a compatible version, `latest` being the latest compatible one |
| GET | `/api/v1/extensions/{id}` | One extension (`plugin-git-tools`) with every version |
| POST | `/api/v1/updates` | Body `{"ide": "0.4.0", "installed": {"plugin-git-tools": "1.1.0"}}`: the NeoIDE release when newer than `ide`, and the extension updates |
| GET | `/api/v1/neoide` | The latest NeoIDE release: `version`, `date`, `notes`, `url`, `size`, `sha256` |

The "Install in NeoIDE" button opens `neoide://install/{id}`: NeoIDE registers this URL scheme at install time, then reads `/api/v1/extensions/{id}`, downloads the compatible version and checks its SHA-256 (also sent in the `X-Checksum-Sha256` header of the download).

## Analytics

Page views are stored in SQLite (connection `analytics`, `ANALYTICS_DATABASE_URL`, `var/analytics.db`) by a listener of `TerminateEvent`, after the response is sent. Downloads of NeoIDE and of the extensions go into the `download` table of the same database. No IP address and no cookie: the visitor is a daily hash of the IP, the user agent and `APP_SECRET`. Bots, assets and the admin pages are ignored.

The dashboard `/analytics` requires the `ROLE_ADMIN` user defined in `config/packages/security.yaml`.

## Deployment

The server runs `deploy.sh`, which clones this repository, builds a new release and switches to it when every step succeeds:

1. `composer install --no-dev`
2. migrations, `tailwind:run --minify`, `asset:reload --minify`
3. `.deploy/post-deploy.sh`: `analytics:install`, `docs:sync`, `marketplace:sync` and a cron job running both syncs every 15 minutes
4. switch of `current`, `cache:clear`, PHP-FPM reload

`.env.local` and `var/` are shared between the releases. To publish a change: push on `master`, then run `./deploy.sh` on the server.