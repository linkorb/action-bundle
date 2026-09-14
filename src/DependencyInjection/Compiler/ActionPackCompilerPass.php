<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\DependencyInjection\Compiler;

use LinkORB\Bundle\ActionBundle\Service\ActionService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers all services tagged with 'action.pack' with the ActionService
 */
class ActionPackCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ActionService::class)) {
            return;
        }

        $definition = $container->findDefinition(ActionService::class);
        $taggedServices = $container->findTaggedServiceIds('action.pack');

        foreach (array_keys($taggedServices) as $id) {
            $definition->addMethodCall('registerActionPack', [new Reference($id)]);
        }
    }
}




