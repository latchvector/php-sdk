<?php

declare(strict_types=1);

namespace LatchVector\Sso\Doctrine;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use LatchVector\Sso\Tenancy\TenantContext;

/**
 * Stamps a new {@see TenantAware} entity with the current tenant on insert (the
 * Doctrine equivalent of the Laravel `creating` hook). Registered on Doctrine's
 * `prePersist` event.
 *
 * FAIL CLOSED on writes too: if scoping is active but no tenant is known, it
 * refuses to persist rather than inserting a NULL-tenant (unscoped, leaky) row.
 * An explicit opt-out ({@see TenantContext::configure} off, or a bypass caller)
 * leaves the entity untouched — the caller owns the columns then.
 */
final class TenantStampListener
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TenantAware) {
            return;
        }

        if (!$this->context->isActive()) {
            return;
        }

        // Mirror the filter: a tenant entity must declare its isolation mode.
        // Fail loud on an undeclared one rather than stamping a row that would
        // then be visible to the whole tenant.
        if (!$entity instanceof OrgSubtreeAware && !$entity instanceof TenantWide) {
            throw new \LogicException(sprintf(
                '%s is tenant-scoped but declares no isolation mode. Implement '
                . 'OrgSubtreeAware (org-tree isolation — the default for tenant data) '
                . 'or TenantWide (deliberately visible to the whole tenant).',
                $entity::class,
            ));
        }

        $tenantId = $this->context->tenantId();
        if ($tenantId === null) {
            throw new \RuntimeException(sprintf(
                'Cannot persist %s: tenant scoping is active but no tenant is set. '
                . 'Authenticate first, or disable scoping deliberately for this operation.',
                $entity::class,
            ));
        }

        if ($entity->getTenantId() === null) {
            $entity->setTenantId($tenantId);
        }

        if ($entity instanceof OrgSubtreeAware) {
            if ($entity->getOrgId() === null && $this->context->ownOrgId() !== null) {
                $entity->setOrgId($this->context->ownOrgId());
            }
            if ($entity->getOrgPath() === null && $this->context->ownOrgPath() !== null) {
                $entity->setOrgPath($this->context->ownOrgPath());
            }
        }
    }
}
