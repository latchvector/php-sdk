# Webhooks

The SSO service can POST **critical events** to your application: a role
assigned or revoked from a user, a role's permissions changed, an account
disabled or erased. When permissions change, the affected users' current tokens
are invalidated (`data.tokensInvalidated: true`) — they refresh into a token
with the new access — so a webhook is your cue to clear caches or force a
refresh instead of waiting for the first 401.

You register and manage endpoints from the **control panel** (Applications →
Manage → Webhook), or over the management API. A webhook is optional — an
application with none behaves exactly as before, it just receives no events.

```
# Register — returns the signing secret ONCE, store it now
POST /api/webhooks
  { "organizationId": 1, "applicationId": 42,
    "url": "https://app.example.com/hooks/latchvector", "eventTypes": [] }
  -> { "id": 7, "url": "...", "secret": "whsec_...", "eventTypes": "" }

# Change URL / events / pause — the secret is left untouched
PUT  /api/webhooks/7   { "url": "...", "eventTypes": ["role.revoked"], "active": true }

# Rotate the secret (returned once); the old one stops working immediately
POST /api/webhooks/7/rotate-secret   -> { "id": 7, "secret": "whsec_..." }

DELETE /api/webhooks/7
```

Event types: `role.assigned`, `role.revoked`, `role.permissions_changed`,
`user.disabled`, `user.enabled`, `user.erased`. An empty `eventTypes` means all.

Payload:

```json
{
  "id": "<uuid>", "type": "role.revoked",
  "occurredAt": "2026-...Z", "tenantId": 1, "orgId": 57,
  "data": { "userId": 4711, "roleId": 12, "roleName": "Manager", "tokensInvalidated": true }
}
```

Every delivery is signed. Verify the signature **and** the timestamp against the
raw body (see the verify helper in this SDK) before trusting it:

- `X-LatchVector-Signature: sha256=<hex HMAC-SHA256(timestamp + "." + rawBody, secret)>`
- `X-LatchVector-Timestamp: <unix seconds>` — reject if too old (replay).
- `X-LatchVector-Delivery: <event id>` — stable across retries, for idempotency.

Delivery is at-least-once with exponential backoff; dedupe on the delivery id.
