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
		$this->setHelp('Deletes entries from sys_log matching:
 - details LIKE "%has cleared the cache%"
 - details LIKE "[scheduler%"
 - details LIKE "User %s logged in from%"
 - details LIKE "%was deleted unrecoverable%"
 - error > 0
 - tstamp older than --days (default 360)

Use --dry-run to see row count without deleting.');


        $this->addCommonOptions();
		$this->addOption(
			'days',
			'd',
			InputOption::VALUE_OPTIONAL,
			'Delete rows older than this many days (by tstamp).',
			'360'
		);
	}

	public function execute(InputInterface $input, OutputInterface $output): int
	{
        try {
            $this->initializeCommand($input, $output);
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }

        $days = (int)($input->getOption('days') ?? 360);
        $oldestEntryTstamp = time() - ($days * 86400);

        $this->io->note(($this->dryRun ? '[DRY-RUN] ' : '') . 'Cleaning sys_log with --days=' . $days);

        $totalAffected = 0;

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_log');

        if ($this->dryRun) {
            $count = (int)$qb
                ->selectLiteral('COUNT(*)')
                ->from('sys_log')
                ->where(
                    $qb->expr()->or(
                        $qb->expr()->like('details', $qb->createNamedParameter('%has cleared the cache%')),
                        $qb->expr()->like('details', $qb->createNamedParameter('[scheduler%')),
                        $qb->expr()->like('details', $qb->createNamedParameter('User %s logged in from%')),
                        $qb->expr()->lt('tstamp', $qb->createNamedParameter($oldestEntryTstamp, ParameterType::INTEGER)),
                        $qb->expr()->like('details', $qb->createNamedParameter('%was deleted unrecoverable%')),
                        $qb->expr()->gt('error', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                    )
                )
                ->executeQuery()
                ->fetchOne();
            $this->io->writeln(sprintf('Would delete %d rows', $count));
        } else {
            $affected = (int)$qb
                ->delete('sys_log')
                ->where(
                    $qb->expr()->or(
                        $qb->expr()->like('details', $qb->createNamedParameter('%has cleared the cache%')),
                        $qb->expr()->like('details', $qb->createNamedParameter('[scheduler%')),
                        $qb->expr()->like('details', $qb->createNamedParameter('User %s logged in from%')),
                        $qb->expr()->lt('tstamp', $qb->createNamedParameter($oldestEntryTstamp, ParameterType::INTEGER)),
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
