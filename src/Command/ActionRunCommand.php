<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Command;

use LinkORB\Bundle\ActionBundle\Service\ActionService;
use LinkORB\Component\Action\Host\CallbackHost;
use LinkORB\Component\Action\Message\ErrorMessage;
use LinkORB\Component\Action\Message\LogMessage;
use LinkORB\Component\Action\Message\OutputMessage;
use LinkORB\Component\Action\Message\ProgressMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'action:run',
    description: 'Execute an action'
)]
class ActionRunCommand extends Command
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
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'JSON input (or @filename to read from file)', '{}')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output format: pretty (default) or ndjson', 'pretty')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $actionName */
        $actionName = $input->getArgument('name');

        /** @var string $inputJson */
        $inputJson = $input->getOption('input');

        /** @var string $outputFormat */
        $outputFormat = $input->getOption('output');
        $ndjson = $outputFormat === 'ndjson';

        // Load input from file if starts with @
        if (str_starts_with($inputJson, '@')) {
            $filename = substr($inputJson, 1);
            $content = file_get_contents($filename);
            if ($content === false) {
                $io->error("Failed to read input file: {$filename}");

                return Command::FAILURE;
            }
            $inputJson = $content;
        }

        // Parse input JSON
        /** @var array<string, mixed>|null $inputData */
        $inputData = json_decode($inputJson, true);
        if ($inputData === null && $inputJson !== '{}' && $inputJson !== '') {
            $io->error('Invalid JSON input: ' . json_last_error_msg());

            return Command::FAILURE;
        }

        // Check if action exists
        if (!$this->actionService->hasAction($actionName)) {
            $io->error("Action not found: {$actionName}");

            return Command::FAILURE;
        }

        $action = $this->actionService->getAction($actionName);

        if (!$ndjson) {
            $io->title("Executing: {$actionName}");
            $io->writeln("<info>Description:</info> {$action->getDescription()}");
            $io->newLine();
        }

        // Create host based on output mode
        $progressBar = null;
        $host = new CallbackHost(function ($message) use ($output, $io, $ndjson, &$progressBar) {
            if ($ndjson) {
                $output->writeln($message->toJson());

                return;
            }

            if ($message instanceof LogMessage) {
                $level = $message->getLevel();
                $text = $message->getMessage();

                match ($level) {
                    'error' => $io->error($text),
                    'warning' => $io->warning($text),
                    'debug' => $output->isVerbose() ? $io->comment("[DEBUG] {$text}") : null,
                    default => $io->writeln("  <info>→</info> {$text}"),
                };
            } elseif ($message instanceof ProgressMessage) {
                if ($progressBar === null && $message->getTotal() > 0) {
                    $progressBar = new ProgressBar($output, $message->getTotal());
                    $progressBar->start();
                }

                if ($progressBar !== null) {
                    $progressBar->setProgress($message->getCurrent());
                    if ($message->getMessage() !== '') {
                        $progressBar->setMessage($message->getMessage());
                    }

                    if ($message->getCurrent() >= $message->getTotal()) {
                        $progressBar->finish();
                        $io->newLine(2);
                        $progressBar = null;
                    }
                }
            } elseif ($message instanceof OutputMessage) {
                $io->newLine();
                $io->section('Output');
                $io->writeln(json_encode($message->getData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
            } elseif ($message instanceof ErrorMessage) {
                $io->error($message->getMessage());
            }
        });

        // Execute action
        $result = $this->actionService->executeAction($actionName, $inputData ?? [], $host, 'console');

        if (!$ndjson) {
            $io->newLine();

            if ($result->isSuccess()) {
                $io->success('Action completed successfully');
            } else {
                $io->error('Action failed: ' . ($result->getError() ?? 'Unknown error'));

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}

