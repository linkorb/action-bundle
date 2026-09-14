<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Command;

use LinkORB\Bundle\ActionBundle\Service\ActionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

/**
 * Lazy command loader that creates console commands for all registered actions
 */
class ActionCommandLoader implements CommandLoaderInterface
{
    public function __construct(
        private readonly ActionService $actionService,
    ) {
    }

    /**
     * Convert action name to command name (e.g., 'weather.current' → 'action:weather:current')
     */
    public static function actionNameToCommandName(string $actionName): string
    {
        return 'action:' . str_replace('.', ':', $actionName);
    }

    /**
     * Convert command name to action name (e.g., 'action:weather:current' → 'weather.current')
     */
    public static function commandNameToActionName(string $commandName): string
    {
        if (!str_starts_with($commandName, 'action:')) {
            throw new \InvalidArgumentException("Invalid action command name: {$commandName}");
        }

        $withoutPrefix = substr($commandName, 7); // Remove 'action:'

        return str_replace(':', '.', $withoutPrefix);
    }

    public function get(string $name): Command
    {
        $actionName = self::commandNameToActionName($name);

        if (!$this->actionService->hasAction($actionName)) {
            throw new \InvalidArgumentException("Action not found: {$actionName}");
        }

        $action = $this->actionService->getAction($actionName);

        return new DynamicActionCommand($action, $name, $this->actionService);
    }

    public function has(string $name): bool
    {
        if (!str_starts_with($name, 'action:')) {
            return false;
        }

        // Skip built-in action commands
        if (in_array($name, ['action:list', 'action:run', 'action:view'], true)) {
            return false;
        }

        $actionName = self::commandNameToActionName($name);

        return $this->actionService->hasAction($actionName);
    }

    /**
     * @return string[]
     */
    public function getNames(): array
    {
        $names = [];

        foreach ($this->actionService->getActions() as $action) {
            $names[] = self::actionNameToCommandName($action->getName());
        }

        return $names;
    }
}


