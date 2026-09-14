<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Command;

use LinkORB\Bundle\ActionBundle\Service\ActionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'action:view',
    description: 'View action details and schema'
)]
class ActionViewCommand extends Command
{
    public function __construct(
        private readonly ActionService $actionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The action name (e.g., demo.greet)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $actionName */
        $actionName = $input->getArgument('name');

        if (!$this->actionService->hasAction($actionName)) {
            $io->error("Action not found: {$actionName}");

            return Command::FAILURE;
        }

        $action = $this->actionService->getAction($actionName);

        $io->title($action->getName());
        $io->writeln("<info>Description:</info> {$action->getDescription()}");
        $io->newLine();

        $io->section('Input Schema');
        $io->writeln(json_encode($action->getInputSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        $io->newLine();

        $io->section('Output Schema');
        $io->writeln(json_encode($action->getOutputSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return Command::SUCCESS;
    }
}




