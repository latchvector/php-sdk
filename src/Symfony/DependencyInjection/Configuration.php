<?php

declare(strict_types=1);

namespace LatchVector\Sso\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('latch_vector_sso');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('tenant')
                    ->addDefaultsIfNotSet()
                    ->children()
                        // A permission code whose holder is left unconstrained by
                        // tenant scoping (e.g. a platform operator). Null = nobody.
                        ->scalarNode('bypass_permission')->defaultNull()->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
