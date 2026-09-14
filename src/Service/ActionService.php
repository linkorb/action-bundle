<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Service;

use LinkORB\Component\Action\Action;
use LinkORB\Component\Action\ActionManager;
use LinkORB\Component\Action\ActionResult;
use LinkORB\Component\Action\Factory\ActionPackFactory;
use LinkORB\Component\Action\Factory\ConfigFactory;
use LinkORB\Component\Action\Host\HostInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing and executing actions in Symfony
 */
class ActionService
{
    private ActionManager $manager;
    private ActionPackFactory $packFactory;
    private ConfigFactory $configFactory;
    private LoggerInterface $logger;

    public function __construct(
        ActionManager $manager,
        ActionPackFactory $packFactory,
        ConfigFactory $configFactory,
        LoggerInterface $logger,
    ) {
        $this->manager = $manager;
        $this->packFactory = $packFactory;
        $this->configFactory = $configFactory;
        $this->logger = $logger;
    }

    /**
     * Register an ActionPack
     */
    public function registerActionPack(object $pack): void
    {
        $this->packFactory->registerActionPack($this->manager, $pack);
    }

    /**
     * Load actions from a configuration file
     */
    public function loadConfigFile(string $path): void
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === 'yaml' || $extension === 'yml') {
            $this->configFactory->loadFromYaml($this->manager, $path);
        } elseif ($extension === 'json') {
            $this->configFactory->loadFromJson($this->manager, $path);
        } else {
            throw new \InvalidArgumentException("Unsupported config file type: {$extension}");
        }
    }

    /**
     * Get the ActionManager
     */
    public function getManager(): ActionManager
    {
        return $this->manager;
    }

    /**
     * Get all actions
     *
     * @return array<string, Action>
     */
    public function getActions(): array
    {
        return $this->manager->getActions();
    }

    /**
     * Get actions grouped by pack
     *
     * @return array<string, array<string, Action>>
     */
    public function getActionsGroupedByPack(): array
    {
        return $this->manager->getActionsGroupedByPack();
    }

    /**
     * Get an action by name
     */
    public function getAction(string $name): Action
    {
        return $this->manager->getAction($name);
    }

    /**
     * Check if an action exists
     */
    public function hasAction(string $name): bool
    {
        return $this->manager->hasAction($name);
    }

    /**
     * Execute an action
     *
     * @param array<string, mixed> $input
     */
    public function executeAction(string $name, array $input, ?HostInterface $host = null, string $transport = 'unknown'): ActionResult
    {
        $action = $this->manager->getAction($name);

        $result = $action->execute($input, $host);

        $this->logActionOutcome($name, $transport, $result->isSuccess() ? 'success' : 'error');

        return $result;
    }

    /**
     * Log an action invocation outcome for usage telemetry
     */
    public function logActionOutcome(string $name, string $transport, string $outcome): void
    {
        $this->logger->info('action.execute', ['action' => $name, 'transport' => $transport, 'outcome' => $outcome]);
    }
}




