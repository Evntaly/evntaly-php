<?php

namespace Evntaly\Integration\Symfony;

use Evntaly\Integration\Symfony\DependencyInjection\EvntalyExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class EvntalyBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
    }

    public function getContainerExtension()
    {
        return new EvntalyExtension();
    }
}
