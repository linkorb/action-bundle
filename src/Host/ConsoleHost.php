<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Host;

use LinkORB\Component\Action\Host\AbstractHost;
use LinkORB\Component\Action\Message\ErrorMessage;
use LinkORB\Component\Action\Message\LogMessage;
use LinkORB\Component\Action\Message\MessageInterface;
use LinkORB\Component\Action\Message\OutputMessage;
use LinkORB\Component\Action\Message\ProgressMessage;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Host for Symfony Console output with formatted messages
 */
class ConsoleHost extends AbstractHost
{
    private OutputInterface $output;
    private ?ProgressBar $progressBar = null;
    private bool $verbose;

    public function __construct(OutputInterface $output, bool $verbose = false)
    {
        $this->output = $output;
        $this->verbose = $verbose;
    }

    public function emit(MessageInterface $message): void
    {
        if ($message instanceof LogMessage) {
            $this->handleLogMessage($message);
        } elseif ($message instanceof ProgressMessage) {
            $this->handleProgressMessage($message);
        } elseif ($message instanceof OutputMessage) {
            $this->handleOutputMessage($message);
        } elseif ($message instanceof ErrorMessage) {
            $this->handleErrorMessage($message);
        } else {
            // Unknown message type, output as JSON
            $this->output->writeln($message->toJson());
        }
    }

    private function handleLogMessage(LogMessage $message): void
    {
        $level = $message->getLevel();
        $text = $message->getMessage();

        // Skip debug messages unless verbose
        if ($level === LogMessage::LEVEL_DEBUG && !$this->verbose) {
            return;
        }

        $prefix = match ($level) {
            LogMessage::LEVEL_ERROR => '<error>ERROR</error>',
            LogMessage::LEVEL_WARNING => '<comment>WARN</comment>',
            LogMessage::LEVEL_DEBUG => '<fg=gray>DEBUG</fg=gray>',
            default => '<info>INFO</info>',
        };

        $this->output->writeln("  {$prefix} {$text}");
    }

    private function handleProgressMessage(ProgressMessage $message): void
    {
        if ($this->progressBar === null && $message->getTotal() > 0) {
            $this->progressBar = new ProgressBar($this->output, $message->getTotal());
            $this->progressBar->start();
        }

        if ($this->progressBar !== null) {
            $this->progressBar->setProgress($message->getCurrent());

            if ($message->getMessage() !== '') {
                $this->progressBar->setMessage($message->getMessage());
            }

            if ($message->getCurrent() >= $message->getTotal()) {
                $this->progressBar->finish();
                $this->output->writeln('');
                $this->progressBar = null;
            }
        }
    }

    private function handleOutputMessage(OutputMessage $message): void
    {
        $this->output->writeln('');
        $this->output->writeln('<info>Output:</info>');
        $json = json_encode($message->getData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->output->writeln($json ?: '{}');
    }

    private function handleErrorMessage(ErrorMessage $message): void
    {
        $this->output->writeln('');
        $this->output->writeln("<error>Error [{$message->getCode()}]: {$message->getMessage()}</error>");

        if ($this->verbose && !empty($message->getDetails())) {
            $this->output->writeln(json_encode($message->getDetails(), JSON_PRETTY_PRINT) ?: '{}');
        }
    }
}




