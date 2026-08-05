# Changelog

All notable changes to this project will be documented in this file.

## [1.0.2] - 2026-08-05

### Added
- Added `ManagementClient` — the full management API in code: users, organizations, roles, applications, API clients, webhooks, audit, bulk import, GDPR export/erase, and (platform operators) sandbox tenants and PII re-encryption. Authenticated with a management access token (a user token minted with `audience` omitted), separate from `SsoClient`'s auth-only, tokenless endpoints.
- Added device-bound sessions: a new `DeviceInfo` value object, an optional `DeviceInfo $device` parameter on `SsoClient::login()`, `verifyMfa()`, and `socialLogin()`, and a `deviceId` field on `TokenPair` — store the id returned on first login and pass it back so the same device row is reused across sessions.
- Added `SsoClient::forgotPassword()` and `resetPassword()` for the self-service password reset flow.
- Added `docs/management.md` documenting the management API and how to obtain a management token.

## [1.0.1] - 2026-08-01

### Added
- Added official MIT License file.
- Added comprehensive Webhooks support for critical event notifications (`role.assigned`, `role.revoked`, `user.disabled`, etc.)
- Added LatchVector organization author metadata in `composer.json`.