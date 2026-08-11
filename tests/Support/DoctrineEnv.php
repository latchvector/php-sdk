<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Support;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use LatchVector\Sso\Doctrine\TenantFilter;
use LatchVector\Sso\Doctrine\TenantStampListener;
use LatchVector\Sso\Tenancy\TenantContext;

/** Builds an in-memory Doctrine EntityManager wired with the tenant boundary. */
final class DoctrineEnv
{
    /** @param bool $enableFilter enable the filter now, or leave it for the configurator */
    public static function create(TenantContext $context, bool $enableFilter = true): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__) . '/Doctrine/Fixtures'],
            true,
        );
        $config->enableNativeLazyObjects(true);
        $config->addFilter('latchvector_tenant', TenantFilter::class);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);

        $eventManager = new EventManager();
        $eventManager->addEventListener([Events::prePersist], new TenantStampListener($context));

        $em = new EntityManager($connection, $config, $eventManager);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        if ($enableFilter) {
            $em->getFilters()->enable('latchvector_tenant')->setContext($context);
        }

        return $em;
    }
}
