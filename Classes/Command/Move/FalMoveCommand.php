<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Command\Move;

use Elementareteilchen\Housekeeper\Command\AbstractCommand;
use Elementareteilchen\Housekeeper\Helper\CommandHelper;
use Elementareteilchen\Housekeeper\Service\FileOperationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Command for moving or renaming files in the TYPO3 FAL system
 *
 * Handles both renaming operations (when source and target folder are the same)
 * and moving operations (when source and target folders differ). Generates
 * appropriate nginx rewrite rules to ensure URLs continue to work after moves.
 */
class FalMoveCommand extends AbstractCommand
{
    /**
     * Constructor
     *
     * @param ResourceFactory $resourceFactory TYPO3 resource factory
     * @param StorageRepository $storageRepository TYPO3 storage repository
     * @param FileOperationService $fileOperationService Service for file operations
     * @param EventDispatcher $eventDispatcher TYPO3 event dispatcher
     */
    public function __construct(
        ResourceFactory $resourceFactory,
        StorageRepository $storageRepository,
        private readonly FileOperationService $fileOperationService,
        private readonly EventDispatcher $eventDispatcher
    ) {
        parent::__construct($resourceFactory, $storageRepository);
    }

    /**
     * Configure the command
     */
    public function configure()
    {
        $this->setHelp('
Move or rename a file. Specify the source and the target with optional storageId, default is 1. Works similar to bash mv command. Move between different storages only works with files, not folders.
Examples:
typo3 housekeeper:move <source> <target>
typo3 housekeeper:move old/path other-path/
typo3 housekeeper:move old.pdf new.pdf
typo3 housekeeper:move 1:old/path 1:new-path
typo3 housekeeper:move 1:old.pdf 2:other-path/new.pdf
');
        $this->addCommonOptions();
        $this->addArgument('source', InputArgument::REQUIRED, 'Combined identifier of the source folder/file');
        $this->addArgument('target', InputArgument::REQUIRED, 'Combined identifier of the target folder/file');
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
            // Initialize common command properties
            $this->initializeCommand($input, $output);
            $this->fileOperationService->setIo($this->io);

            $source = $this->resolvePath($input->getArgument('source'));
            $target = $this->resolvePath($input->getArgument('target'));

            // make sure the source exists
            $this->getFileObject($source);

            [$sourceFolder, $sourceName] = CommandHelper::getFolderAndFile($source);
            [$targetFolder, $targetName] = CommandHelper::getFolderAndFile($target);

            if (empty($targetName)) {
                $targetName = $sourceName;
                $target = $targetFolder . '/' . $targetName;
            }

            try {
                $this->getFileObject($target);
                $this->io->error('Target ' . $target . ' already exists!');
                return Command::FAILURE;
            } catch (\Throwable) {
                // target does not exist, we can proceed
            }

            // if the two folders are the same, we do a rename operation
            if ($sourceFolder === $targetFolder) {
                if ($targetName === $sourceName) {
                    $this->io->error('Source and target are the same!');
                    return Command::FAILURE;
                }
                // depending if we rename a file or a folder:
                $this->io->info('Renaming ' . $source . ' to ' . $targetName);
                return $this->rename($source, $targetName);
            }

            $this->io->info('Moving ' . $source . ' to ' . $target);
            return $this->move($source, $targetFolder, $targetName);
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Rename a file or folder
     *
     * @param string $source Source path or identifier
     * @param string $target New name for the file or folder
     * @return int Command result status
     */
    public function rename(string $source, string $target): int
    {
        $success = $this->fileOperationService->renameFile($source, $target);

        if (!$success) {
            return Command::FAILURE;
        }

        $this->io->success('DONE!');
        $this->io->writeln('Hint: It may be useful to setup rewrite rules for the renamed files.');

        return Command::SUCCESS;
    }

    /**
     * Move a file or folder to a new location
     *
     * @param mixed $source Source path or identifier
     * @param mixed $target Target path or identifier
     * @param false|string $alternativeFileName Optional alternative filename
     * @return int Command result status
     */
    public function move(mixed $source, mixed $target, null|string $alternativeFileName): int
    {
        $success = $this->fileOperationService->moveFile($source, $target, $alternativeFileName);

        if (!$success) {
            return Command::FAILURE;
        }

        $this->io->success('DONE!');
        $this->io->writeln('Hint: It may be useful to setup rewrite rules for the moved files.');

        return Command::SUCCESS;
    }

    /**
     * Standardize path resolution with storage ID
     *
     * Ensures a path has the proper storage ID prefix
     * and that it starts with a slash:
     * <storageId>:/<path>
     *
     * @param string $path Path to resolve
     * @return string Path with storage ID prefix
     */
    public function resolvePath(string $path): string
    {
        if (!preg_match('/^\d+:/', $path)) {
            return '1:/' . ltrim($path, '/');
        }
        [$storageId, $path] = explode(':', $path, 2);
        return $storageId . ':/' . ltrim($path, '/');
    }
}
