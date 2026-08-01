# latchvector/sso

PHP SDK for Latch Vector SSO. PHP 8.1+, with Laravel and Symfony
integrations.

```bash
composer require latchvector/sso
```

> **Note on `firebase/php-jwt`.** This SDK requires `^7.1`. Everything
> below 7.0.0 is affected by CVE-2025-45769, and Composer will refuse to
> install those versions. Do not work around that with
> `policy.advisories.ignore`.

---

## Protecting an API — the common case

Most integrations only need this. Your API verifies tokens locally; it does
not call the SSO service on every request.

```php
use LatchVector\Sso\TokenVerifier;

$verifier = new TokenVerifier(
    issuer: 'https://sso.yourdomain.com',
    audience: 'https://api.yourcompany.com',   // your registered identifier
    cache: $psr16Cache,                        // see below — do not skip this
);

$principal = $verifier->verifyAuthorizationHeader(
    $request->getHeaderLine('Authorization'),
);
```

### Pass a cache

PHP is not Node or Python here. The process dies at the end of every
request, so without a PSR-16 cache the SDK refetches the JWKS on **every
single request** — turning the SSO service into a hard dependency of your
hot path, which is the exact thing local verification exists to avoid.

The Laravel provider wires this up for you. On Symfony, pass `@cache.app`.

### Laravel

The service provider is auto-discovered. Add to `.env`:

```dotenv
SSO_ISSUER=https://sso.yourdomain.com
SSO_AUDIENCE=https://api.yourcompany.com
```

```php
Route::get('/invoices', [InvoiceController::class, 'index'])
    ->middleware('sso.auth');

Route::post('/invoices/{id}/approve', [InvoiceController::class, 'approve'])
    ->middleware(['sso.auth', 'sso.can:invoice.approve']);

// Several codes are "all of them"; append ",any" for "at least one"
->middleware('sso.can:invoice.approve,invoice.admin,any');
```

In a controller:

```php
$principal = $request->attributes->get('sso_principal');
```

Publish the config if you want to change the defaults:

```bash
php artisan vendor:publish --tag=latchvector-sso-config
```

#### Machine-to-machine (API clients)

For endpoints called by a backend service, not a user — the OAuth2
`client_credentials` grant. `sso.client` is the machine counterpart of
`sso.auth`, and `sso.scope` of `sso.can`. It verifies with `verifyClient`, so a
user access token is rejected here just as a machine token is rejected by
`sso.auth` — the two never cross.

```php
// A route a backend service calls (no user, no session).
Route::post('/reports/sync', [ReportController::class, 'sync'])
    ->middleware(['sso.client', 'sso.scope:reports.write']);

// "all of them" by default; append ",any" for "at least one".
->middleware('sso.scope:reports.read,reports.write,any');
```

In the controller, the verified client is a `ClientPrincipal`:

```php
use LatchVector\Sso\ClientPrincipal;

public function sync(Request $request)
{
    /** @var ClientPrincipal $client */
    $client = $request->attributes->get('sso_client');

    // Which customer the machine acts for, and what it may do.
    $orgId  = $client->orgId;
    $scopes = $client->scopes;                 // e.g. ['reports.write']
    // $client->clientId, $client->applicationId, $client->hasScope('reports.write')
}
```

**Calling another service** (your app *is* the backend job) — obtain a token
with the injected `SsoClient`. Cache it; it lasts ~15 minutes and there is no
refresh:

```php
use LatchVector\Sso\SsoClient;

public function pushNightly(SsoClient $sso)
{
    $token = cache()->remember('sso_machine_token', now()->addMinutes(14), function () use ($sso) {
        return $sso->clientCredentials(
            config('services.reporting.client_id'),
            config('services.reporting.client_secret'),
            ['reports.write'],
        )->accessToken;
    });

    Http::withToken($token)->post('https://api.internal/reports', $payload);
}
```

### Symfony

```yaml
# config/services.yaml
services:
    LatchVector\Sso\TokenVerifier:
        arguments:
            $issuer: '%env(SSO_ISSUER)%'
            $audience: '%env(SSO_AUDIENCE)%'
            $cache: '@cache.app'

    LatchVector\Sso\Symfony\SsoAuthenticator: ~
```

```yaml
# config/packages/security.yaml
security:
    firewalls:
        api:
            pattern: ^/api
            stateless: true
            custom_authenticators:
                - LatchVector\Sso\Symfony\SsoAuthenticator
```

Permission codes become roles verbatim, so this works with the codes your
application already defined:

```php
#[IsGranted('invoice.approve')]
public function approve(int $id): Response { … }
```

The authenticated user is an `SsoUser` wrapping the `Principal`. There is
no database row behind it — everything it reports was proven by the
signature, so establishing identity costs no query.

