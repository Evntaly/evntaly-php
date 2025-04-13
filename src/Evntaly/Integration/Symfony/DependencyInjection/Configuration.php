<?php

namespace Evntaly\Integration\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('evntaly');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('developer_secret')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('project_token')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->booleanNode('verbose_logging')
                    ->defaultFalse()
                ->end()
                ->integerNode('max_batch_size')
                    ->defaultValue(10)
                ->end()
                ->booleanNode('auto_context')
                    ->defaultTrue()
                ->end()
                ->booleanNode('auto_instrument')
                    ->defaultTrue()
                ->end()
                ->booleanNode('track_queries')
                    ->defaultTrue()
                ->end()
                ->integerNode('min_query_time')
                    ->defaultValue(100)
                ->end()
                ->booleanNode('track_routes')
                    ->defaultTrue()
                ->end()
                ->booleanNode('track_auth')
                    ->defaultTrue()
                ->end()
                ->arrayNode('sampling')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('rate')
                            ->defaultValue(1.0)
                        ->end()
                        ->arrayNode('priorityEvents')
                            ->scalarPrototype()->end()
                            ->defaultValue(['error', 'exception', 'security', 'payment', 'auth'])
                        ->end()
                        ->arrayNode('typeRates')
                            ->useAttributeAsKey('type')
                            ->scalarPrototype()->end()
                            ->defaultValue([
                                'query' => 0.1,
                                'route' => 0.5,
                            ])
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('track_performance')
                    ->defaultTrue()
                ->end()
                ->booleanNode('auto_track_performance')
                    ->defaultTrue()
                ->end()
                ->arrayNode('performance_thresholds')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('slow')->defaultValue(1000)->end()
                        ->integerNode('warning')->defaultValue(500)->end()
                        ->integerNode('acceptable')->defaultValue(100)->end()
                    ->end()
                ->end()
                ->scalarNode('webhook_secret')
                    ->defaultValue('')
                ->end()
                ->booleanNode('realtime_enabled')
                    ->defaultFalse()
                ->end()
                ->scalarNode('realtime_server')
                    ->defaultValue('wss://realtime.evntaly.com')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
