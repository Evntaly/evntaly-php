<?php

namespace Evntaly\Integration\Symfony\DependencyInjection;

use Evntaly\EvntalySDK;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class EvntalyExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );
        $loader->load('services.yaml');

        $definition = new Definition(EvntalySDK::class);
        $definition->setArguments([
            $config['developer_secret'],
            $config['project_token'],
            [
                'verboseLogging' => $config['verbose_logging'],
                'maxBatchSize' => $config['max_batch_size'],
                'autoContext' => $config['auto_context'],
                'sampling' => $config['sampling'],
            ],
        ]);

        $container->setDefinition('evntaly.sdk', $definition);
        $container->setAlias(EvntalySDK::class, 'evntaly.sdk');

        if ($config['auto_instrument']) {
            $this->registerEventSubscribers($container, $config);
        }
    }

    private function registerEventSubscribers(ContainerBuilder $container, array $config)
    {
        $subscriberDefinition = new Definition(EventSubscriber::class);
        $subscriberDefinition->setArguments([
            new Reference('evntaly.sdk'),
            $config,
        ]);
        $subscriberDefinition->addTag('kernel.event_subscriber');

        $container->setDefinition('evntaly.event_subscriber', $subscriberDefinition);
    }
}
