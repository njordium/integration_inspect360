# Inspect360 integration for Nextcloud

Nextcloud integration app for [Njordium Inspect360](https://njordium.com).

## Status

**v0.0.1 — initial scaffold.** The repository layout, build tooling, PHP namespace (`OCA\Inspect360`), dashboard and settings framework, OAuth scaffolding and CI workflows are in place.

Business logic — controllers, services, widget content — was cloned from `integration_forgejo_gitea` (the current Njordium Nextcloud fork base) and still targets the Forgejo/Gitea API surface. It will be replaced with Inspect360-specific behaviour incrementally in subsequent commits.

## Requirements

- Nextcloud 30–35
- PHP 8.3+
- Node 20+, npm 10+ (build only)

## Development

Standard Nextcloud app layout.

```bash
composer install
npm install
npm run build       # production bundles into js/
npm run dev         # dev bundles
npm run watch       # rebuild on change
npm run lint        # eslint
composer stan       # phpstan
composer test       # phpunit
```

Package for install with `make appstore` (or `make build`); see `makefile` for the full task list.

## License

AGPL-3.0-or-later. See `COPYING`.
