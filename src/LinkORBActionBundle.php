<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use LinkORB\Bundle\ActionBundle\DependencyInjection\Compiler\ActionPackCompilerPass;
use LinkORB\Bundle\ActionBundle\Service\ActionApprovalService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class LinkORBActionBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode() // @phpstan-ignore-line
            ->children()
                ->arrayNode('approval')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('default_timeout_minutes')
                            ->defaultValue(10)
                            ->min(1)
                        ->end()
                        ->arrayNode('rules')
                            ->defaultValue([
                                ['caller' => '*', 'action' => '*', 'effect' => 'allow'],
                            ])
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('caller')->isRequired()->end()
                                    ->scalarNode('action')->isRequired()->end()
                                    ->variableNode('input')->defaultNull()->end()
                                    ->enumNode('effect')
                                        ->values(['allow', 'deny'])
                                        ->defaultValue('allow')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver(
            ['LinkORB\Bundle\ActionBundle\Entity'],
            [__DIR__ . DIRECTORY_SEPARATOR . 'Entity'],
        ));
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void {
        $container->import('../config/services.yaml');

        /** @var array{approval: array{default_timeout_minutes: int, rules: array<int, array{caller: string, action: string, input?: array<string, mixed>, effect: string}>}} $config */
        $approvalConfig = $config['approval'];

        $builder->getDefinition(ActionApprovalService::class)
            ->setArgument('$rules', $approvalConfig['rules'])
            ->setArgument('$defaultTimeoutMinutes', $approvalConfig['default_timeout_minutes']);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ActionPackCompilerPass());
    }
}

