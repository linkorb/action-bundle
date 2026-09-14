<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Command;

use LinkORB\Bundle\ActionBundle\Service\ActionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'action:list',
    description: 'List all registered actions'
)]
class ActionListCommand extends Command
{
    public function __construct(
        private readonly ActionService $actionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $groupedActions = $this->actionService->getActionsGroupedByPack();

        if (empty($groupedActions)) {
            $io->warning('No actions registered');

            return Command::SUCCESS;
        }

        foreach ($groupedActions as $packName => $actions) {
            $io->section("Pack: {$packName}");

            $rows = [];
            foreach ($actions as $action) {
                $rows[] = [
                    $action->getName(),
                    $action->getDescription(),
                ];
            }

            $io->table(['Name', 'Description'], $rows);
        }

        $totalActions = array_sum(array_map('count', $groupedActions));
        $io->success("Total: {$totalActions} actions in " . count($groupedActions) . ' packs');

        return Command::SUCCESS;
    }
}




