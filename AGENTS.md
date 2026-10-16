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

PHP QA (run from repo root via Composer scripts; Mago is the linter, formatter,
and static analyzer, configured in `mago.toml`):

- `composer lint` — runs `mago lint` (read-only lint rules) then `mago analyze`
  (static type analysis). This is the canonical check; `composer qa` is an
  alias. `mago analyze` currently reports existing issues, so `composer lint`
  exits non-zero until those are resolved.
- `composer lint:fix` — runs `mago lint --fix` to apply auto-fixable lint rules,
  then `mago format` to format the code.
- `composer lint:upgrade` — runs `rector process` and applies changes directly
  (no dry run). Rector is not part of `composer lint`, so run it deliberately
  and review the diff.
- No Composer test scripts are defined. There is no `tests/` directory or
  `phpunit.xml`, so there is no test suite to run. Don't assume one exists.

JS/Markdown QA (Node, dev-only):

- `npm run lint` — `eslint` (`.js,.ts,.vue,.yml,.yaml`) + `markdownlint '**/*.md'`.
- `npm run format` — `prettier --write .` + `markdownlint --fix`. There is no
  JS build step; `assets/app.js` is served verbatim.

Suggested order before committing: `composer lint:fix` then `composer lint`,
plus `npm run format` if touching non-PHP files.

## Conventions and gotchas

- Mago lints and analyzes `index.php`, `bin`, and `src` (`mago.toml`), with the
  Symfony and PHPUnit linter integrations enabled. Several lint rules and
  analyzer issue types are disabled or ignored there; follow that config rather
  than working around it in code.
- Rector (`rector.php`, paths `bin` and `src`) enforces `declare(strict_types=1)`
  via `DeclareStrictTypesRector` and removes unused imports (`withImportNames`).
  Keep new files strict-typed.
- `mago.toml` has no formatter section, so `mago format` uses Mago's defaults.
- Import global classes like `PDO` and `Exception` rather than fully qualifying
  them.
- Controllers write timestamps with `UTC_TIMESTAMP()` in SQL and convert to
  `display_timezone` only when rendering; do not mix local time into the DB.
- `groups` is a reserved-ish word; queries reference the `groups` table
  unquoted throughout. Preserve that style.
- The `.cecli/` directory and `.cecli*` entries are a local AI tool's data and
  are gitignored — do not commit anything under them.
- Markdown files are linted: line length 100, ATX headings, no trailing
  punctuation in headings (MD026), prose wrapped (prettier `proseWrap: always`).
