# AGENTS.md

Personal RSS feed reader. Single PHP app (no framework) backed by MySQL.

## Architecture

- `index.php` is the web entrypoint: bootstraps `AppContainer` (singleton in
  `src/AppContainer.php`) and runs `FeedController::execute()`.
- `bin/import_items.php` is the CLI entrypoint that forces a feed import
  regardless of `next_read` schedule. Use `-q` for quiet output.
- PSR-4: namespace `DouglasGreen\FeedReader\` maps to `src/`. Only
  `src/AppContainer.php` and `src/Controller/{FeedController,ImportController}.php`.
- All Twig templates are inline heredoc strings registered on an `ArrayLoader`
  inside `FeedController::registerTemplates()`. There are no `.twig` files; the
  `var/cache/twig` cache dir is created at runtime.
- SQL lives in `schema.sql` (MySQL `FeedManager` database, tables `feeds`,
  `filters`, `groups`, `items`). App code uses raw PDO with `UTC_TIMESTAMP()`
  for storage; `timezone` is UTC and `display_timezone` is used for rendering.

## Setup

- PHP 8.3 required. Run `composer install`.
- Copy `config/parameters.dist.yml` to `config/parameters.yml` (gitignored) and
  fill in the PDO DSN/credentials. The app throws at runtime if this file is
  missing — there is no env-var fallback.
- Apply `schema.sql` to a MySQL instance before running the app.
- `parameters.yml`, `var/`, `vendor/`, `node_modules/` are all gitignored.

## Commands

PHP QA (run from repo root via Composer scripts):

- `composer lint` — runs `php-linter`, `php-cs-fixer fix --dry-run`,
  `phpstan analyse`, `rector process --dry-run` in sequence. This is the
  canonical check; `composer qa` is an alias.
- `composer lint:fix` — applies `php-cs-fixer fix` and `rector process`.
- `composer test` / `test:unit` / `test:integration` — invoke PHPUnit with
  `--testsuite=Unit` / `Integration`, but **no `tests/` directory or
  `phpunit.xml` exists yet**, so these scripts currently fail. Don't assume a
  passing test suite exists.

JS/Markdown QA (Node, dev-only):

- `npm run lint` — `eslint` (`.js,.ts,.vue,.yml,.yaml`) + `markdownlint '**/*.md'`.
- `npm run format` — `prettier --write .` + `markdownlint --fix`. There is no
  JS build step; `assets/app.js` is served verbatim.

Suggested order before committing: `composer lint:fix` then `composer lint`,
plus `npm run format` if touching non-PHP files.

## Conventions and gotchas

- PHPStan runs at **level 8** over `bin` and `src` only (`phpstan.neon.dist`).
- Rector enforces `declare(strict_types=1)` via `DeclareStrictTypesRector` and
  removes unused imports (`withImportNames`). Keep new files strict-typed.
- php-cs-fixer uses `@PSR12` plus strict import ordering (alpha, class/function
  /const) and `global_namespace_import` for classes/constants/functions. Global
  classes like `PDO`, `Exception` are imported, not fully qualified.
- Controllers write timestamps with `UTC_TIMESTAMP()` in SQL and convert to
  `display_timezone` only when rendering; do not mix local time into the DB.
- `groups` is a reserved-ish word; queries reference the `groups` table
  unquoted throughout. Preserve that style.
- The `.cecli/` directory and `.cecli*` entries are a local AI tool's data and
  are gitignored — do not commit anything under them.
- Markdown files are linted: line length 100, ATX headings, no trailing
  punctuation in headings (MD026), prose wrapped (prettier `proseWrap: always`).
