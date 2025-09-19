<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Command\Cleanup;

use Doctrine\DBAL\ParameterType;
use Elementareteilchen\Housekeeper\Command\AbstractCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Cleanup sys_log table from noisy or outdated entries
 *
 * Deletes entries matching specific patterns and entries older than a
 * configurable number of days. Supports dry-run to preview deletions.
 */
class SysLogCleanupCommand extends AbstractCommand
{

	public function configure()
	{
		$this->setDescription('Cleanup TYPO3 sys_log table from outdated entries.');
		$this->setHelp('
This command deletes entries from sys_log if they are older than --retentionPeriod and match one of the following criteria:
 - details LIKE "%has cleared the cache%"
 - details LIKE "[scheduler%"
 - details LIKE "User %s logged in from%"
 - details LIKE "%was deleted unrecoverable%"
 - error > 0
 - tstamp older than --cutoffPeriod (default 360)

Use --dry-run to see row count without deleting.');

        $this->addCommonOptions();
		$this->addOption(
			'cutoffPeriod',
			'c',
			InputOption::VALUE_OPTIONAL,
			'Any row older than this value will be deleted (in days).',
			'360'
		);
        $this->addOption(
            'retentionPeriod',
            'r',
            InputOption::VALUE_OPTIONAL,
            'Any row younger than this value will not be deleted (in days).',
            '10'
        );
	}

	public function execute(InputInterface $input, OutputInterface $output): int
	{
        try {
            $this->initializeCommand($input, $output);
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }

        $cutoffPeriod = (int)($input->getOption('cutoffPeriod') ?? 360);
        $retentionPeriod = (int)($input->getOption('retentionPeriod') ?? 10);
        $cutoffTstamp = time() - ($cutoffPeriod * 86400);
        $retentionTstamp = time() - ($retentionPeriod * 86400);

        if ($retentionPeriod >= $cutoffPeriod) {
            $this->io->error('Invalid options: --retentionPeriod must be less than --cutoffPeriod');
            return Command::INVALID;
        }

        $this->io->note(($this->dryRun ? '[DRY-RUN] ' : '') . 'Cleaning sys_log with --cutoffPeriod=' . $cutoffPeriod . ' --retentionPeriod=' . $retentionPeriod);

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_log');

        $expressions = [
            $qb->expr()->lt('tstamp', $qb->createNamedParameter($retentionTstamp, ParameterType::INTEGER)),
            $qb->expr()->or(
                $qb->expr()->like('details', $qb->createNamedParameter('%has cleared the cache%')),
                $qb->expr()->like('details', $qb->createNamedParameter('[scheduler%')),
                $qb->expr()->like('details', $qb->createNamedParameter('User %s logged in from%')),
                $qb->expr()->lt('tstamp', $qb->createNamedParameter($cutoffTstamp, ParameterType::INTEGER)),
                $qb->expr()->like('details', $qb->createNamedParameter('%was deleted unrecoverable%')),
                $qb->expr()->gt('error', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
        ];

        if ($this->dryRun) {
            $count = (int)$qb
                ->selectLiteral('COUNT(*)')
                ->from('sys_log')
                ->where(...$expressions)
                ->executeQuery()
                ->fetchOne();
            $this->io->writeln(sprintf('Would delete %d row(s)', $count));
            $this->io->success('Dry-run completed. No rows were deleted.');
            return Command::SUCCESS;
        }

        $affected = (int)$qb
            ->delete('sys_log')
            ->where(...$expressions)
            ->executeStatement();

        $this->io->success('Cleanup completed. Total deleted: ' . $affected);
		return Command::SUCCESS;
	}
}
