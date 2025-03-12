<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Command\Cleanup;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use Elementareteilchen\Housekeeper\Service\FileOperationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\File\ExtendedFileUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Command for cleaning up files marked as missing in the TYPO3 database
 *
 * This command finds all files marked as missing in the database,
 * creates empty placeholder files for them, marks them as not missing,
 * and then attempts to delete them using the TYPO3 API.
 *
 * If deletion fails (e.g., due to references), the command marks the file
 * as missing again and logs the failure.
 */
class MissingFilesCleanupCommand extends AbstractCleanupCommand
{
    /**
     * Constructor
     *
     * @param ResourceFactory $resourceFactory TYPO3 resource factory service
     * @param StorageRepository $storageRepository TYPO3 storage repository service
     * @param ExtendedFileUtility $extendedFileUtility TYPO3 file utility service
     * @param FileOperationService $fileOperationService Service for file operations
     */
    public function __construct(
        ResourceFactory      $resourceFactory,
        StorageRepository    $storageRepository,
        ExtendedFileUtility  $extendedFileUtility,
        FileOperationService $fileOperationService
    )
    {
        parent::__construct($resourceFactory, $storageRepository, $extendedFileUtility, $fileOperationService);
    }

    /**
     * Configure the command
     */
    public function configure()
    {
        $this->setHelp('
Cleanup files missing files.

As files can not be deleted if they do not exist, files that are marked as missing
are touched and marked as not missing before issuing the delete command.

In case files still have references or for some other reason can not be deleted,
their path is written to a log file in var/log for further inspection.
');
        $this->addCleanupOptions();
    }

    /**
     * Execute the command
     *
     * @param InputInterface $input Command input
     * @param OutputInterface $output Command output
     * @return int Command result status
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->prepareExecution($input, $output);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }

        $result = $this->findMissingFiles($this->storageId);

        if ($result->rowCount() > 0) {
            $this->io->note('Starting to delete ' . $result->rowCount() . ' missing files.');

            $this->processFiles($result);

            $this->io->success('Finished deleting missing files.');
        } else {
            $this->io->success('No missing files found.');
        }

        $this->endExecution();

        return Command::SUCCESS;
    }

    /**
     * Base implementation for processing a set of files from search results or other sources
     *
     * Implements the Template Method pattern to allow customization in subclasses through
     * the hook methods beforeFileDelete() and afterFailedDelete().
     *
     * @param Result $result The result set containing the files to process
     * @return array An array containing the number of deleted and failed files
     */
    protected function processFiles(Result $result): array
    {
        $logFileFailed = Environment::getVarPath() . '/log/' . $this->getName() . '_failed_' . date('Y-m-d_H:i:s') . '.log';

        $deletedCount = 0;
        $failedCount = 0;

        while ($record = $result->fetchAssociative()) {
            // Get the identifier from the file data
            $identifier = $record['identifier'];

            // Skip .htaccess files
            if ($this->skipFiles($identifier)) {
                continue;
            }

            [$directory, $filePath] = $this->getFileFromIdentifier($identifier);

            $this->io->writeln('<info>Processing file: ' . $filePath . '</info>');

            if ($this->dryRun) {
                continue;
            }

            if (!$this->beforeFileDelete($record, $filePath, $directory)) {
                $failedCount++;
                continue;
            }

            // the file should exist at this point
            if (!file_exists($filePath)) {
                $this->io->writeln('<warning>Can not delete file, as it does not exist</warning>');
                $failedCount++;
                continue;
            }

            // now the deletion should go through, except for files that still have references
            $this->deleteFile($identifier);

            if (file_exists($filePath)) { // @phpstan-ignore-line - file_exists may not be true as we try to delete it inbetween
                $failedCount++;
                $this->logFailed($logFileFailed, $filePath);

                // Additional processing can be done in derived classes through afterFailedDelete method
                $this->afterFailedDelete($record, $filePath);
            } else {
                $deletedCount++;
            }
        }

        $this->io->writeln('<info>Deleted: ' . $deletedCount . ' Failed: ' . $failedCount . '</info>');

        return [$deletedCount, $failedCount];
    }

    /**
     * Hook method called before a file is deleted
     * Prepares missing files by touching them and marking them as not missing
     *
     * @param array $fileData File data containing identifier and uid
     * @param string $file File path
     * @param string $directory Directory path
     * @return bool Whether the file was successfully prepared
     */
    protected function beforeFileDelete(array $record, string $file, string $directory): bool
    {
        // IMPORTANT: To be able to delete the file, we first need to mark it as NOT missing
        $success = $this->setFileMissingStatus($record['uid'], false);
        if (!$success) {
            $this->io->writeln('<warning>Failed to mark file with identifier ' . $record['identifier'] . ' (uid=' . $record['uid'] . ') as not missing, aborting deletion.</warning>');
            return false;
        }

        // then create a dummy
        $this->touchFile($file, $directory);

        $file_exists = file_exists($file);
        if (!$file_exists) {
            $this->io->writeln('<warning>Failed to create dummy file: ' . $file . '</warning>');
        }
        return $file_exists;
    }

    /**
     * Hook method called after a file deletion has failed
     * Cleans up the temporary file and marks it as missing again
     *
     * @param array $fileData File data containing identifier and uid
     * @param string $file File path
     */
    protected function afterFailedDelete(array $fileData, string $file): void
    {
        // We could not delete the file, so we mark it as missing again
        $success = $this->setFileMissingStatus($fileData['uid'], true);
        if (!$success) {
            $this->io->writeln('<warning>Failed to mark file with identifier ' . $record['identifier'] . ' (uid=' . $record['uid'] . ')  as missing</warning>');
        }
        // and delete the touched file
        unlink($file);

        if (file_exists($file)) {
            $this->io->writeln('<warning>Failed to delete dummy file: ' . $file . '</warning>');
        }
    }

    /**
     * Set the missing status of a file
     *
     * @param int $uid The file uid
     * @param bool $missing Whether the file should be marked as missing
     * @return bool Success status of the operation
     */
    private function setFileMissingStatus(int $uid, bool $missing): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file');

        $affectedRows = $queryBuilder
            ->update('sys_file')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->set('missing', (int)$missing)
            ->executeStatement();

        if ($affectedRows > 1) {
            $this->io->writeln('<warning>Multiple files with the same uid (' . $uid . ') where set as missing = ' . (int)$missing . '</warning>');
        }

        return $affectedRows > 0;
    }

    /**
     * Find file references that point to non-existing files in the system
     *
     * @param int $storageId The storage ID
     * @return array An array of missing file identifiers
     */
    private function findMissingFiles(int $storageId): Result
    {
        $missingFiles = [];
        // Select all files in the reference table
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file');

        $result = $queryBuilder
            ->select('identifier', 'uid')
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq('missing', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('storage', $queryBuilder->createNamedParameter($storageId, ParameterType::INTEGER)),
            )
            ->orderBy('identifier')
            ->executeQuery();

        return $result;
    }
}
