# Security

## Reporting a vulnerability

Please report security issues privately to **security@njordium.com** rather than opening a public issue.

We aim to acknowledge reports within 3 working days and provide a remediation timeline within 10 working days.

## Supported versions

Only the latest minor release receives security updates. As of writing, that is `0.3.x`.

## Audit history

- **2026-08-16 — v0.3.0** (SHA `16b20ea` audited, fixes shipped in v0.3.0). First full audit against OWASP Top 10 (2021) and API Security Top 10 (2023). 3 Medium + 5 Low findings surfaced and remediated in the same release; 4 Info items documented as accepted risks below.

## Security posture

### Authentication

- Token-based authentication against Inspect360's `/api/v1/auth/` endpoint. The user completes sign-in from Personal Settings; only the returned refresh token is persisted.
- Refresh token stored per-user in `oc_preferences`, encrypted at rest via Nextcloud's `ICrypto` (see `lib/Service/TokenStorage.php`).
- Access tokens are minted on demand from the refresh token. They are cached (a) per-request in memory and (b) cross-request in Nextcloud's distributed cache (Redis / APCu / memcached) with TTL = `expires_in - 30 s`. They are **never persisted** to disk.
- Nextcloud's bruteforce throttler (`#[BruteForceProtection]`) is attached to the sign-in endpoint — a session-authenticated user hammering the endpoint to enumerate Inspect360 accounts will be back-off-throttled per email after successive failures.
- MFA-enabled accounts are intentionally rejected in this release with a targeted UI message (`mfa_required`, `mfa_enrollment_required`, `must_change_password`). Fuller MFA support arrives in a subsequent release.

### Authorization

- Every widget-facing endpoint is annotated `@NoAdminRequired` and scoped by `$this->userId`.
- `setAdminConfig` is admin-only (Nextcloud default when `@NoAdminRequired` is absent). No `@NoCSRFRequired` anywhere in the controller layer.
- No endpoint accepts an object ID from the client — the widget endpoints (`/overview`, `/vendors/approved`, `/vendors/added`, `/assessments/recent`) return only what the session user's Inspect360 role can see.
- The per-widget refresh-cadence setter (`PUT /widget/{widgetKey}/refresh-interval`) whitelists the four known widget IDs to prevent authenticated users from populating `oc_preferences` with arbitrary rows.

### SSRF hardening on the admin `instance_url`

- Scheme restricted to `http` / `https`; plain HTTP against non-loopback hosts triggers a warning banner.
- Known internal / cloud-metadata hostnames rejected (`metadata`, `metadata.google.internal`, `metadata.aws.internal`, `instance-data`, `instance-data.ec2.internal`).
- Hosts that resolve to RFC1918 / link-local / reserved IP ranges are rejected at admin-save time, independently of Nextcloud's site-wide `allow_local_remote_servers` setting.
- Loopback (`localhost`, `127.0.0.1`, `::1`, `*.localhost`) is deliberately allowed for dev use.
- The check is DNS-time and does not protect against active DNS rebinding after admin save; that scenario is out of scope (the trust boundary is admin), but the check meaningfully prevents typo-driven and clipboard-copy-driven SSRF.

### Logging

- Every catch block logs `$e->getMessage()` — not the exception object — to avoid Nextcloud's default full-context capture including the `Authorization: Bearer` header.
- Known secret substrings (access tokens, refresh tokens) are stripped from log messages via `redactSecrets()` / `redactUrl()` before write.
- Upstream HTTP statuses are bucketed to `upstream_client_error` (4xx) / `upstream_server_error` (5xx) before being returned to the browser; the exact status is only logged server-side.

### Denial-of-wallet on the upstream

- Every widget carries a per-user refresh cadence picker with a whitelisted set of intervals: `[Never, 30 s, 1 m, 5 m, 15 m, 30 m, 1 h]`, default 5 m. Worst-case sustained load per user tab is ~10 requests/min at the 30 s minimum.
- The distributed access-token cache coalesces parallel dashboard-load requests so all four widgets fired at first paint result in at most one `/auth/refresh` call rather than four.
- Polling pauses while the browser tab is hidden and re-fetches only on wake.

## Accepted risks (documented)

- **JWT signature is not verified on the Nextcloud side.** The token was just received over TLS from the trusted upstream in the same round-trip; we hold only cosmetic claims (`sub`, `role`) from it and re-check nothing security-relevant. The upstream server verifies the signature on every subsequent API call. We do not hold the HS256 shared secret and cannot verify without it.
- **Best-effort upstream logout is a guess** (`POST /api/v1/auth/logout` with the refresh token). Endpoint URL and body shape are educated guesses pending verification against ymir. A missing endpoint (404) is silently accepted — local state is always cleared regardless.
- **Refresh endpoint shape is unverified.** `POST /api/v1/auth/refresh` with `{"refresh_token": "..."}` is an educated guess based on standard token-refresh conventions. First real 15-minute token expiry against a live instance will confirm; adjust in `Inspect360AuthService::refreshAndCache()` if wrong.
- **`nextcloud/ocp` composer dev-dep is pinned to `dev-stable30`.** The app declares NC 30–35 compatibility but phpstan lints against the NC30 OCP surface only. Bump before v1.0.

## Threat model

- **In scope:** any authenticated Nextcloud user (including admin) trying to exploit the integration, and any external attacker with network reach to the Nextcloud instance.
- **Out of scope:** compromise of the underlying Nextcloud host (root, database access), compromise of the Nextcloud administrator account (all admin-configurable knobs are treated as trusted at set-time), compromise of the Inspect360 upstream, and DNS rebinding attacks against admin-save-time SSRF checks.

## Contact

`security@njordium.com` — please include repository URL, affected version(s), reproduction steps, and impact assessment.
