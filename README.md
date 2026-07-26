# Frumle - AI-Powered Codebase Analyzer for PHP

Analyze your PHP codebase with AI and generate comprehensive API documentation. Supports **Laravel**, **Symfony**, **CodeIgniter 4+**, **CakePHP**, **Yii**, **Laminas (Zend)**, **Slim**, and **Phalcon**.

## Installation

```bash
composer require --dev frumle/frumle
```

> **Note:** Avoid `composer global require` if you also use Frumle for JavaScript, Python, or C# — global installs from different package managers can conflict. Use `vendor/bin/frumle` instead.

## Quick Start

```bash
# 1. Add your API key (get it from the Frumle dashboard)
vendor/bin/frumle add-key <your-api-key>

# Or set an environment variable (useful for CI — no add-key needed):
export FRUMLE_API_KEY=frumle_…
vendor/bin/frumle

# 2. Analyze your project
vendor/bin/frumle

# 3. View results at https://frumle.com
```

`FRUMLE_API_KEY` overrides the key stored in `~/.frumle/config.json`.

## Commands

### `vendor/bin/frumle [directory]`

Analyze a codebase. Defaults to the current directory.

```bash
vendor/bin/frumle                              # Analyze current directory
vendor/bin/frumle ./src                        # Analyze specific directory
vendor/bin/frumle --project-name my-api        # Custom project name
vendor/bin/frumle --ignore tests,storage       # Ignore specific directories
```

**CI / unattended (env key, no local config write, JSON output):**

```bash
export FRUMLE_API_KEY=frumle_…
vendor/bin/frumle . --project-name "my-api" --skip-config-write --json
```

When `CI=true` (GitHub Actions sets this), Frumle skips writing `frumle.config.json` automatically.

### `vendor/bin/frumle add-key <api-key>`

Add or update your API key. Verifies the key with the server before saving.

```bash
vendor/bin/frumle add-key frumle_abc123...
```

### `vendor/bin/frumle login <api-key>`

Alias for `add-key`.

### `vendor/bin/frumle status`

Check your API key status, quota, and usage statistics.

```bash
vendor/bin/frumle status
```

### `vendor/bin/frumle ci [--force] [directory]`

Add `.github/workflows/frumle.yml` so docs regenerate on every push to `main`/`master`.

```bash
vendor/bin/frumle ci              # Create the workflow
vendor/bin/frumle ci --force      # Overwrite an existing frumle.yml
```

**Auto-docs on every push (GitHub Action):**

1. Run `vendor/bin/frumle` once — when asked, choose **Y** to add `.github/workflows/frumle.yml`  
   (or run `vendor/bin/frumle ci` anytime)
2. Set the secret:

```bash
gh secret set FRUMLE_API_KEY
```

3. Commit and push the workflow file

After that, every push to `main`/`master` queues a fresh analysis. Docs show up in your [dashboard](https://frumle.com/dashboard).  
A green Actions run means the scan was **queued** — not that docs finished instantly.

If you choose **n** at the prompt, you’ll need to run `frumle` yourself whenever docs should update.

## Options

| Flag | Description |
|------|-------------|
| `--project-name <name>` | Project name (defaults to `composer.json` name or directory) |
| `--ignore <dirs>` | Comma-separated directories to ignore |
| `--skip-config-write` | Do not write/update `frumle.config.json` (also default when `CI=true`) |
| `--json` | Print a single JSON result (implies quiet) |
| `--quiet` | Suppress human-readable progress logs |

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | Analysis queued or completed |
| `1` | Client / unexpected error (bad path, no files, etc.) |
| `2` | Auth failure (missing or invalid API key) |
| `3` | Quota or payment required |

## Supported Frameworks

| Framework       | Version | Auto-Detection           |
|-----------------|---------|--------------------------|
| Laravel         | 5.x+    | `artisan`, `routes/api.php` |
| Symfony         | 4.x+    | `config/packages/`, routing annotations |
| CodeIgniter     | 4.x+    | `app/Config/App.php`     |
| CakePHP         | 4.x+    | `src/Application.php`    |
| Yii             | 2.x+    | `config/web.php`         |
| Laminas (Zend)  | 3.x+    | `config/autoload/`       |
| Slim            | 4.x+    | `Slim\App` in `public/index.php` |
| Phalcon         | 4.x+    | `.htrouter.php`          |

## What Gets Analyzed

The scanner collects these file types from your project:

- `.php` — PHP source files (including `.blade.php` templates)
- `.json` — Configuration files (composer.json, etc.)
- `.yaml` / `.yml` — Symfony routes, config, Docker, etc.
- `.xml` — Configuration files
- `.env` — Environment configuration
- `.twig` — Twig templates
- `.neon` — Nette configuration
- `.ini` — PHP configuration
- `.md` — Documentation

### Default Ignored Directories

`vendor`, `node_modules`, `.git`, `storage`, `cache`, `var`, `tmp`, `temp`, `logs`, `dist`, `build`, `runtime`, `assets`

## Configuration

### API Key

Auth: `FRUMLE_API_KEY` env (preferred), else `~/.frumle/config.json` from `add-key`. Shared across all Frumle tools (npm, Python, Maven, PHP).

### Project Configuration

A `frumle.config.json` file is created in your project root with detected base URLs:

```json
{
  "baseUrls": [
    {
      "environment": "local",
      "url": "http://localhost:8000"
    },
    {
      "environment": "production",
      "url": ""
    }
  ]
}
```

The local URL is auto-detected from:
- `.env` file (`APP_URL`, `APP_PORT`, `PORT`)
- Framework-specific config files
- Common framework defaults

Edit the `production` URL to enable production API testing in the dashboard.

## Framework Examples

### Laravel

```bash
cd /path/to/laravel-project
vendor/bin/frumle
```

Frumle detects Laravel via `artisan` and scans routes, controllers, models, middleware, and more.

### Symfony

```bash
cd /path/to/symfony-project
vendor/bin/frumle
```

Detects Symfony via `config/packages/` and scans controllers with routing attributes/annotations.

### CodeIgniter 4

```bash
cd /path/to/codeigniter4-project
vendor/bin/frumle
```

Detects CodeIgniter via `app/Config/App.php` and scans controllers with defined routes.

### Slim Framework

```bash
cd /path/to/slim-project
vendor/bin/frumle
```

Detects Slim via `public/index.php` and scans route definitions and middleware.

## Requirements

- PHP 8.0+
- `ext-curl`
- `ext-json`

## Environment Variables

| Variable         | Description                                      | Default                                      |
|------------------|--------------------------------------------------|----------------------------------------------|
| `FRUMLE_API_KEY` | API key (overrides `~/.frumle/config.json`)      | —                                            |
| `FRUMLE_API_URL` | Backend API URL override (testing / self-host)   | production default (built into CLI)          |
| `CI`             | When `true`/`1`, skip writing `frumle.config.json` | —                                         |

## License

MIT