#### Machine-to-machine (API clients)

For machine callers, add `SsoClientAuthenticator` on a firewall of its own, so
machine and user callers stay separated. It verifies with `verifyClient`, so a
user token is rejected there just as a machine token is rejected by
`SsoAuthenticator`.

```yaml
# config/services.yaml
    LatchVector\Sso\Symfony\SsoClientAuthenticator: ~
```

```yaml
# config/packages/security.yaml
security:
    firewalls:
        api_machine:
            pattern: ^/api/machine
            stateless: true
            custom_authenticators:
                - LatchVector\Sso\Symfony\SsoClientAuthenticator
```

The authenticated user is an `SsoClientUser` wrapping the `ClientPrincipal`, and
**scopes** become roles verbatim (the mirror of permissions on the user side):

```php
#[IsGranted('reports.write')]
public function sync(): Response { … }   // requires the reports.write scope
```

To *call* another service, obtain a token with the `SsoClient` service:

```php
$machine = $sso->clientCredentials($clientId, $clientSecret, ['reports.write']);
// cache $machine->accessToken (~15 min, no refresh), send as Bearer.
```

---

## Multitenancy (Laravel)

Verifying a token tells you *who* is calling. Multitenancy is about what data
they may touch. The SDK ties your Eloquent models to the tenant in the verified
token, so a query cannot read or write another tenant's rows even if you forget
the `where` clause.

Add the trait to any model with a `tenant_id` column:

```php
use Illuminate\Database\Eloquent\Model;
use LatchVector\Sso\Laravel\BelongsToTenant;

class Invoice extends Model
{
    use BelongsToTenant;
}
```

Behind the `sso.auth` (or `sso.client`) middleware, that is all:

```php
Invoice::all();          // only the caller's tenant
Invoice::create([...]);  // tenant_id stamped automatically
$invoice->update([...]); // still scoped — you cannot touch another tenant's row
```

The scope comes from the `tenant_id` claim in the token, bound to the request by
the auth middleware. Configure it in `config/latchvector-sso.php`:

```php
'tenant' => [
    // Turn OFF in a sandbox / local dev so testing isn't bound to one tenant.
    'enabled' => env('SSO_TENANT_SCOPING', true),
    'column'  => 'tenant_id',
    // Callers with any of these permissions see across tenants — platform
    // operators, not ordinary admins.
    'bypass_permissions' => ['PLATFORM_ADMIN'],
],
```

- **Bypass.** A caller whose token carries a `bypass_permissions` code (e.g. a
  platform operator) is left unconstrained. An org admin is still bound to their
  own tenant.
- **Sandbox.** Set `SSO_TENANT_SCOPING=false` in dev so seed data and tests
  aren't confined to one tenant.
- **Cross-tenant on purpose.** A nightly report that must span tenants uses
  `Invoice::allTenants()->...` — explicit, greppable, never accidental.
- **Console & queues.** With no request there is no tenant, so the scope is
  inert. In a queued job that must be tenant-bound, set it yourself:
  `app(\LatchVector\Sso\Tenancy\TenantContext::class)->set($tenantId);`

### Confining to a sub-tree

`tenant_id` is the hard wall *between* customers. *Within* one customer, an
admin of a sub-org should often see only their slice of the org tree, not the
whole tenant. Opt a model into **subtree mode** and it is narrowed to exactly
the org paths the caller's token grants:

```php
class Chart extends Model
{
    use BelongsToTenant;
    protected $tenantScope = 'subtree'; // default is 'tenant'
}
```

The model needs, alongside `tenant_id`, an `org_id` and an `org_path` column (a
materialized path like `/1/57/903/`). New rows are stamped with the writer's own
node; reads are confined to:

- **SUBTREE** grants — the caller's node *and everything below it* (a
  left-anchored prefix match on `org_path`);
- **SELF** grants — that node *only* (an exact match).

Which applies is decided by the caller's roles at token-issue time and carried
in the `scope_subtree` / `scope_self` claims — you write nothing. A machine
(client-credentials) token has no org reach, so a subtree model falls back to
tenant-wide for it — still leak-safe across customers.

> The trailing slash matters. Paths are stored `/1/57/` (not `/1/57`), so the
> prefix `/1/57/` can never leak into a sibling like `/1/570/`.

### Multitenancy at scale

For tables that will hold billions of rows, three columns and the right indexes
keep every scoped query a range scan, never a table scan:

| Column | Type | Why |
|---|---|---|
| `tenant_id` | `bigint` | the hard customer wall; on every tenant-aware table |
| `org_id` | `bigint` | the owning node — subtree tables only |
| `org_path` | `text` | materialized path `/1/57/903/`, trailing slash — subtree only |

