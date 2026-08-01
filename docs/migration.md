# Migrating onto Latch Vector

Already running your own identity data — organizations, users, roles,
permissions? You don't move it across one API call at a time. You describe the
whole estate in **one payload**, **validate** it (a dry-run that finds every
problem and writes nothing), then **commit** it. A migration that used to take
months takes an afternoon.

- **Two steps, always.** `POST /api/import/validate` → fix what it reports →
  `POST /api/import/commit`.
- **You keep your own ids.** Every record carries an `externalId` (its id in
  your old system); relationships reference *those*, and we map them to our ids
  for you. You never have to learn our ids to wire things up.
- **Idempotent.** Re-running skips whatever already imported — so a partial run,
  a retry, or a second batch is always safe.
- **Passwords come with you.** bcrypt hashes are carried over verbatim (users
  keep their password); anything else gets a one-time set-password link.

Requires `ORG_MANAGE` + `USER_MANAGE` + `ROLE_MANAGE`. Importing into an existing
organization needs subtree reach over it; provisioning a **brand-new tenant**
(omit `rootParentOrgId`, provide `rootTenant`) needs `PLATFORM_ADMIN`.

---

## The payload

```jsonc
{
  // Target: one of these two.
  "rootParentOrgId": 57,                 // import UNDER an existing org, OR…
  "rootTenant": { "orgName": "Acme Health", "slug": "acme-health" }, // …make a new tenant

  "organizations": [
    { "externalId": "o-cardio", "name": "Cardiology", "slug": "acme-cardio",
      "type": "DEPARTMENT", "parentExternalId": null }   // null → under the root
  ],
  "applications": [
    { "externalId": "a-portal", "orgExternalId": null,
      "identifier": "https://api.acme.com", "name": "Patient Portal" }
  ],
  "permissions": [   // application permissions only; system codes are referenced, not created
    { "externalId": "p-approve", "appExternalId": "a-portal",
      "code": "invoice.approve", "description": "Approve invoices" }
  ],
  "roles": [
    { "externalId": "r-doctor", "orgExternalId": "o-cardio", "name": "Doctor",
      "scope": "SUBTREE",
      "permissionCodes": ["USER_MANAGE"],          // system permissions, by code
      "permissionExternalIds": ["p-approve"] }      // app permissions defined above
  ],
  "users": [
    { "externalId": "u-alice", "orgExternalId": "o-cardio",
      "email": "alice@acme.com", "fullName": "Alice A",
      "passwordBcrypt": "$2b$10$...." },            // optional — omit to send an invite
    { "externalId": "u-bob", "orgExternalId": "o-cardio",
      "email": "bob@acme.com", "fullName": "Bob B" }
  ],
  "assignments": [
    { "userExternalId": "u-alice", "roleExternalId": "r-doctor", "orgExternalId": "o-cardio" }
  ]
}
```

All lists are optional. Insert order doesn't matter — organizations are sorted
parents-before-children for you, and every cross-reference is resolved by
`externalId`.

## Passwords

- **`passwordBcrypt` present** → the user logs in immediately with their existing
  password. Only bcrypt (`$2a$` / `$2b$` / `$2y$`) is accepted; other algorithms
  can't be re-hashed, so omit the field for those users.
- **Omitted** → the user is created without a password and appears in the
  response's `invites` array with a one-time `setupToken`. Hand them the link
  (`{your set-password URL}?token=<setupToken>`), or set
  `IMPORT_EMAIL_INVITES=true` on the server to have it emailed automatically.

## The response

Both endpoints return the same report:

```jsonc
{
  "status": "VALIDATED",           // dry-run — or "COMMITTED" / "FAILED"
  "tenantId": 26, "rootOrgId": 26,
  "counts": {                       // per entity type
    "organizations": { "created": 2, "skipped": 0, "updated": 0, "failed": 0 },
    "users":         { "created": 2, "skipped": 0, "updated": 0, "failed": 0 }
    // …
  },
  "errors": [                       // empty when clean; fix these and validate again
    { "entityType": "users", "externalId": "u-x", "field": "email",
      "message": "invalid email: not-an-email" }
  ],
  "invites": [                      // on commit: users needing a set-password link
    { "userExternalId": "u-bob", "email": "bob@acme.com", "setupToken": "…" }
  ]
}
```

**Commit refuses to write anything if `errors` is non-empty** — so a clean
`validate` is your guarantee the commit will go through whole.

## What's checked on validate

Slug and application-identifier uniqueness (globally and within the payload),
email format and uniqueness, resolvable parents/references, no cycles in the org
tree, role scope (`SELF`/`SUBTREE`), that every permission code is a real system
permission and never `PLATFORM_ADMIN`, and bcrypt format. Every failure is
returned at once with the offending `externalId` and field — not one-at-a-time.

## Idempotency & batching

Each created entity's `externalId → internal id` is remembered per tenant. Re-run
the same payload and it's all `skipped`; add new records to a later batch and
only those are created. Keep a single payload under **20,000 entities**
(`IMPORT_MAX_ENTITIES`) and split larger migrations into batches — overlap is
safe.

## No JSON? Use the panel

The control panel's **Import / Migrate** screen has a downloadable CSV template
per sheet (organizations, applications, permissions, roles, users, assignments).
Fill them in a spreadsheet, upload, **Validate**, **Commit** — the browser builds
this same payload for you, and hands back the invite links as a CSV to
distribute. No code required.
