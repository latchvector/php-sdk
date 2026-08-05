# Management API

Everything the control panel does, in code: users, organizations, roles,
applications, API clients, webhooks, audit, bulk import, GDPR export/erase, and —
for platform operators — sandbox tenants and PII re-encryption.

`SsoClient` wraps the *auth* endpoints (no token). `ManagementClient` wraps the
*management* endpoints, authenticated with a **management access token**.

## Getting a management token

A management token is a user access token minted with the **audience omitted** —
log in through `SsoClient` with `audience` equal to the `issuer`:

```php
use LatchVector\Sso\SsoClient;
use LatchVector\Sso\ManagementClient;
use LatchVector\Sso\TokenPair;

$issuer = 'https://sso.yourcompany.com';

$sso = new SsoClient($issuer, $issuer);          // audience === issuer ⟹ management token
$result = $sso->login($serviceEmail, $servicePassword);
assert($result instanceof TokenPair);

$mgmt = new ManagementClient($issuer, $result->accessToken);
```

The account needs the relevant permissions (`USER_MANAGE`, `ORG_MANAGE`,
`ROLE_MANAGE`, `CLIENT_MANAGE`, `AUDIT_VIEW`; `PLATFORM_ADMIN` for sandbox/PII).
Use a dedicated service account, not a person.

**Keep it fresh** — pass a callable and the client re-reads it before each call
(and retries once on a 401):

```php
$mgmt = new ManagementClient($issuer, fn () => $currentAccessToken);
```

## Methods

Flat, one per endpoint; bodies are the JSON arrays the service expects.

```php
// Users
$mgmt->listUsers($orgId);
$user = $mgmt->createUser(['organizationId' => $orgId, 'email' => $email, 'fullName' => $name]);
$mgmt->disableUser($user['id']);
$link = $mgmt->userPasswordSetupLink($user['id']);

// Organizations & roles
$mgmt->createOrganization(['name' => $name, 'slug' => $slug, 'parentId' => $parentId]);
$role = $mgmt->createRole(['organizationId' => $orgId, 'name' => 'Doctor',
    'scope' => 'SUBTREE', 'permissionCodes' => ['USER_MANAGE']]);
$mgmt->assignRole($role['id'], $user['id'], $orgId);

// Applications
$app = $mgmt->createApplication(['organizationId' => $orgId, 'identifier' => $id, 'name' => $name]);
$mgmt->addPermission($app['id'], ['code' => 'invoice.approve']);
$mgmt->applicationQuota($orgId);                 // ['used' => .., 'limit' => .. | null]

// Clients / webhooks (secret shown once)
$mgmt->registerClient(['organizationId' => $orgId, 'name' => $name, 'scopes' => ['reports.read']]);
$mgmt->registerWebhook(['organizationId' => $orgId, 'applicationId' => $app['id'], 'url' => $url]);

// Audit, import, GDPR
$mgmt->searchAudit(['organizationId' => $orgId, 'q' => 'role.revoked', 'size' => 50]);
$report = $mgmt->importValidate($payload);
if (empty($report['errors'])) { $mgmt->importCommit($payload); }
$mgmt->eraseUser($user['id']);

// Platform operators (PLATFORM_ADMIN)
$mgmt->provisionSandbox(['orgName' => $name, 'adminEmail' => $email, 'adminFullName' => $full, 'ttlDays' => 3]);
```

## The generic escape hatch

Every endpoint is reachable, including any added after this SDK was published:

```php
$mgmt->request('POST', '/api/some/endpoint',
    query: ['organizationId' => $orgId], body: ['any' => 'json']);
```

Same auth, timeout, typed exceptions and 429-retry as the other calls. A
plan-cap refusal on `createApplication` is a `402` you can catch and surface as
an upgrade prompt.

## Self-service: MFA & devices

The signed-in user's own MFA enrollment and device (mobile) sessions:

```php
$begin = $mgmt->mfaSetupBegin();       // ['secret', 'otpauthUri'] — show a QR
$mgmt->mfaSetupConfirm('123456');      // ['recoveryCodes' => [...]]
$mgmt->mfaDisable();

$mgmt->listMyDevices();                 // active mobile sessions
$mgmt->revokeMyDevice($deviceId);       // log a device out
```