Index **tenant-leading**, so the tenant predicate drives the scan:

```sql
-- every tenant-aware table
CREATE INDEX ON invoices (tenant_id, created_at DESC);

-- subtree tables: prefix scans on org_path within the tenant
CREATE INDEX ON charts (tenant_id, org_path text_pattern_ops);
```

`text_pattern_ops` is what makes `org_path LIKE '/1/57/%'` an index range scan
under any collation. For the largest tenants, partition or shard by `tenant_id`
(Postgres declarative partitioning, or Citus/Nile-style distribution): the
tenant-leading key means a query already touches only its own partition.

---

## What `audience` is for

It is your application's registered identifier, and it is **required** —
there is no option to turn the check off.

A token issued for a *different* application is still validly signed by a
trusted issuer. If you check the signature but not the audience, you accept
it, which means you accept one from every user of every application on the
platform. This is the single most common way an SSO integration is
compromised, and it is why the parameter has no default.

`firebase/php-jwt` checks only the signature, `exp`, `nbf` and `iat` — it
does **not** check `iss` or `aud`, unlike the equivalent libraries in Node
and Python. This SDK enforces both explicitly. If you ever verify tokens
without it, that is the gap to close first.

## The principal

```php
$principal->uid          // 4711 — key your records on this
$principal->email        // display only, see below
$principal->orgId        // 57
$principal->tenantId     // 1
$principal->orgPath      // "/1/57/"
$principal->permissions  // ['invoice.approve']
$principal->expiresAt    // DateTimeImmutable

$principal->has('invoice.approve');
$principal->hasAny('invoice.approve', 'invoice.admin');
$principal->hasAll('invoice.read', 'invoice.approve');
$principal->canReach('/1/57/903/');   // does their granted scope cover this node?
```

**Key your own tables on `uid`, never on the email.** Addresses change, and
a GDPR erasure request scrubs the address while `uid` survives. Rows keyed
on email lose the link to their own user the first time either happens.

**Do not cache `permissions` past `expiresAt`.** They are a snapshot from
issue time; a revoked role takes effect on the next token, which is why
access tokens last only 15 minutes.

---

## Logging users in

Only whatever actually handles the password needs this — a login
controller, a BFF, a mobile gateway. Your resource APIs do not.

```php
use LatchVector\Sso\{SsoClient, MfaRequired, TokenPair};

$sso = new SsoClient(
    issuer: 'https://sso.yourdomain.com',
    audience: 'https://api.yourcompany.com',
);

$result = $sso->login($email, $password);

if ($result instanceof MfaRequired) {
    $code = $this->promptForCode();                    // TOTP or recovery code
    $tokens = $sso->verifyMfa($result->pendingToken, $code);
} else {
    $tokens = $result;   // TokenPair
}
```

`login()` returns `LoginResult`, which is either a `TokenPair` or an
`MfaRequired` — never a token object with an empty `accessToken`. You have
to branch before you can reach a token, so the MFA path cannot be quietly
skipped. A customer will enable MFA eventually, and the failure mode of the
nullable version is an empty string reaching production.

### Social login

```php
$result = $sso->socialLogin('google', $googleIdToken);
```

The user must already exist. Accounts are provisioned by an administrator;
a first-time social login for an unknown email is refused rather than
silently creating an account.

### Refresh

```php
$fresh = $sso->refresh($storedRefreshToken);
$this->saveRefreshToken($fresh->refreshToken);   // before you use it
```

Refresh tokens rotate: **the old one is dead the moment `refresh()`
returns.** Persist the new one first.

```php
use LatchVector\Sso\Exception\{RefreshTokenException, RefreshTokenReusedException};

try {
    return $sso->refresh($stored);
} catch (RefreshTokenReusedException $e) {
    $this->destroySession();
    $this->alertSecurityTeam($userId);      // this is a security event
    throw $e;
} catch (RefreshTokenException) {
    return $this->redirectToLogin();        // expired or unknown — ordinary
}
```

`RefreshTokenReusedException` is deliberately *not* a subclass of
`RefreshTokenException`, so a catch block that only meant "refresh or
re-login" cannot swallow a compromise signal.

### Logout

```php
$sso->logout($refreshToken);
```

This revokes the refresh token. The current access token stays valid for
the rest of its 15 minutes — it is a signed bearer token, not a session.
For immediate cut-off, have an administrator disable the account.

---

## Errors

Every exception extends `SsoException` and carries `->errorCode` and
`->status`.

> `->errorCode`, not `->code`: PHP's own `\Exception` already declares a
> non-readonly `int $code`, and redeclaring it as a readonly string is a
> fatal error.

