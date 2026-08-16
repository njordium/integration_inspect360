# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] — 2026-08-16

### Added

- **Overview widget** — four KPI tiles (Approved Vendors, Total Vendors, Pending Review, Total Services). Backed by `GET /api/v1/suppliers/stats` and `GET /api/v1/products?limit=1` — one round-trip pair populates the whole widget. Each tile deep-links to the corresponding Inspect360 page.
- **Approved Vendors widget** — list of suppliers with `status=approved`, capped at 30 rows. Shows org name, city / country, org number, and regulatory flag chips (Critical / ICT / Data Processor / AML). Row click deep-links to the vendor detail page in Inspect360.
- **Added Vendors widget** — recently added suppliers, sorted by `created_at desc`, same layout family as Approved Vendors.
- **Assessed widget** — recent assessments across accessible suppliers, with status, current stage, and colour-coded risk-level chip (low / medium / high / critical). Sorted by `updated_at desc` client-side.
- Per-widget refresh cadence picker (`RefreshIntervalPicker`) on all four widgets, defaulting to 5 minutes, whitelisted against `[0, 30s, 1m, 5m, 15m, 30m, 1h]`, persisted per-user via `PUT /apps/integration_inspect360/widget/{key}/refresh-interval`.
- Rich icon set — `CheckDecagram`, `Domain`, `EyeOutline`, `PackageVariant` for the four Overview tiles; `ShieldCheck`, `AccountPlus`, `ClipboardCheck` as empty-state headers; `LinkOff`, `AlertCircleOutline` for not-connected and error states.

### Changed

- `Inspect360APIService` replaced with Inspect360-native methods (`getSuppliersStats`, `getSuppliers`, `getProductsCount`, `getAssessments`) that normalise the three list-envelope shapes (`{items,total,...}`, `{products,total,...}`, raw array) to one `{items, total}` shape at the service layer.
- `Inspect360APIController` replaced with widget-facing endpoints: `GET /overview`, `GET /vendors/approved`, `GET /vendors/added`, `GET /assessments/recent`, `GET /instance-info`, `PUT /widget/{key}/refresh-interval`. Response bodies inline the refresh cadence and instance URL so each widget speaks a single round-trip per fetch.
- `AppInfo\Application::register()` now registers only the four new Inspect360 widgets. The 11 carryover Forgejo/Gitea widget PHP classes remain in the repo as reference material but are no longer surfaced to Nextcloud.

### Notes

- Old widget PHP files (`Dashboard/OpenIssuesWidget.php` etc.) and Vue views (`views/IssuesWidget.vue` etc.) are kept in the repository as reference implementations; they are neither registered nor bundled into the dashboard JS.
- The `added_vendors` endpoint passes `sort=created_at&order=desc` to the upstream — if Inspect360 does not honour those query params, the widget still returns rows in the API's default order; visible on first real dashboard render.

## [0.1.0] — 2026-08-16

### Added

- Password-login auth flow against Inspect360's `/api/v1/auth/login` — user signs in from Personal Settings with their Inspect360 email + password; only the returned JWT refresh token is persisted (encrypted via Nextcloud's `ICrypto`), the password is discarded.
- New `Inspect360AuthService` owning the login / refresh / access-token lifecycle. Access tokens are minted from the refresh token on demand and cached in memory for the lifetime of a single HTTP request. `Inspect360APIService.request()` delegates 401 recovery to it (`forceRefresh()` + one retry).
- `ConfigController` endpoints: `POST /login`, `POST /disconnect`, `GET /connection-status`.
- Handling for Inspect360's stepped-login policy responses — `mfa_required`, `mfa_enrollment_required`, `must_change_password` each return a distinct status so the settings UI can render a targeted message rather than a generic "sign-in failed".
- Njordium logo shipped as the app icon (`img/app.svg`, `img/app-dark.svg`).
- Admin instance URL defaults to `https://ymir.njordium.io` so a fresh install works against the Njordium demo instance out of the box.

### Changed

- Admin settings simplified to a single `instance_url` field; OAuth client-id / client-secret / instance-type-picker (Forgejo / Gitea) removed — they don't apply to the Inspect360 auth model.
- Personal settings replaced with an email + password sign-in form; the OAuth "Connect" redirect flow is removed.
- `TokenStorage` narrowed to refresh-token-only storage; access tokens are no longer persisted.
- `Inspect360APIService` refactored to be auth-mechanism-agnostic; OAuth-specific `requestOAuthAccessToken` / `tryRefresh` methods removed.

### Notes

- Widget-facing `Inspect360APIController` still calls the carryover Forgejo/Gitea endpoint surface (issues, PRs, commits, milestones, …). Those calls will 404 against a real Inspect360 instance and will be replaced with vendor/service/assessment endpoints in the next release.
- Refresh endpoint URL and request body shape (`POST /api/v1/auth/refresh` with `{"refresh_token": "..."}`) are educated guesses based on OAuth 2 conventions and the login response shape — will be verified on first real integration run against ymir and adjusted if needed.

## [0.0.1] — 2026-08-14

### Added

- Initial scaffold cloned from `integration_forgejo_gitea`. App id `integration_inspect360`, PHP namespace `OCA\Inspect360`, targets Nextcloud 30–35 and PHP 8.3+. Repository layout, build tooling, dashboard, controller, service and settings structure, OAuth scaffolding and CI workflows in place. Inspect360-specific business logic pending — carried-over controllers and services still call the Forgejo/Gitea API surface and are placeholders to be replaced.
