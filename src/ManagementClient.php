<?php

declare(strict_types=1);

namespace LatchVector\Sso;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use LatchVector\Sso\Exception\ConfigurationException;
use LatchVector\Sso\Exception\ErrorMapper;
use LatchVector\Sso\Exception\RateLimitException;
use Throwable;

/**
 * The management API — everything the console does, in code: users,
 * organizations, roles, applications, API clients, webhooks, audit, bulk import,
 * GDPR export/erase, and (platform operators) sandbox tenants + PII re-encryption.
 *
 * Unlike {@see SsoClient} (the auth endpoints, no token), every call here is
 * authenticated with a MANAGEMENT access token — one minted with the audience
 * omitted, i.e. logging in through SsoClient with audience === issuer. Pass the
 * token as a string, or a callable so the client can pick up a refreshed one (it
 * re-reads it and retries once on a 401).
 *
 * Every endpoint is reachable: the grouped resources are convenience over
 * {@see ManagementClient::request()}, which can call anything.
 */
final class ManagementClient
{
    private readonly string $issuer;
    /** @var string|callable():string */
    private $token;
    private ClientInterface $http;

    /**
     * @param string|callable():string $token A management access token, or a callable returning one.
     */
    public function __construct(
        string $issuer,
        string|callable $token,
        private readonly int $maxRateLimitRetries = 2,
        ?ClientInterface $httpClient = null,
        float $timeoutSeconds = 10.0,
    ) {
        if ($issuer === '') {
            throw new ConfigurationException('issuer is required');
        }
        $this->issuer = rtrim($issuer, '/');
        $this->token = $token;
        $this->http = $httpClient ?? new HttpClient(['timeout' => $timeoutSeconds]);
    }

