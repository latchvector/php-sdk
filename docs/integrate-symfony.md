# Latch Vector SSO — Symfony integration

## 1. Install

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

For the Doctrine tenant boundary you also need `doctrine/orm` +
`doctrine/doctrine-bundle` (without them the bundle simply skips it — no error).

## 2. Configure

Define `TokenVerifier` (it needs your issuer/audience); the bundle wires the rest:

```yaml
# config/services.yaml
LatchVector\Sso\TokenVerifier:
    arguments:
        $issuer: '%env(SSO_ISSUER)%'
        $audience: '%env(SSO_AUDIENCE)%'
        $cache: '@cache.app'
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

Optional — a permission that bypasses tenant scoping (platform operators):

```yaml
# config/packages/latch_vector_sso.yaml
latch_vector_sso:
    tenant:
        bypass_permission: 'PLATFORM_ADMIN'
```

> If you had a manual `SsoAuthenticator` service definition, remove it — the
> bundle registers it now (wired to the tenant context).

Permission codes become roles, so `#[IsGranted('invoice.read')]` works.

## 3. Tenant boundary on an entity

Every tenant entity must declare **how** it is isolated — org-tree isolation is
the default; declaring neither is rejected loudly (never a silent whole-tenant
leak).

**Org-tree isolated (default)** — one trait maps all three columns
(`tenant_id`, `org_id`, `org_path`); sibling orgs can't see each other:

```php
use LatchVector\Sso\Doctrine\{BelongsToTenantTree, OrgSubtreeAware};

#[ORM\Entity]
class Patient implements OrgSubtreeAware
{
    use BelongsToTenantTree;
}
```

**Deliberately tenant-wide** (shared reference data) — only `tenant_id`:

```php
use LatchVector\Sso\Doctrine\{BelongsToTenant, TenantWide};

#[ORM\Entity]
class TenantSetting implements TenantWide
{
    use BelongsToTenant;
}
```

Every query on the entity is confined to the caller's tenant/reach, and new rows
are stamped on insert (subtree entities also get the caller's own
`org_id`/`org_path`). **Fail closed**: scoping on with no tenant returns nothing
/ throws on write; an entity implementing plain `TenantAware` only (no mode) is
rejected. Index `(tenant_id)` and, for subtree, `(tenant_id, org_path
text_pattern_ops)`.

**Machine tokens** stay tenant-wide (no user reach). For a backend acting on
behalf of a forwarded user token, adopt its reach: `$context->fromPrincipal($verifier->verify($userToken))`.
The `SsoClientAuthenticator` firewall, registering the client and obtaining the
token: **[machine-to-machine.md](machine-to-machine.md)**.

⚠️ Not covered (add the `tenant_id` clause yourself): native SQL, bulk DQL
`UPDATE`/`DELETE`. In long-running workers reset the context per message
(`$context->forget()`, then set the message's tenant).

## 4. Filter within your subtree by a chosen org

To narrow to one org inside the caller's reach, add the condition to a
QueryBuilder with `OrgScope`:

```php
use LatchVector\Sso\Doctrine\OrgScope;

$qb = $em->createQueryBuilder()->select('p')->from(Patient::class, 'p');
OrgScope::apply($qb, 'p', '/2/5/', includeSubtree: false, context: $context);  // that node
OrgScope::apply($qb, 'p', '/2/5/', includeSubtree: true,  context: $context);  // node + below
OrgScope::byId($qb, 'p', 5);                                                    // by org id
```

It only **narrows** the always-on tenant filter. An org outside the token's reach
throws `OrgReachException` — map it to 403 (e.g. rethrow `AccessDeniedException`
in an exception listener); `includeSubtree = true` requires a SUBTREE grant over
the node. `byId` is safe by construction (out-of-reach id → no rows).

## 5. Test it, step by step

**Auth** (behind the `^/api` firewall):

```bash
curl -i -H "Authorization: Bearer <ACCESS_TOKEN>" http://localhost:8000/api/me   # 200
curl -i http://localhost:8000/api/me                                             # 401
```

**Tenant isolation** (get the services from the test container):

```php
$em      = self::getContainer()->get(EntityManagerInterface::class);
$context = self::getContainer()->get(TenantContext::class);

$context->set(tenantId: 10);
$mine = $em->getRepository(Invoice::class)->findAll();   // only tenant 10

$context->set(tenantId: null);                            // active, no tenant
$none = $em->getRepository(Invoice::class)->findAll();    // [] — fail closed
```

The SDK ships end-to-end proof (real kernel boot, filter scoping, cache-safety,
authenticator → context): `composer test` (`tests/Symfony/*`).