| Class | Codes |
|---|---|
| `AuthenticationException` | `invalid_credentials`, `invalid_code`, `invalid_id_token`, `invalid_token`, `invalid_token_use`, `invalid_or_expired_pending_token` |
| `RefreshTokenException` | `invalid_refresh_token`, `refresh_token_expired` |
| `RefreshTokenReusedException` | `refresh_token_reused` |
| `AccountNotActiveException` | `account_not_active` |
| `AccountLockedException` | `account_locked` |
| `AccessDeniedException` | `access_denied` |
| `ValidationException` | `validation_failed` (with `->fields`) |
| `RateLimitException` | `too_many_requests` (with `->retryAfterSeconds`) |
| `ConfigurationException` | `unknown_audience`, discovery failures |

`429` is retried automatically with exponential backoff and jitter (twice
by default). **Nothing else is retried**, and `->isRetryable()` is `false`
for everything but `RateLimitException`. A `403` is a decision the service
already made; retrying it produces a stream of `ACCESS_DENIED` audit
entries that a compliance officer will eventually ask you about.

`access_denied` does not distinguish "forbidden" from "does not exist" —
telling them apart would let anyone enumerate records across tenants.

---

## Configuration

You configure **one** URL. The JWKS endpoint is resolved from
`{issuer}/.well-known/openid-configuration` and cached, so the SDK keeps
working if it ever moves.

| Argument | Default | |
|---|---|---|
| `issuer` | — | required |
| `audience` | — | required, cannot be disabled |
| `leewaySeconds` | `30` | skew allowance; keep NTP running regardless |
| `jwksCacheSeconds` | `600` | |
| `cache` | `null` | PSR-16. Effectively required in PHP — see above |
| `maxRateLimitRetries` | `2` | client only |

---

## Smoke test

```bash
SSO_ISSUER=http://localhost:9000 SSO_AUDIENCE=http://localhost:9000 \
SSO_EMAIL=… SSO_PASSWORD=… php examples/smoke.php
```

Beyond the happy path it asserts that a token minted for a different
audience is rejected and that a tampered signature is rejected — the two
checks whose absence turns a working integration into an open door.

---

## Before you go live

- [ ] `audience` is set to your identifier, not ours
- [ ] A PSR-16 cache is wired in, so the JWKS is not refetched per request
- [ ] Your tables key on `uid`, not email
- [ ] `RefreshTokenReusedException` is handled as a compromise, not retried
- [ ] The `MfaRequired` branch is implemented and tested
- [ ] Tokens are never written to logs, URLs, or error reports
## Webhooks

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
## Migrating from your current system

Already have users, organizations, roles and permissions elsewhere? Don't create
them one at a time. Describe the whole estate in one payload, **validate** it (a
dry-run that reports every problem and writes nothing), then **commit** — a
migration that took months takes an afternoon.

```
POST /api/import/validate   ->  dry-run: reports every error, writes nothing
POST /api/import/commit      ->  provisions it, in one transaction
```

Records reference each other by your **own ids** (`externalId`), so you never
need ours:

```jsonc
{
  // target: an existing org…            OR a brand-new tenant:
  "rootParentOrgId": 57,                 // "rootTenant": { "orgName": "..", "slug": ".." }
  "organizations": [
    { "externalId": "o-cardio", "name": "Cardiology", "slug": "acme-cardio",
      "parentExternalId": null }
  ],
  "roles": [
    { "externalId": "r-doc", "orgExternalId": "o-cardio", "name": "Doctor",
      "scope": "SUBTREE", "permissionCodes": ["USER_MANAGE"] }
  ],
  "users": [
    { "externalId": "u-alice", "orgExternalId": "o-cardio", "email": "alice@acme.com",
      "fullName": "Alice A", "passwordBcrypt": "$2b$10$.." }   // omit -> send an invite
  ],
  "assignments": [
    { "userExternalId": "u-alice", "roleExternalId": "r-doc", "orgExternalId": "o-cardio" }
  ]
  // …also supported: applications[], permissions[]
}
```

Both endpoints return the same report: `counts` per type, an `errors` list (each
with the offending `externalId` and field) and, on commit, `invites` — the
one-time set-password links for users with no carried-over password. **Commit
writes nothing if `errors` is non-empty**, so a clean validate guarantees it.

- **bcrypt** passwords carry over (`$2a/$2b/$2y$`) — users keep their login;
  everyone else is invited.
- **Idempotent** — re-running skips what already imported, so retries and staged
  batches are safe.
- **No code?** The control panel's **Import / Migrate** screen has a CSV template
  per sheet and builds this same payload for you.

Requires `ORG_MANAGE` + `USER_MANAGE` + `ROLE_MANAGE`; provisioning a brand-new
tenant needs `PLATFORM_ADMIN`. The full contract and a complete worked example
live in `sdk/MIGRATION.md` in the Latch Vector repository.
