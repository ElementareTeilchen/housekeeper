<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Service;

use Elementareteilchen\Housekeeper\Helper\CommandHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Resource\Event\AfterFolderRenamedEvent;
use TYPO3\CMS\Core\Resource\Exception;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\File\ExtendedFileUtility;

/**
 * Service for file operations
 *
 * This service centralizes file operations like delete, move, and rename
 * to reduce code duplication across command classes.
 */
class FileOperationService
{
    private ?SymfonyStyle $io = null;

    /**
     * Constructor
     *
     * @param ExtendedFileUtility $extendedFileUtility TYPO3 file utility service
     * @param EventDispatcher $eventDispatcher TYPO3 event dispatcher
     * @param ResourceFactory $resourceFactory TYPO3 resource factory
     */
    public function __construct(
        private readonly ExtendedFileUtility $extendedFileUtility,
        private readonly EventDispatcher $eventDispatcher,
        private readonly ResourceFactory $resourceFactory
    ) {}

    /**
     * Delete a file via the TYPO3 API
     *
     * @param string $identifier Uid or Path or identifier of the file to delete
     * @param int $storageId ID of the storage containing the file
     * @return bool Success status of the deletion
     */
    public function deleteFile(string $identifier, int $storageId): bool
    {
        if (substr($identifier, 0, 1) === '/') {
            $identifier = $storageId . ':' . substr($identifier, 1);
        }

        if ($this->io->isDebug()) {
            $this->io->writeln('Executing delete command with data uid: ' . $identifier);
        }

        $result = $this->executeCommand([
            'delete' => [
                [
                    'data' => $identifier,
                ],
            ],
        ]);

        if (empty($result['delete'][0])) {
            $this->io->writeln('<error>File deletion failed!</error>');
            return false;
        }
        return true;

    }

    /**
     * Move a file or folder to a new location
     *
     * @param string $source Source path or identifier
     * @param string $target Target path or identifier
     * @param string|null $alternativeFileName Optional alternative file name for the target
     * @return bool Success status of the move operation
     */
    public function moveFile(string $source, string $target, ?string $alternativeFileName = null): bool
    {
        if ($this->io->isDebug()) {
            $this->io->writeln('Executing move command with source: ' . $source . ' and target: ' . $target . ' and alternative name: ' . $alternativeFileName);
        }

        $result = $this->executeCommand([
            'move' => [
                [
                    'data' => $source,
                    'target' => $target,
                    'altName' => $alternativeFileName,
                ],
            ],
        ]);

        if (empty($result['move'][0])) {
            $this->io->writeln('<error>File move failed!</error>');
            return false;
        }

        // TYPO3 does not update file mounts on move operations only for rename
        // So we use the AfterFolderRenamedEvent to update filemount paths
        try {
            $sourceFolder = CommandHelper::getFolderFromPath($source);
            $afterRenameFolderEvent = new AfterFolderRenamedEvent(
                CommandHelper::getFileObject($this->resourceFactory, $target),
                CommandHelper::getFileObject($this->resourceFactory, $sourceFolder)
            );
            $this->eventDispatcher->dispatch($afterRenameFolderEvent);
        } catch (\Throwable) {
            // SynchronizeFolderRelations tries to enqueue FlashMessages to the CommandLineAuthentication,
            // which doesn't have a user session. Ignore this error.
        }

        return true;
    }

    /**
     * Rename a file or folder
     *
     * @param string $source Source path or identifier
     * @param string $newName New name for the file or folder
     * @return bool Success status of the rename operation
     */
    public function renameFile(string $source, string $newName): bool
    {
        if ($this->io->isDebug()) {
            $this->io->writeln('Executing rename command with source: ' . $source . ' and target: ' . $newName);
        }

        $result = $this->executeCommand([
            'rename' => [
                [
                    'data' => $source,
                    'target' => $newName,
                ],
            ],
        ]);

        if (empty($result['rename'][0])) {
            $this->io->writeln('<error>File rename failed!</error>');
            return false;
        }

        return true;
    }

    /**
     * Execute a file command
     *
     * @param array $fileCommand Command array for the ExtendedFileUtility
     * @return array Result of the command execution
     */
    private function executeCommand(array $fileCommand): array
    {
        try {
            if (class_exists(\TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior::class)) {
                $this->extendedFileUtility->setExistingFilesConflictMode(\TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior::CANCEL);
            }
            $this->extendedFileUtility->start($fileCommand);
            return $this->extendedFileUtility->processData();
        } catch (Exception $e) {
            $this->io->writeln('<error>' . $e->getMessage() . '</error>');
            return [];
        }
    }

    public function setIo(?SymfonyStyle $io): void
    {
        $this->io = $io;
    }
}
