# Symfony + Doctrine tenant boundary

Confine every Doctrine query to the caller's tenant automatically — the same
model-level boundary the Laravel `BelongsToTenant` trait gives, but for Symfony.
Add a trait + interface to an entity and its rows become invisible to other
tenants; new rows are stamped with the caller's tenant on insert.

It is **fail closed**: if tenant scoping is on but the caller is unknown, a read
returns *nothing* and a write *throws* — never "all tenants". An accidental leak
has to be impossible, not merely unlikely.

## Install & enable

```bash
composer require latchvector/sso
```

```php
// config/bundles.php
return [
    // …
    LatchVector\Sso\Symfony\LatchVectorSsoBundle::class => ['all' => true],
];
```

You already have the SSO authenticator wired (it verifies the access token). The
bundle extends it to publish the tenant. You still define `TokenVerifier` (it
needs your issuer/audience); the bundle provides the authenticator and everything
else:

```yaml
# config/services.yaml
LatchVector\Sso\TokenVerifier:
    arguments:
        $issuer: '%env(SSO_ISSUER)%'
        $audience: '%env(SSO_AUDIENCE)%'
        $cache: '@cache.app'
# NOTE: remove any manual `LatchVector\Sso\Symfony\SsoAuthenticator` service
# definition — the bundle now registers it (wired to the tenant context).
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

Optionally, let a permission bypass scoping (e.g. a platform operator):

```yaml
# config/packages/latch_vector_sso.yaml
latch_vector_sso:
    tenant:
        bypass_permission: 'platform.admin'   # holders see across tenants; default: none
```

## Make an entity tenant-owned

An entity must declare **how** it is isolated. Org-tree isolation is the default;
an entity that declares neither mode is rejected loudly, so you can never
accidentally leave tenant data visible to the whole tenant.

**Subtree mode (the default)** — confine to the caller's reach *within* the
tenant so a department sees only its own branch. A SELF grant sees exactly its
node, a SUBTREE grant sees its node and everything below — decided by the token.
One trait maps all three columns (`tenant_id`, `org_id`, `org_path`):

```php
use Doctrine\ORM\Mapping as ORM;
use LatchVector\Sso\Doctrine\BelongsToTenantTree;
use LatchVector\Sso\Doctrine\OrgSubtreeAware;

#[ORM\Entity]
class Patient implements OrgSubtreeAware
{
    use BelongsToTenantTree;   // tenant_id + org_id + org_path
    // … your fields …
}
```

**Tenant-wide mode** (the hard wall between customers only — every org under the
tenant sees the rows). Deliberate opt-out for shared reference data:

```php
use LatchVector\Sso\Doctrine\BelongsToTenant;
use LatchVector\Sso\Doctrine\TenantWide;

#[ORM\Entity]
class TenantSetting implements TenantWide
{
    use BelongsToTenant;   // maps the `tenant_id` column + accessors
    // … your fields …
}
```

A machine token has no user reach, so it stays tenant-wide even on a subtree
entity. For a backend acting on behalf of a forwarded user token, adopt the
user's reach with `$tenantContext->fromPrincipal($verifier->verify($userToken))`.

Add the columns with a migration and index them for the boundary:

```sql
ALTER TABLE invoice ADD COLUMN tenant_id BIGINT;
CREATE INDEX idx_invoice_tenant ON invoice (tenant_id);

-- subtree entities also:
ALTER TABLE patient ADD COLUMN tenant_id BIGINT;
ALTER TABLE patient ADD COLUMN org_id    BIGINT;
ALTER TABLE patient ADD COLUMN org_path  VARCHAR(255);
CREATE INDEX idx_patient_tenant ON patient (tenant_id);
CREATE INDEX idx_patient_org ON patient (tenant_id, org_path text_pattern_ops);
```

That's it. `$repo->findAll()` now returns only the caller's tenant, and
`$em->persist(new Invoice())` stamps `tenant_id` automatically.

## Requirements & graceful degradation

The bundle registers the Doctrine boundary only when `doctrine/orm` is installed,
and the authenticator only when Symfony Security is installed. So enabling the
bundle without Doctrine (e.g. you use the SDK only for token verification) does
not break the container — you just don't get the tenant filter. Install
`doctrine/orm` + `doctrine/doctrine-bundle` to get it.

## How it works (one paragraph)

A Doctrine **SQL filter** (`TenantFilter`) is registered *enabled* for the whole
EntityManager and adds `WHERE tenant_id = <caller>` (plus the subtree clause) to
every query on a `TenantAware` entity. A **prePersist listener** stamps new rows.
Both read a per-request `TenantContext` that the authenticator fills from the
verified token. The filter is deliberately always-on so anything that runs before
authentication is denied, not leaked; and it feeds the tenant into Doctrine's
query-cache key so a compiled query is never reused across tenants.

## Fail-closed guarantees (what you can rely on)

| Situation | Read | Write |
|---|---|---|
| Authenticated caller | only their tenant / reach | stamped with their tenant |
| Scoping on, tenant unknown (unauth, forgot to set) | **nothing** (`1 = 0`) | **throws** |
| `bypass_permission` holder | all tenants | not stamped (you set it) |
| Scoping deliberately disabled | all tenants | not stamped (you set it) |

## ⚠️ Escape hatches & caveats (read this)

App-level scoping is strong but not omnipotent. It does **not** cover:

- **Native SQL** — `$conn->executeQuery(...)`, `NativeQuery`, raw DBAL. The filter
  only rewrites DQL/ORM queries. Never hand-write tenant SQL without a
  `tenant_id` clause.
- **Bulk DQL** — `UPDATE`/`DELETE` via DQL and `$qb->delete()` do **not** fire
  `prePersist` and are **not** tenant-filtered. Add the tenant condition yourself,
  or load-and-remove for a handful of rows. Bulk deletes especially.
- **Long-running workers** (Messenger, RoadRunner, Swoole, …) — one PHP process
  serves many messages. In an HTTP request the bundle resets the context per
  request; in a worker consuming messages you must reset it yourself between
  messages (`$tenantContext->forget()` then set the message's tenant), or you'll
  carry one message's tenant into the next.
- **Console commands / migrations / fixtures** run with no authenticated caller,
  so tenant entities are fail-closed (you'll see nothing). To do cross-tenant work
  deliberately, disable scoping for that operation:

  ```php
  $tenantContext->configure(false);   // opt out (the TenantContext service is public)
  // … cross-tenant work …
  ```

- **Warm identity map** — within one request, an entity already loaded is returned
  from memory without re-checking the filter. That only ever returns *your own*
  tenant's already-loaded rows; a fresh `find($otherTenantId)` still hits the DB
  and returns `null`.

## Defense in depth (optional)

This filter is the ergonomic, primary guard for a subscriber app. For a belt-and-
suspenders boundary that even raw SQL can't cross, add PostgreSQL Row-Level
Security on the tenant tables (the SSO service itself does exactly this). The
filter and RLS compose well: the filter keeps your ORM code clean, RLS is the
wall of last resort.

## Laravel

The Laravel equivalent lives in `LatchVector\Sso\Laravel\BelongsToTenant` (an
Eloquent global scope). It follows the same fail-closed rules: an active scope
with no tenant returns no rows and refuses to create.
