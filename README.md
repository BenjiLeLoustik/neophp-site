# NeoPHP Skeleton

Creates a new [NeoPHP](https://github.com/NeoPHP-Framework/neophp) application.

## Requirements

- PHP 8.2 or higher
- Composer 2

## Installation

```bash
composer create-project neophp/skeleton my-project
cd my-project
php bin/neo serve
```

Open http://127.0.0.1:8000.

## Generated files

```
.env
.gitignore
assets/
bin/neo
config/routes.yaml
config/framework/app.yaml
config/packages/
public/.htaccess
public/index.php
public/builds/
src/Kernel.php
src/Controller/HomeController.php
templates/base.php
templates/home/index.php
tests/
```

## Console

| Command | Description |
|---|---|
| `php bin/neo` | lists the commands |
| `php bin/neo serve` | starts the development server |
| `php bin/neo route:list` | lists the routes |
| `php bin/neo install` | regenerates the missing project files |

## Documentation

https://github.com/NeoPHP-Framework/neophp/blob/v1.x/docs/v1.x/README.md

## License

MIT