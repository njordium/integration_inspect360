# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
