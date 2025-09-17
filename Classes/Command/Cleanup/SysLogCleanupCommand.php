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
 * Cleanup sys_log table from noisy or outdated rows
 *
 * Deletes rows matching specific patterns and rows older than a
 * configurable number of days. Supports dry-run to preview deletions.
 */
class SysLogCleanupCommand extends AbstractCommand
{

	public function configure()
	{
		$this->setDescription('Cleanup TYPO3 sys_log table from outdated rows.');
		$this->setHelp('Deletes rows from sys_log matching:
 - details LIKE "%has cleared the cache%"
 - details LIKE "[scheduler%"
 - details LIKE "User %s logged in from%"
 - details LIKE "%was deleted unrecoverable%"
 - error > 0
 - tstamp older than --maxDays (default 360)

Keeps rows younger than --minDays (default 10).
Use --dry-run to see row count without deleting.');

        $this->addCommonOptions();
		$this->addOption(
			'maxDays',
			'D',
			InputOption::VALUE_OPTIONAL,
			'Delete rows older than this many days (by tstamp).',
			'360'
		);
        $this->addOption(
            'minDays',
            'd',
            InputOption::VALUE_OPTIONAL,
            'Keep rows younger than this many days (by tstamp).',
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

        $maxDays = (int)($input->getOption('maxDays') ?? 360);
        $minDays = (int)($input->getOption('minDays') ?? 10);
        $maxDaysTstamp = time() - ($maxDays * 86400);
        $minDaysTstamp = time() - ($minDays * 86400);

        if ($minDays >= $maxDays) {
            $this->io->error('Invalid options: --minDays must be less than --maxDays');
            return Command::INVALID;
        }

        $this->io->note(($this->dryRun ? '[DRY-RUN] ' : '') . 'Cleaning sys_log with --maxDays=' . $maxDays . ' --minDays=' . $minDays);

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_log');

        if ($this->dryRun) {
            $count = (int)$qb
                ->selectLiteral('COUNT(*)')
                ->from('sys_log')
                ->where(
                    $qb->expr()->lt('tstamp', $qb->createNamedParameter($minDaysTstamp, ParameterType::INTEGER)),
                    $qb->expr()->or(
                        $qb->expr()->like('details', $qb->createNamedParameter('%has cleared the cache%')),
                        $qb->expr()->like('details', $qb->createNamedParameter('[scheduler%')),
                        $qb->expr()->like('details', $qb->createNamedParameter('User %s logged in from%')),
                        $qb->expr()->lt('tstamp', $qb->createNamedParameter($maxDaysTstamp, ParameterType::INTEGER)),
                        $qb->expr()->like('details', $qb->createNamedParameter('%was deleted unrecoverable%')),
                        $qb->expr()->gt('error', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                    )
                )
                ->executeQuery()
                ->fetchOne();
            $this->io->writeln(sprintf('Would delete %d row(s)', $count));
        } else {
            $affected = (int)$qb
                ->delete('sys_log')
                ->where(
                    $qb->expr()->lt('tstamp', $qb->createNamedParameter($minDaysTstamp, ParameterType::INTEGER)),
                    $qb->expr()->or(
                        $qb->expr()->like('details', $qb->createNamedParameter('%has cleared the cache%')),
                        $qb->expr()->like('details', $qb->createNamedParameter('[scheduler%')),
                        $qb->expr()->like('details', $qb->createNamedParameter('User %s logged in from%')),
                        $qb->expr()->lt('tstamp', $qb->createNamedParameter($maxDaysTstamp, ParameterType::INTEGER)),
                        $qb->expr()->like('details', $qb->createNamedParameter('%was deleted unrecoverable%')),
                        $qb->expr()->gt('error', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                    )
                )
                ->executeStatement();
        }

		if ($this->dryRun) {
			$this->io->success('Dry-run completed. No rows were deleted.');
		} else {
			$this->io->success('Cleanup completed. Total deleted: ' . $affected);
		}

		return Command::SUCCESS;
	}
}
