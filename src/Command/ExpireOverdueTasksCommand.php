<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TaskUserRepository;
use App\Service\TaskWorkflowService;
use App\Service\Time\UserClock;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Marks open claims whose deadline (stored in UTC, resolved from the user's local time at claim time) has passed.
 * Idempotent and safe to run concurrently: every row is re-checked under a row lock inside TaskWorkflowService::expire().
 */
#[AsCommand(name: 'app:expire-overdue-tasks', description: 'Expire open task claims whose deadline has passed')]
final class ExpireOverdueTasksCommand extends Command
{
    public function __construct(
        private readonly TaskUserRepository $taskUsers,
        private readonly TaskWorkflowService $workflow,
        private readonly UserClock $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows fetched per round', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be expired');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = (int) $input->getOption('batch');
        if ($batch < 1 || $batch > 5000) {
            $io->error('--batch must be between 1 and 5000.');

            return Command::INVALID;
        }

        $now = $this->clock->nowUtc();
        $seen = [];
        $expired = 0;
        $skipped = 0;
        $failed = 0;

        do {
            $ids = array_values(array_diff($this->taskUsers->findOverdueIds($now, $batch), $seen));
            foreach ($ids as $id) {
                $seen[] = $id;
                if ($input->getOption('dry-run')) {
                    ++$expired;
                    continue;
                }
                try {
                    $this->workflow->expire($id) ? ++$expired : ++$skipped;
                } catch (\Throwable $e) {
                    // one bad row must not stop the run; the next run retries it
                    ++$failed;
                    $this->logger->error('Failed to expire task_user', ['task_user_id' => $id, 'exception' => $e]);
                }
            }
        } while ([] !== $ids);

        $summary = ['expired' => $expired, 'skipped' => $skipped, 'failed' => $failed, 'dry_run' => (bool) $input->getOption('dry-run')];
        $this->logger->info('Expire-overdue run finished', $summary);
        $io->success(\sprintf('%s%d expired, %d skipped (already handled), %d failed.', $input->getOption('dry-run') ? '[dry-run] ' : '', $expired, $skipped, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
