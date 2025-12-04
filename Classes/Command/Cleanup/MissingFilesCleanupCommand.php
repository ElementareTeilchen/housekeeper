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
    private array $createdDirectories = [];

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

            // For writable storages, the file should exist at this point
            if ($this->isWritableStorage && !file_exists($filePath)) {
                $this->io->writeln('<warning>Can not delete file, as it does not exist</warning>');
                $failedCount++;
                continue;
            }

            // now the deletion should go through, except for files that still have references
            $deletionSuccess = $this->deleteFile((string)$record['uid']);

            // For writable storages, also check if file still exists after deletion attempt
            // For non-writable storages, rely only on the API response
            $deletionFailed = !$deletionSuccess || ($this->isWritableStorage && file_exists($filePath)); // @phpstan-ignore-line - file_exists may not be true as we try to delete it inbetween

            if ($deletionFailed) {
                $failedCount++;
                $this->logFailed($logFileFailed, $filePath);

                // Additional processing can be done in derived classes through afterFailedDelete method
                $this->afterFailedDelete($record, $filePath);
            } else {
                $deletedCount++;
            }
            $this->removeCreatedDirectories();
        }

        $this->io->writeln('<info>Deleted: ' . $deletedCount . ' Failed: ' . $failedCount . '</info>');

        return [$deletedCount, $failedCount];
    }

    /**
     * Hook method called before a file is deleted
     * Prepares missing files by touching them and marking them as not missing
     *
     * For non-writable storages, touchFile is skipped as they don't support
     * local file operations. The files can be deleted directly via the TYPO3 API.
     *
     * @param array $fileData File data containing identifier and uid
     * @param string $file File path (empty for non-writable storages)
     * @param string $directory Directory path (empty for non-writable storages)
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

        // For non-writable storages, skip touchFile as they don't support local file operations
        if (!$this->isWritableStorage) {
            $this->io->writeln('<info>Non-writable storage detected - skipping local file creation for: ' . $record['identifier'] . '</info>', OutputInterface::VERBOSITY_VERBOSE);
            return true; // Non-writable storages can delete files directly via API
        }

        // For writable storages, create a dummy file so it can be deleted via the file system
        $this->touchFile($file, $directory);

        $file_exists = file_exists($file);
        if (!$file_exists) {
            $this->io->writeln('<warning>Failed to create dummy file: ' . $file . '</warning>');
        }
        return $file_exists;
    }

    /**
     * Create a file that doesn't exist
     *
     * Creates necessary directory structure and touches the file so that it can
     * be properly deleted by the file system if needed.
     *
     * @param string $file Path to the file
     * @param string $directory Directory containing the file
     */
    protected function touchFile(string $file, string $directory): void
    {
        if (!file_exists($file)) {
            if (!is_dir($directory)) {
                if ($this->io->isDebug()) {
                    $this->io->writeln('<comment>Creating directory: ' . $directory . '</comment>');
                }
                // Manually create directory structure recursively
                $this->createDirectoryRecursively($directory);
            }
            if ($this->io->isDebug()) {
                $this->io->writeln('<comment>Touching file so it can be deleted via system command</comment>');
            }
            touch($file);
            chmod($file, 0666);
        }
    }

    /**
     * Creates directory structure recursively and collects created directories
     *
     * @param string $directory Full directory path to create
     */
    protected function createDirectoryRecursively(string $directory): void
    {
        $parts = explode('/', $directory);
        $path = '';

        // Skip the first empty element when path starts with '/'
        $startIndex = $parts[0] === '' ? 1 : 0;

        for ($i = $startIndex; $i < count($parts); $i++) {
            $path = $path . ($path && $startIndex === 1 ? '/' : '') . $parts[$i];

            $fileNotExists = !file_exists($path);
            if ($fileNotExists && !mkdir($path, 0775)) {
                throw new \RuntimeException('Directory "' . $path . '" could not be created');
            }

            // if it now exists, add it to the list
            if ($fileNotExists && file_exists($path)) {
                $this->createdDirectories[] = $path;
            }

            if ($startIndex === 1 && $i === $startIndex) {
                // For absolute paths, add the leading slash back for subsequent iterations
                $path = '/' . $path;
            }
        }
    }

    /**
     * Removes all previously created directories
     * @return void
     */
    private function removeCreatedDirectories(): void
    {
        foreach (array_reverse($this->createdDirectories) as $directory) {
            if (rmdir($directory)) {
                if ($this->io->isDebug()) {
                    $this->io->writeln('<comment>Removed created directory: ' . $directory . '</comment>');
                }
            } else {
                $this->io->writeln('<warning>Failed to remove created directory: ' . $directory . '</warning>');
            }
        }
        // clear the list
        $this->createdDirectories = [];
    }

    /**
     * Hook method called after a file deletion has failed
     * Cleans up the temporary file and marks it as missing again
     *
     * For non-writable storages, skip the unlink operation as no local dummy file was created.
     *
     * @param array $fileData File data containing identifier and uid
     * @param string $file File path (empty for non-writable storages)
     */
    protected function afterFailedDelete(array $fileData, string $file): void
    {
        // We could not delete the file, so we mark it as missing again
        $success = $this->setFileMissingStatus($fileData['uid'], true);
        if (!$success) {
            $this->io->writeln('<warning>Failed to mark file with identifier ' . $fileData['identifier'] . ' (uid=' . $fileData['uid'] . ')  as missing</warning>');
        }

        // For writable storages, delete the touched dummy file
        if ($this->isWritableStorage) {
            unlink($file);

            if (file_exists($file)) {
                $this->io->writeln('<warning>Failed to delete dummy file: ' . $file . '</warning>');
            }
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
