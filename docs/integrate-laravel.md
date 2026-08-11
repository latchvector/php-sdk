# Latch Vector SSO — Laravel integration

## 1. Install

```bash
composer require latchvector/sso
```

The service provider auto-registers (Laravel package discovery).

## 2. Configure

```bash
php artisan vendor:publish --tag=latchvector-sso-config
```

`.env`:

```dotenv
SSO_ISSUER=https://sso-sbx.latchvector.com   # your SSO base URL
SSO_AUDIENCE=your-app-identifier             # this app's registered id
SSO_TENANT_SCOPING=true                      # false only in local/sandbox
```

Only `issuer` is a URL; the JWKS is discovered from it. `audience` is mandatory.

## 3. Protect routes

Middleware aliases (registered for you):

| Alias | Purpose |
|---|---|
| `sso.auth` | require a valid **user** token |
| `sso.can:CODE` | require permission `CODE` |
| `sso.client` | require a **machine** (client_credentials) token |
| `sso.scope:CODE` | require scope `CODE` (machine) |

```php
Route::middleware('sso.auth')->get('/api/me', fn (Request $r) => $r->principal());

Route::middleware(['sso.auth', 'sso.can:invoice.read'])
    ->get('/api/invoices', [InvoiceController::class, 'index']);
```

`$request->principal()` → a `Principal` (`uid`, `tenantId`, `orgId`, `orgPath`,
`permissions`, `scopeSelf`, `scopeSubtree`). The middleware also fills the tenant
context, so the next step works automatically.

## 4. Tenant boundary on a model

The trait is **org-tree isolated by default** — sibling orgs in the same tenant
never see each other's rows. Give the table `tenant_id`, `org_id`, `org_path`:

```php
use LatchVector\Sso\Laravel\BelongsToTenant;

class Patient extends Model
{
    use BelongsToTenant;   // subtree (default): tenant_id + org_id + org_path
}
```

For genuinely tenant-wide data (visible to every org under the tenant) opt out
explicitly — only `tenant_id` needed:

```php
class TenantSetting extends Model
{
    use BelongsToTenant;
    protected string $tenantScope = 'tenant';
}
```

`Patient::all()` returns only the caller's reach; `Patient::create([...])` stamps
`tenant_id` + the caller's own `org_id`/`org_path`. What's visible is decided by
the token: a SELF grant sees exactly its node, a SUBTREE grant sees its node and
everything below. **Fail closed**: scoping on with no tenant → reads return
nothing, writes throw; a subtree table without an `org_path` column fails loudly,
never leaks. Holders of any `tenant.bypass_permissions` code (default
`PLATFORM_ADMIN`) see across tenants. Escape deliberately with
`Patient::allTenants()`.

**Machine tokens** (client_credentials) carry no user org reach, so they stay
**tenant-wide** on a subtree table (still walled between tenants). For a backend
that authenticates with a machine token but acts on behalf of an end user,
forward the user's token and adopt its reach so scoping follows the user:

```php
$user = app(TokenVerifier::class)->verify($forwardedUserToken);
app(TenantContext::class)->fromPrincipal($user);   // now models scope to the user's subtree
```

## 5. Filter within your subtree by a chosen org

To let a caller narrow to one org inside their reach (e.g. I'm `/2/`, show me
only `/2/5/`):

```php
Post::forOrg('/2/5/')->get();        // exactly that node (SELF)
Post::forOrg('/2/5/', true)->get();  // that node and everything below (SUBTREE)
Post::forOrgId(5)->get();            // a single node by org id
```

This only ever **narrows** the always-on tenant scope. An org outside the
token's reach throws `OrgReachException` (raised as Laravel's
`AuthorizationException` → HTTP 403 when available); `includeSubtree = true`
additionally requires a SUBTREE grant over that node. `forOrgId` is safe by
construction — an out-of-reach id simply returns no rows. Map the exception to
403 in `bootstrap/app.php`:

```php
->withExceptions(function ($e) {
    $e->render(fn (\LatchVector\Sso\Tenancy\OrgReachException $ex) =>
        response()->json(['error' => 'forbidden'], 403));
});
```

## 6. Test it, step by step

**Auth** (behind `sso.auth`):

```bash
curl -i -H "Authorization: Bearer <ACCESS_TOKEN>" http://localhost:8000/api/me   # 200 + principal
curl -i http://localhost:8000/api/me                                             # 401
```

**Tenant isolation** (a feature test):

```php
public function test_scoped_to_the_caller_tenant(): void
{
    app(TenantContext::class)->configure(false);          // seed across tenants
    Invoice::insert([['tenant_id' => 10, /* … */], ['tenant_id' => 20, /* … */]]);
    app(TenantContext::class)->configure(true);

    app(TenantContext::class)->set(tenantId: 10);          // act as tenant 10
    $this->assertSame(1, Invoice::query()->count());       // sees only its own

    app(TenantContext::class)->set(tenantId: null);        // active, no tenant
    $this->assertSame(0, Invoice::query()->count());       // fail closed
}
```

The SDK ships its own proof of these rules: `composer test` (see
`tests/Laravel/TenantScopeTest.php`).
