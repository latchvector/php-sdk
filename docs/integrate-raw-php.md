# Latch Vector SSO — raw PHP integration

No framework. You verify the token yourself and enforce the tenant boundary in
your own queries.

## 1. Install

```bash
composer require latchvector/sso
```

## 2. Verify the token

```php
use LatchVector\Sso\TokenVerifier;
use LatchVector\Sso\Exception\TokenVerificationException;

$verifier = new TokenVerifier(
    issuer: 'https://sso-sbx.latchvector.com',  // JWKS is discovered from this
    audience: 'your-app-identifier',            // mandatory
    cache: $psr16Cache,                          // optional but recommended (see below)
);

try {
    $p = $verifier->verifyAuthorizationHeader($_SERVER['HTTP_AUTHORIZATION'] ?? null);
} catch (TokenVerificationException $e) {
    http_response_code(401);
    exit(json_encode(['error' => 'invalid_token']));
}

// $p->uid, $p->tenantId, $p->orgId, $p->orgPath,
// $p->permissions, $p->scopeSelf, $p->scopeSubtree
```

**Cache:** pass any PSR-16 cache so the signing keys (JWKS) are fetched once, not
on every request — the PHP process dies each request.

## 3. Authorize

```php
if (! $p->has('invoice.read')) { http_response_code(403); exit; }

$p->hasAny('a', 'b');          // any of
$p->hasAll('a', 'b');          // all of
$p->canReach('/10/57/');       // does the caller's scope reach this org node
```

## 4. Tenant boundary (you enforce it)

There is no ORM to auto-scope, so put the token's tenant into every query:

```php
// Read — hard tenant wall:
$stmt = $pdo->prepare('SELECT * FROM invoices WHERE tenant_id = ?');
$stmt->execute([$p->tenantId]);

// Write — stamp it:
$pdo->prepare('INSERT INTO invoices (tenant_id, amount) VALUES (?, ?)')
    ->execute([$p->tenantId, $amount]);
```

Subtree scoping (only rows the caller reaches within the tenant):

```php
// SUBTREE grants: org_path LIKE '<prefix>%'  (prefix from $p->scopeSubtree)
// SELF grants:    org_path = '<node>'        (from $p->scopeSelf)
```

**Fail closed:** never run a tenant query without a verified `$p`. If there is no
principal, return 401 — don't fall back to an unscoped query.

## 5. Machine (client_credentials) tokens

```php
use LatchVector\Sso\ClientPrincipal;

$client = $verifier->verifyClientAuthorizationHeader($_SERVER['HTTP_AUTHORIZATION'] ?? null);
// $client->orgId, $client->tenantId, $client->scopes — no user identity.
```

A machine token is **tenant-wide** — it has no user org reach. If your backend
authenticates with a machine token but acts on behalf of an end user, verify the
user's **forwarded** token and scope by the user (`$user->tenantId`,
`$user->orgPath`, `$user->scopeSubtree`/`$user->scopeSelf`), not the machine.

## 6. Test it, step by step

Smoke script (`test.php`):

```php
require 'vendor/autoload.php';
use LatchVector\Sso\TokenVerifier;

$v = new TokenVerifier('https://sso-sbx.latchvector.com', 'your-app-identifier');
$p = $v->verify($argv[1]);                    // paste a raw access token as arg 1
printf("uid=%d tenant=%d perms=%s\n", $p->uid, $p->tenantId, implode(',', $p->permissions));
```

```bash
php test.php "<ACCESS_TOKEN>"       # prints the principal
php test.php "garbage"              # throws TokenVerificationException (as it should)
```

- Valid token → principal printed.
- Tampered/expired/wrong-audience token → `TokenVerificationException`.
- Tenant check: query with `tenant_id = $p->tenantId` and confirm another
  tenant's row never comes back.
