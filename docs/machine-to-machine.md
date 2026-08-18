# Machine-to-machine (API clients)

A backend that acts as **itself**, not on behalf of a signed-in user: a nightly
job, a service calling another service, a partner's server hitting your API.
The OAuth2 `client_credentials` grant, with credentials that come from
registering an **API client**.

Two sides, and you may be on one or both:

- **The caller** — holds a client id and secret, exchanges them for a token.
- **The resource server** — verifies that token and authorizes by **scope**.

A machine token and a user access token never cross: `verify()` rejects a
machine token, `verifyClient()` rejects a user one.

## 1. Register the client

From the control panel (Applications → Manage → API clients), or over the
management API with a user token holding **`CLIENT_MANAGE`** for that
organization:

```php
$new = $mgmt->registerClient([
    'name'          => 'reporting-job',
    'orgId'         => $orgId,        // required — who the machine acts for
    'applicationId' => $app['id'],    // strongly recommended, see below
    'scopes'        => ['reports.write'],
]);
// ['clientId' => 'client_9f3a…', 'clientSecret' => 'kQ7v…']
```

Or plain HTTP:

```bash
curl -X POST https://sso.yourcompany.com/api/clients \
  -H "Authorization: Bearer $MANAGEMENT_TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"reporting-job","orgId":42,"applicationId":7,"scopes":["reports.write"]}'
```

| Field | |
|---|---|
| `name` | Required. Label in the client list and the audit trail. |
| `orgId` | Required. The customer the machine acts for — becomes `org_id`/`tenant_id` in every token it mints, and the billing attribution. |
| `applicationId` | Optional but see §2. Binds the client to one application. |
| `scopes` | Optional; defaults to `["api.default"]`. What the client may ask for — a scope not registered here is never granted. |

`CLIENT_MANAGE` alone is not enough: the caller must also have access to that
`orgId`, so an admin of one tenant cannot mint a client billed to another.

## 2. `applicationId` decides the audience — read this before step 3

`aud` on the issued token depends on it, and `aud` is what the resource server
checks:

- **With `applicationId`** → `aud` = that application's identifier, the same
  value your resource server has in `SSO_AUDIENCE`. `verifyClient()` passes,
  and the token is scoped to one application exactly like a user token.
- **Without it** → `aud` = the client id itself. `verifyClient()` then fails
  the audience check unless the resource server sets its audience to that
  literal `client_9f3a…` — one audience per client, which does not scale.

**If a Latch Vector SDK will verify the token, register the client with an
`applicationId`.** Leave it off only for the scope-plus-org model, where
something else consumes the token.

## 3. Store the secret

The plaintext secret exists **exactly once**, in the registration response. The
service stores only a hash: `GET /api/clients` never returns it, and there is
no endpoint to retrieve or rotate it. Lose it and you register a new client and
swap the config.

It is an outbound credential of the *calling* app — it does not belong in the
SDK config (`SSO_ISSUER` / `SSO_AUDIENCE`), which is verification-side only.
Put it where your other outbound secrets live:

```dotenv
# .env — never committed
REPORTING_CLIENT_ID=client_9f3a...
REPORTING_CLIENT_SECRET=kQ7v...
```

```php
// config/services.php (Laravel)
'reporting' => [
    'client_id'     => env('REPORTING_CLIENT_ID'),
    'client_secret' => env('REPORTING_CLIENT_SECRET'),
],
```

On Symfony use the secrets vault. The SDK sends the pair as HTTP Basic — never
put it in a URL, a query string, or a log line.

## 4. Get a token (the caller)

```php
use LatchVector\Sso\SsoClient;

$sso = new SsoClient(
    issuer:   'https://sso.yourcompany.com',
    audience: 'your-app-identifier',   // required by the constructor; unused by this call
);

$machine = $sso->clientCredentials($clientId, $clientSecret, ['reports.write']);

$machine->accessToken;        // send as: Authorization: Bearer …
$machine->expiresInSeconds;   // ~900
$machine->scope;              // what was actually granted — may be narrower than asked
```