    // ---- Users ----
    /** @return array<int, array<string, mixed>> */
    public function listUsers(int $organizationId, ?int $size = null): array
    {
        return $this->request('GET', '/api/users', ['organizationId' => $organizationId, 'size' => $size]);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function createUser(array $body): array
    {
        return $this->request('POST', '/api/users', null, $body);
    }

    /** @param array<string, mixed> $body */
    public function updateUser(int $id, array $body): void
    {
        $this->request('PUT', "/api/users/{$id}", null, $body);
    }

    public function disableUser(int $id): void
    {
        $this->request('POST', "/api/users/{$id}/disable");
    }

    public function enableUser(int $id): void
    {
        $this->request('POST', "/api/users/{$id}/enable");
    }

    /** @return array<string, mixed> */
    public function userPasswordSetupLink(int $id): array
    {
        return $this->request('POST', "/api/users/{$id}/password-setup-link");
    }

    // ---- Organizations ----
    /** @return array<string, mixed> */
    public function listOrganizations(?string $after = null, ?int $size = null): array
    {
        return $this->request('GET', '/api/organizations', ['after' => $after, 'size' => $size]);
    }

    /** @return array<int, array<string, mixed>> */
    public function searchOrganizations(string $q = '', ?string $status = null, ?int $size = null): array
    {
        return $this->request('GET', '/api/organizations/search', ['q' => $q, 'status' => $status, 'size' => $size]);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function createOrganization(array $body): array
    {
        return $this->request('POST', '/api/organizations', null, $body);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function updateOrganization(int $id, array $body): array
    {
        return $this->request('PUT', "/api/organizations/{$id}", null, $body);
    }

    /** @return array<string, mixed> */
    public function suspendOrganization(int $id): array
    {
        return $this->request('POST', "/api/organizations/{$id}/suspend");
    }

    /** @return array<string, mixed> */
    public function activateOrganization(int $id): array
    {
        return $this->request('POST', "/api/organizations/{$id}/activate");
    }

    // ---- Roles ----
    /** @return array<int, array<string, mixed>> */
    public function listRoles(int $organizationId, ?int $size = null): array
    {
        return $this->request('GET', '/api/roles', ['organizationId' => $organizationId, 'size' => $size]);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function createRole(array $body): array
    {
        return $this->request('POST', '/api/roles', null, $body);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function updateRolePermissions(int $roleId, array $body): array
    {
        return $this->request('PUT', "/api/roles/{$roleId}/permissions", null, $body);
    }

    public function assignRole(int $roleId, int $userId, int $organizationId): void
    {
        $this->request('POST', "/api/roles/{$roleId}/assign", null,
            ['userId' => $userId, 'organizationId' => $organizationId]);
    }

    public function revokeRole(int $roleId, int $userId, int $organizationId): void
    {
        $this->request('POST', "/api/roles/{$roleId}/revoke", null,
            ['userId' => $userId, 'organizationId' => $organizationId]);
    }

    // ---- Applications ----
    /** @return array<int, array<string, mixed>> */
    public function listApplications(int $organizationId): array
    {
        return $this->request('GET', '/api/applications', ['organizationId' => $organizationId]);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function createApplication(array $body): array
    {
        return $this->request('POST', '/api/applications', null, $body);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function updateApplication(int $applicationId, array $body): array
    {
        return $this->request('PUT', "/api/applications/{$applicationId}", null, $body);
    }

    /** @return array<string, mixed> */
    public function applicationQuota(int $organizationId): array
    {
        return $this->request('GET', '/api/applications/quota', ['organizationId' => $organizationId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function listPermissions(int $applicationId): array
    {
        return $this->request('GET', "/api/applications/{$applicationId}/permissions");
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function addPermission(int $applicationId, array $body): array
    {
        return $this->request('POST', "/api/applications/{$applicationId}/permissions", null, $body);
    }

    public function deletePermission(int $applicationId, int $permissionId): void
    {
        $this->request('DELETE', "/api/applications/{$applicationId}/permissions/{$permissionId}");
    }

    // ---- API clients ----
    /** @return array<int, array<string, mixed>> */
    public function listClients(int $organizationId): array
    {
        return $this->request('GET', '/api/clients', ['organizationId' => $organizationId]);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function registerClient(array $body): array
    {
        return $this->request('POST', '/api/clients', null, $body);
    }

    // ---- Webhooks ----
    /** @return array<int, array<string, mixed>> */
    public function listWebhooks(int $organizationId): array
    {
        return $this->request('GET', '/api/webhooks', ['organizationId' => $organizationId]);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function registerWebhook(array $body): array
    {
        return $this->request('POST', '/api/webhooks', null, $body);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function updateWebhook(int $id, array $body): array
    {
        return $this->request('PUT', "/api/webhooks/{$id}", null, $body);
    }

    /** @return array<string, mixed> */
    public function rotateWebhookSecret(int $id): array
    {
        return $this->request('POST', "/api/webhooks/{$id}/rotate-secret");
    }

    public function deleteWebhook(int $id): void
    {
        $this->request('DELETE', "/api/webhooks/{$id}");
    }

    // ---- Audit ----
    /** @param array<string, mixed> $query @return array<int, array<string, mixed>> */
    public function searchAudit(array $query): array
    {
        return $this->request('GET', '/api/audit', $query);
    }

    // ---- Bulk import ----
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function importValidate(array $payload): array
    {
        return $this->request('POST', '/api/import/validate', null, $payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function importCommit(array $payload): array
    {
        return $this->request('POST', '/api/import/commit', null, $payload);
    }

    /** @return array<int, array<string, mixed>> */
    public function importJobs(?int $limit = null): array
    {
        return $this->request('GET', '/api/import/jobs', ['limit' => $limit]);
    }

    // ---- GDPR ----
    /** @return array<string, mixed> */
    public function exportMe(): array
    {
        return $this->request('GET', '/api/users/me/export');
    }

    /** @return array<string, mixed> */
    public function exportUser(int $id): array
    {
        return $this->request('GET', "/api/users/{$id}/export");
    }

    public function eraseUser(int $id): void
    {
        $this->request('POST', "/api/users/{$id}/erase");
    }

    // ---- Platform operators ----
    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function provisionSandbox(array $body): array
    {
        return $this->request('POST', '/api/sandbox/tenants', null, $body);
    }

    /** @return array<int, array<string, mixed>> */
    public function listSandboxTenants(): array
    {
        return $this->request('GET', '/api/sandbox/tenants');
    }

    /** @return array<string, mixed> */
    public function reencryptPii(): array
    {
        return $this->request('POST', '/api/admin/pii/reencrypt');
    }

    // ---- Signed-in user's own MFA enrollment + devices (the token is theirs) ----
    /** @return array<string, mixed> */
    public function mfaSetupBegin(): array
    {
        return $this->request('POST', '/api/mfa/setup/begin');
    }

    /** @return array<string, mixed> */
    public function mfaSetupConfirm(string $code): array
    {
        return $this->request('POST', '/api/mfa/setup/confirm', null, ['code' => $code]);
    }

    public function mfaDisable(): void
    {
        $this->request('POST', '/api/mfa/disable');
    }

    /** @return array<int, array<string, mixed>> */
    public function listMyDevices(): array
    {
        return $this->request('GET', '/api/users/me/devices');
    }

    public function revokeMyDevice(string $deviceId): void
    {
        $this->request('DELETE', '/api/users/me/devices/'.rawurlencode($deviceId));
    }

    /**
     * Call any management endpoint. The methods above wrap this; use it directly
     * for anything they do not cover (including endpoints newer than this SDK).
     * Re-reads a callable token and retries once on a 401.
     *
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $query = null, ?array $body = null): array
    {
        $attempt = 0;
        $refreshed = false;

        while (true) {
            $options = [
                'http_errors' => false,
                'headers' => ['Authorization' => 'Bearer '.$this->resolveToken()],
            ];
            if ($query !== null) {
                $options['query'] = array_filter($query, static fn ($v) => $v !== null && $v !== '');
            }
            if ($body !== null) {
                $options['json'] = $body;
            }

            $response = $this->http->request($method, $this->issuer.$path, $options);
            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();

            if ($status >= 200 && $status < 300) {
                if ($raw === '') {
                    return [];
                }
                try {
                    $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                } catch (Throwable $cause) {
                    throw new ConfigurationException('Malformed JSON from '.$path.': '.$cause->getMessage());
                }

                return is_array($decoded) ? $decoded : [];
            }

            $decoded = json_decode($raw, true);

            if ($status === 401 && !$refreshed && \is_callable($this->token)) {
                $refreshed = true;
                continue;
            }

            $error = ErrorMapper::map(
                $status,
                is_array($decoded) ? $decoded : null,
                $response->getHeaderLine('Retry-After') ?: null,
            );

            if ($error instanceof RateLimitException && $attempt < $this->maxRateLimitRetries) {
                usleep((int) ($this->backoffSeconds($attempt, $error->retryAfterSeconds) * 1_000_000));
                ++$attempt;
                continue;
            }

            throw $error;
        }
    }

    private function resolveToken(): string
    {
        return \is_callable($this->token) ? ($this->token)() : $this->token;
    }

    private function backoffSeconds(int $attempt, ?float $retryAfter): float
    {
        if ($retryAfter !== null) {
            return $retryAfter;
        }

        return (mt_rand() / mt_getrandmax()) * min(2 ** $attempt, 8);
    }
}
