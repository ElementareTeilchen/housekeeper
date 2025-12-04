<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Command\Cleanup;

use Elementareteilchen\Housekeeper\Command\AbstractCommand;
use Elementareteilchen\Housekeeper\Service\FileOperationService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Backend\Command\ProgressListener\ReferenceIndexProgressListener;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\InaccessibleFolder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\File\ExtendedFileUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Abstract base class for all file cleanup commands
 *
 * Provides common functionality for commands that clean up files:
 * - Updating reference index
 * - File deletion operations
 * - Handling dry run mode
 * - Processing files with hooks for customization
 * - Cleaning up empty folders
 */
class AbstractCleanupCommand extends AbstractCommand
{
    /**
     * Storage ID
     */
    protected int $storageId;

    /**
     * Path to the storage being processed
     * Only populated for writable storages that support local file operations
     */
    protected string $storagePath = '';

    /**
     * Indicates whether the storage is writable
     * Non-writable storages (read-only) cannot have files written to them,
     * so touchFile, mkdir, and unlink operations must be skipped
     */
    protected bool $isWritableStorage = true;

    /**
     * Constructor
     *
     * @param ResourceFactory $resourceFactory TYPO3 resource factory service
     * @param StorageRepository $storageRepository TYPO3 storage repository service
     * @param ExtendedFileUtility $extendedFileUtility TYPO3 file utility service
     * @param FileOperationService $fileOperationService Service for file operations
     */
    public function __construct(
        ResourceFactory                         $resourceFactory,
        StorageRepository                       $storageRepository,
        protected readonly ExtendedFileUtility  $extendedFileUtility,
        protected readonly FileOperationService $fileOperationService
    )
    {
        parent::__construct($resourceFactory, $storageRepository);
    }

    /**
     * Add options specific to cleanup commands
     */
    protected function addCleanupOptions(): void
    {
        $this->addCommonOptions();
        $this->addOption(
            'update-refindex',
            null,
            InputOption::VALUE_NONE,
            'Setting this option automatically updates the reference index and does not ask on command line. Alternatively, use -n to avoid the interactive mode'
        );
        $this->addOption('storageId', 's', InputOption::VALUE_REQUIRED, 'Storage id to use', '1');
    }

    /**
     * Prepare command execution
     *
     * Initializes backend authentication, sets storage path, updates reference index,
     * and handles dry run mode configuration.
     *
     * @param InputInterface $input Command input interface
     * @param OutputInterface $output Command output interface
     */
    protected function prepareExecution(InputInterface $input, OutputInterface $output): void
    {
        // Initialize common command properties
        $this->initializeCommand($input, $output);
        $this->fileOperationService->setIo($this->io);

        $this->storageId = (int)($input->getOption('storageId') ?? 1);

        try {
            $storage = $this->storageRepository->getStorageObject($this->storageId);
            if (!$storage->isOnline()) {
                $this->io->error('Storage ' . $this->storageId . ' is not online');
                exit(1);
            }

            // Check if storage is writable
            // Non-writable storages cannot have dummy files created for deletion
            $this->isWritableStorage = $storage->isWritable();

            if (!$this->isWritableStorage) {
                $this->io->info('Storage ' . $this->storageId . ' is not writable. File operations will be skipped.');
            } else {
                $this->storagePath = $this->getFullStoragePath();
                $this->io->info('Using storage path: ' . $this->storagePath);
            }
        } catch (\Exception $e) {
            $this->io->error('Storage ' . $this->storageId . ' not found');
            exit(1);
        }

        // Update the reference index
        $this->updateReferenceIndex($input);

        // Set the dry run flag
        $this->dryRun = (bool)$input->getOption('dry-run');
        if ($this->dryRun) {
            $this->io->warning('Running in dry-run mode, no files will be deleted');
        }
    }

    /**
     * Delete a file using the file operation service
     *
     * @param string $file Uid or Identifier of the file to delete
     * @return bool Success of the deletion operation
     */
    protected function deleteFile($file): bool
    {
        return $this->fileOperationService->deleteFile($file, $this->storageId);
    }

    /**
     * Get the full path to the storage
     *
     * @return string Full path to the storage
     */
    protected function getFullStoragePath(): string
    {
        return Environment::getPublicPath() . DIRECTORY_SEPARATOR .
            rtrim($this->getFolderFromStorage('/')->getPublicUrl(), '/');
    }

    /**
     * Get a folder from the storage
     *
     * @param string $path Path within the storage
     * @return Folder|InaccessibleFolder Folder object
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     */
    protected function getFolderFromStorage(string $path): Folder|InaccessibleFolder
    {
        $storage = $this->storageRepository->getStorageObject($this->storageId);
        return $storage->getFolder($path);
    }

    /**
     * Update the reference index if needed
     *
     * - Updates automatically if --update-refindex is set
     * - Asks the user in interactive mode
     * - Skips the update otherwise
     *
     * @param InputInterface $input Command input interface
     */
    protected function updateReferenceIndex(InputInterface $input)
    {
        // Check for reference index to update
        $this->io->note('Finding files referenced by TYPO3 requires a clean reference index (sys_refindex)');
        if ($input->hasOption('update-refindex') && $input->getOption('update-refindex')) {
            $updateReferenceIndex = true;
        } elseif ($input->isInteractive()) {
            $updateReferenceIndex = $this->io->confirm('Should the reference index be updated right now?', false);
        } else {
            $updateReferenceIndex = false;
        }

        // Update the reference index
        if ($updateReferenceIndex) {
            $progressListener = GeneralUtility::makeInstance(ReferenceIndexProgressListener::class);
            $progressListener->initialize($this->io);
            $referenceIndex = GeneralUtility::makeInstance(ReferenceIndex::class);
            $referenceIndex->updateIndex(false, $progressListener);
        } else {
            $this->io->note('Reference index is assumed to be up to date, continuing.');
        }
    }

    /**
     * Log a file that failed to be deleted
     *
     * @param string $logFileFailed Path to the log file
     * @param string $file File that failed to be deleted
     */
    public function logFailed(string $logFileFailed, string $file): void
    {
        $this->io->writeln('<error>Failed to delete file, it might still have references</error>');
        file_put_contents($logFileFailed, $file . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get the file path from an identifier
     *
     * @param mixed $identifier File identifier
     * @return array [$directory, $file] Directory and file paths
     */
    public function getFileFromIdentifier(mixed $identifier): array
    {
        $directory = $this->storagePath . dirname((string)$identifier);
        $file = rtrim($directory, '/') . '/' . basename((string)$identifier);
        return [$directory, $file];
    }

    /**
     * Finalize command execution
     *
     * Cleans up empty folders if not in dry run mode
     */
    public function endExecution(): void
    {
        $this->io->success('Done.');
    }

    /**
     * @param mixed $identifier
     * @return bool
     */
    public function skipFiles(mixed $identifier): bool
    {
        $fileNameValidator = GeneralUtility::makeInstance(FileNameValidator::class);
        $skip = !$fileNameValidator->isValid(basename((string)$identifier));
        // todo: do we need to skip _recycler_ files? $skip = $skip || strpos($identifier, '_recycler_') > -1;
        if ($skip) {
            $this->io->writeln('<warning>Skipping: We are not allowed to delete file: ' . $identifier . '</warning>');
        }
        return $skip;
    }
}
