<?php

declare(strict_types=1);

namespace LatchVector\Sso\Symfony;

use Doctrine\ORM\EntityManagerInterface;
use LatchVector\Sso\Doctrine\TenantFilter;
use LatchVector\Sso\Tenancy\TenantContext;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Keeps Doctrine's tenant filter in step with the request, in two phases:
 *
 *  - {@see reset} runs BEFORE the firewall (high priority): it clears any tenant
 *    left over from a previous request and wires the (now empty) shared context
 *    onto the filter. This matters for long-running workers (Messenger, RoadRunner,
 *    Swoole) where one PHP process serves many requests — without it, one request's
 *    tenant would linger into the next. After the reset the filter is fail closed.
 *
 *  - {@see configure} runs AFTER the firewall (low priority): the authenticator has
 *    populated the context by now, so this re-wires it to refresh the filter's
 *    cache-key discriminator to the authenticated tenant. An unauthenticated
 *    request skips authentication, so the context stays empty and the filter stays
 *    fail closed.
 */
final class TenantFilterConfigurator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $context,
        private readonly string $filterName = 'latchvector_tenant',
    ) {
    }

    public function reset(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->context->forget();
        $this->wire();
    }

    public function configure(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->wire();
    }

    private function wire(): void
    {
        $filters = $this->em->getFilters();
        if (!$filters->isEnabled($this->filterName)) {
            $filters->enable($this->filterName);
        }
        $filter = $filters->getFilter($this->filterName);
        if ($filter instanceof TenantFilter) {
            $filter->setContext($this->context);
        }
    }
}
