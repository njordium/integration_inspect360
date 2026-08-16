# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.2] — 2026-08-16

### Changed

- **Row cap reduced from 30 to 7** on the three list widgets (Approved / Added / Assessed). Feedback from live deploy was that ~8 visible rows crowded the dashboard column's bottom edge with no breathing room. 7 rows gives a short, scannable list; anything more goes via the "Show all" deep link into ymir.
- **"Show all" link restyled** — now centered with an `OpenInNew` icon next to the label, matching the `integration_forgejo_gitea` pattern. Underline appears on hover of the text only, not the icon.
- **"I360: Approved Vendors" widget title shortened to "I360: Approved"** so the full name fits without truncation in the dashboard title bar.
- Removed the (now unnecessary) `max-height` / internal-scroll on list bodies — with only 7 rows the full list always fits.
- **Widget settings now open in an `NcModal`**, matching the `integration_forgejo_gitea` pattern exactly. The previous inline-reveal-below-toolbar was replaced. New shared component `src/components/WidgetSettingsModal.vue` handles the modal shell (title, section, Cancel/Save actions with `ContentSave` icon + spinner). Draft-then-Save semantics: the picker mutates a local draft; user commits via **Save** (persists then closes) or reverts via **Cancel**.
- **3-dot menu reordered**: `Widget settings` first (with filled `Cog` icon), `Refresh now` second — matching forgejo. Previous `CogOutline` icon replaced with filled `Cog`.

## [0.3.1] — 2026-08-16

### Changed — widget UX polish (feedback from first live deploy)

- **3-dot widget-settings menu (top-right)** on all four widgets, matching the `integration_forgejo_gitea` pattern. `NcActions` with `:forceMenu="true"` containing two actions: **Refresh now** (fires an immediate fetch) and **Widget settings** (toggles an inline settings panel just below the toolbar). Replaces the previous bottom-footer settings toggle.
- **Vertical row layout** on the three list widgets: title + status chip on line 1, meta (city · country · org number · flag chips) on a wrapping line 2. Fixes the horizontal overflow where longer status text ("Under review") pushed chips beyond the widget's right edge on standard dashboard column widths.
- **High-contrast solid chip colours** — approved (green #16a34a), under review (orange #ea580c), draft (grey #6b7280), and risk-level chips (green/orange/red/dark-red for low/medium/high/critical). Replaces the earlier 20 %-alpha tints that were near-invisible on Nextcloud's grey widget background.
- **`overflow: hidden` + `max-height: 500 px`** on the widget root, with `max-height: 380 px` + internal `overflow-y: auto` on list bodies. Long lists scroll internally instead of pushing the widget vertically past the dashboard column.
- **Widget titles shortened `Inspect360:` → `I360:`** (Overview / Approved Vendors / Added Vendors / Assessed) to give the actual widget name more headline room in the dashboard title bar.
- **Flag chips (Critical / ICT / DP / AML)** now use distinct high-contrast colours (red / blue / purple / amber) instead of the near-invisible grey defaults.

### Fixed

- Duplicate settings surfaces removed — the old `.footer > .settings-toggle > .settings-body` block at the bottom of each widget is gone; refresh cadence now lives only in the 3-dot menu.

## [0.3.0] — 2026-08-16

### Security

First full security audit against OWASP Top 10 (2021) + API Security Top 10 (2023). 3 Medium + 5 Low findings shipped as fixes in this release.

- **H-M1 (A07 / Bruteforce)** — `POST /login` now carries `#[BruteForceProtection(action: 'inspect360Login')]` and calls `$response->throttle(['email' => $email])` on invalid-credentials, so a session-authenticated user cannot use the Nextcloud instance to launder credential-stuffing traffic against Inspect360. (`lib/Controller/ConfigController.php`)
- **H-M2 (A04 / Insecure Design)** — Distributed access-token cache (`OCP\ICacheFactory`) added to `Inspect360AuthService`, keyed per user with TTL derived from `expires_in`. Kills the thundering-herd refresh where four dashboard widgets firing in parallel each triggered their own `/auth/refresh` POST, with N-1 losing the refresh-token-rotation race. On refresh failure, service now re-reads the distributed cache before returning "not connected" to catch the case where a parallel worker won the race. (`lib/Service/Inspect360AuthService.php`)
- **H-M3 (A10 / SSRF)** — `setAdminConfig` now rejects known internal / cloud-metadata hostnames (`metadata.google.internal`, `169.254.169.254` etc.) and any host that resolves to an RFC1918 / link-local / reserved IP range, independently of Nextcloud's site-wide `allow_local_remote_servers` flag. Loopback still allowed for dev. (`lib/Controller/ConfigController.php::isSafeInstanceHost`)
- **H-L1 (A07 / Session)** — `disconnect()` now issues a best-effort `POST /api/v1/auth/logout` with the stored refresh token before clearing local state, so a leaked Nextcloud config snapshot cannot be replayed to mint access tokens after the user disconnects. (Endpoint URL is an educated guess pending upstream verification.) (`lib/Service/Inspect360AuthService.php::revokeUpstream`)
- **H-L2 (A05 / Insecure Default)** — Removed the hardcoded fallback `https://ymir.njordium.io` from `getInstanceUrl()`. A fresh install now returns "not connected" until the admin explicitly configures the instance URL. The demo URL is kept only as a Vue placeholder attribute in the admin UI.
- **H-L3 (A08 / Artifact hygiene)** — Deleted 11 carryover Forgejo/Gitea widget PHP classes (`lib/Dashboard/{OpenIssues,ClosedIssues,OpenPRs,ClosedPRs,Heatmap,Milestones,Notifications,PendingReviews,RecentCommits,RepoStats,Stats}Widget.php`), 8 corresponding Vue views (`src/views/*Widget.vue`) and 2 unused Vue components (`src/components/{ItemAvatar,LabelChip}.vue`). ~1200 lines of unregistered but shipped bytes removed from the artifact.
- **H-L4 (A05 / Info leak)** — Upstream HTTP status codes are no longer echoed verbatim to the browser (`upstream_502` → `upstream_server_error`, etc.). Exact status is only logged server-side. (`lib/Service/Inspect360APIService.php::doRequest`)
- **H-L5 (A03 / Defence-in-depth)** — `setRefreshInterval` now whitelists `widgetKey` against the four known widget IDs, preventing session-authenticated users from populating `oc_preferences` with arbitrary `<foo>_refresh_seconds` rows. (`lib/Controller/Inspect360APIController.php`)

Accepted risks (documented in `SECURITY.md`):
- JWT signature not verified on the Nextcloud side (I1) — only cosmetic claims read; upstream verifies on every API call.
- Refresh endpoint URL / body shape unverified (I4) — will be pinned down on first live token expiry against ymir.
- `nextcloud/ocp` dev-dep pinned to `dev-stable30` (I3) — phpstan lints against NC30 surface only; bump before v1.0.

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