A different wire shape from the user endpoints: HTTP Basic plus a form body to
`POST {issuer}/oauth2/token`, and OAuth2-style errors (`error` /
`error_description`) — which the SDK still maps onto the same exceptions
(`AuthenticationException` on bad credentials, `RateLimitException` with
automatic backoff).

**There is no refresh token.** When it expires you call this again — so cache
it, do not fetch one per request:

```php
$token = cache()->remember('sso_machine_token', now()->addMinutes(14), fn () =>
    $sso->clientCredentials(
        config('services.reporting.client_id'),
        config('services.reporting.client_secret'),
        ['reports.write'],
    )->accessToken);

Http::withToken($token)->post('https://api.internal/reports', $payload);
```

Cache below the TTL (14 minutes against 15), and key it per client id if you
hold more than one.

## 5. Accept the token (the resource server)

No secret here — only the public JWKS, discovered from the issuer:

```dotenv
SSO_ISSUER=https://sso.yourcompany.com
SSO_AUDIENCE=your-app-identifier      # must match the client's applicationId (§2)
```

**Raw PHP**

```php
use LatchVector\Sso\ClientPrincipal;

$client = $verifier->verifyClientAuthorizationHeader($_SERVER['HTTP_AUTHORIZATION'] ?? null);

if (!$client->hasScope('reports.write')) {
    http_response_code(403); exit;
}
// $client->clientId, $client->orgId, $client->tenantId, $client->scopes, $client->applicationId
```

**Laravel** — `sso.client` is the machine counterpart of `sso.auth`, and
`sso.scope` of `sso.can`:

```php
Route::post('/reports/sync', [ReportController::class, 'sync'])
    ->middleware(['sso.client', 'sso.scope:reports.write']);

// several codes = all of them; append ",any" for at least one
->middleware('sso.scope:reports.read,reports.write,any');

// in the controller
$client = $request->attributes->get('sso_client');   // ClientPrincipal
```

**Symfony** — put `SsoClientAuthenticator` on a firewall of its own so machine
and user callers stay separated:

```yaml
# config/packages/security.yaml
firewalls:
    api_machine:
        pattern: ^/api/machine
        stateless: true
        custom_authenticators: [LatchVector\Sso\Symfony\SsoClientAuthenticator]
```

## 6. Two rules that catch people

**Authorize by scope, not permissions.** A `ClientPrincipal` has no user behind
it — no `permissions`, no roles, no org reach. `sso.can` / `$principal->can()`
do not apply.

**A machine token is tenant-wide.** If your backend authenticates as a machine
but acts for an end user, scope by the *user*: verify the forwarded user token
and use `$user->tenantId`, `$user->orgPath`, `$user->scopeSubtree` /
`$user->scopeSelf` — never the machine's `orgId`.

## 7. Test it, step by step

```bash
# 1. A token comes back
curl -u "$CLIENT_ID:$CLIENT_SECRET" \
  -d grant_type=client_credentials -d scope=reports.write \
  https://sso.yourcompany.com/oauth2/token
# -> {"access_token":"…","token_type":"Bearer","expires_in":900,"scope":"reports.write"}

# 2. Wrong secret -> 401 invalid_client
curl -u "$CLIENT_ID:nonsense" -d grant_type=client_credentials \
  https://sso.yourcompany.com/oauth2/token

# 3. It opens the machine route
curl -X POST -H "Authorization: Bearer $TOKEN" https://api.example.com/reports/sync
```

Then the negatives, which matter more:

- A **user** access token on the machine route → rejected (`token_use` is
  `access`, not `client`).
- The machine token on a `sso.auth` route → rejected the same way.
- An unrequested scope → not in `$machine->scope`, and `sso.scope` returns 403.
- Wrong audience (client registered without `applicationId`, §2) →
  `TokenVerificationException` on the resource server.

See also: [management.md](management.md) for the management token that step 1
needs, and [integrate-laravel.md](integrate-laravel.md) /
[integrate-symfony.md](integrate-symfony.md) /
[integrate-raw-php.md](integrate-raw-php.md) for the user-token side.
