# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [2.0.0] - 2026-08-17

### Changed — BREAKING

- **Tenant scoping is now confined to the caller's org subtree by default.**
  Previously a scoped query was narrowed to the customer (`tenant_id`) and no
  further, with org-subtree scoping available as an opt-in. That is unsafe once a
  person belongs to more than one company of the same customer — which they now
  can — because it shows them every company's rows.

  **Migration.** PHP already defaulted to subtree scoping, so no change is
  required here; this release aligns the version with the other SDKs and adds the
  multi-company surface below.

- **A token represents one person acting at ONE company.** `org_id`, `org_path`,
  `permissions` and `scope_self`/`scope_subtree` are resolved for that company
  alone rather than being a union across all of them. Claim shapes are unchanged,
  so verification code keeps working — but two things now matter:
  - **cache and key company data on `(uid, org_id)`, not `uid` alone.** After a
    switch, a `uid`-keyed cache serves the previous company's data to the new
    one. This is the most likely mistake in a multi-company integration, and the
    READMEs previously advised keying on `uid` without this caveat;
  - membership, not role assignment, is what grants access to a company: the
    service will not mint a token for one the user does not belong to.

### Added

- `SsoClient::listMemberships()` and `SsoClient::switchOrganization()` — the caller's companies, and acting as one
  of them. See `sdk/CONTRACT.md`, "The active organization".


## [1.0.3] - 2026-08-11

### Added
- Symfony + Doctrine tenant boundary: a `TenantFilter` (Doctrine SQL filter) and `TenantStampListener` (prePersist) that confine every query on a `TenantAware` entity to the caller's tenant and stamp new rows, wired automatically by the new `LatchVectorSsoBundle`. See `docs/symfony-tenancy.md`.
- `BelongsToTenantTree` — one-line trait that maps all three columns (`tenant_id`, `org_id`, `org_path`) and gives an entity org-tree isolation. `TenantWide` marker interface to opt a table into deliberate whole-tenant visibility. A tenant entity that declares neither isolation mode is now rejected loudly (never a silent whole-tenant leak).
- `TenantContext::fromPrincipal()` — adopt a verified token's tenant and org reach in one call. The delegation hook for a machine-fronted backend that acts on behalf of a forwarded user token, so scoping follows the user, not the (tenant-wide) machine.
- Filter within your subtree by a chosen org: Laravel `Model::forOrg('/2/5/', $includeSubtree)` / `forOrgId($id)` scopes and Doctrine `OrgScope::apply()` / `OrgScope::byId()`. Backed by `TenantContext::reaches()` / `reachesSubtree()` / `assertReach()`; a target outside the token's reach throws `OrgReachException` (map to HTTP 403). Narrowing only — it can never widen the always-on tenant scope.
- Framework integration guides: `docs/integrate-laravel.md`, `docs/integrate-symfony.md`, `docs/integrate-raw-php.md`.

### Changed
- Tenant scoping is now **fail closed** on both Symfony and Laravel: when scoping is active but no tenant is known, reads return nothing and writes throw — instead of the previous behaviour of returning every tenant's rows. `TenantContext` gains `isActive()` to distinguish "scoping on but tenant unknown" (deny) from a deliberate opt-out/bypass (unconstrained).
- **BREAKING (data layer):** the default scope for a tenant-owned model is now the caller's **org subtree**, not the whole tenant — sibling orgs no longer see each other's rows out of the box. A model that should stay visible to the entire tenant must opt out explicitly: Laravel `protected string $tenantScope = 'tenant';`, Doctrine `implements TenantWide`. Subtree models need an `org_path` column.

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