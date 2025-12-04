<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Command\Cleanup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Search\FileSearchDemand;
use TYPO3\CMS\Core\Resource\Search\Result\FileSearchResultInterface;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Command for cleaning up files by a specified identifier pattern
 *
 * This command searches for files matching a given identifier (e.g., file extension)
 * and attempts to delete them using the TYPO3 API.
 *
 * Files that cannot be deleted (e.g., due to references) are logged for later inspection.
 */
class FilesCleanupCommand extends AbstractCleanupCommand
{

    /**
     * When deleting files, remove new empty parent folders
     */
    protected string|bool $removeEmptyParentFolder;

    /**
     * Configure the command
     */
    public function configure()
    {
        $this->setHelp('
Cleanup files via a given identifier.
The identifier is passed as argument: E.g. .jpg.webp.

All matching files are then deleted via the systems API delete command.
This should clean up all other metadata and other related records also.

In case files still have references or for some other reason can not be deleted,
their path is written to a log file in var/log for further inspection.
');
        $this->addCleanupOptions();
        $this->addArgument('identifier', InputArgument::REQUIRED, 'Identifier of the files to delete. E.g. ".jpg.webp"');
        $this->addOption('removeEmptyParentFolder', 'e', InputOption::VALUE_OPTIONAL, 'When deleting files, remove new empty parent folders. Pass recursive as value to remove all empty parent folders', false);
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

        $identifier = $input->getArgument('identifier');

        $this->removeEmptyParentFolder =  match ($input->getOption('removeEmptyParentFolder')) {
            null => true, // null is passed if the option is set but has no value
            'recursive' => 'recursive',
            default => false,
        };


        $fileSearchResults = $this->search($identifier);

        if ($fileSearchResults->count() > 0) {
            $this->io->success('Starting to delete ' . $fileSearchResults->count() . ' files matching ' . $identifier);

            $this->processFiles($fileSearchResults);

            $this->io->success('Finished to delete files matching ' . $identifier);
        } else {
            $this->io->success('No files found matching ' . $identifier);
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
     * @param FileSearchResultInterface $files The collection of file identifiers to process
     * @return array [deletedCount, failedCount]
     */
    protected function processFiles(FileSearchResultInterface $fileSearchResults): array
    {
        $logFileFailed = Environment::getVarPath() . '/log/' . $this->getName() . '_failed_' . date('Y-m-d_H:i:s') . '.log';

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($fileSearchResults as $fileResult) {
            // Get the plain file if this is an object
            $identifier = $fileResult->getIdentifier();

            // Skip .htaccess files
            if ($this->skipFiles($identifier)) {
                continue;
            }

            [$directory, $filePath] = $this->getFileFromIdentifier($identifier);

            $this->io->writeln('<info>Processing file: ' . $filePath . '</info>');

            // For writable storages, the file should exist at this point
            if ($this->isWritableStorage && !file_exists($filePath)) {
                $this->io->writeln('<warning>Can not delete file, as it does not exist</warning>');
                $failedCount++;
                continue;
            }

            // now the deletion should go through, except for files that still have references
            $deletionSuccess = true;
            if (!$this->dryRun) {
                $deletionSuccess = $this->deleteFile((string)$fileResult->getUid());
            }

            // For writable storages, also check if file still exists after deletion attempt
            // For non-writable storages, rely only on the API response
            $deletionFailed = !$deletionSuccess || ($this->isWritableStorage && file_exists($filePath)); // @phpstan-ignore-line - file_exists may not be true as we try to delete it inbetween

            if ($deletionFailed) {
                $failedCount++;
                $this->logFailed($logFileFailed, $filePath);
            } else {
                $deletedCount++;

                if ($this->removeEmptyParentFolder && $this->isWritableStorage) {
                    $this->removeEmptyFolder($directory, $this->removeEmptyParentFolder === 'recursive');
                }
            }
        }

        $this->io->writeln('<info>Deleted: ' . $deletedCount . ' Failed: ' . $failedCount . '</info>');

        return [$deletedCount, $failedCount];
    }

    /**
     * Recursively remove empty parent folders
     *
     * Only works for writable storages where local file operations are supported
     *
     * @param string $path Current path to check
     * @param bool $recursive Recursively remove empty parent folders
     * @return void
     */
    protected function removeEmptyFolder($dir, $recursive = false): void
    {
        // Only attempt to remove folders for writable storages
        if (!$this->isWritableStorage) {
            return;
        }

        while (is_dir($dir) && count(scandir($dir)) === 2) {
            if (!$this->dryRun) {
                if (rmdir($dir)) {
                    if ($this->io->isDebug()) {
                        $this->io->writeln('<comment>Removed empty folder: ' . $dir . '</comment>');
                    }
                } else {
                    $this->io->writeln('<warning>Failed to remove empty folder: ' . $dir . '</warning>');
                }
            }
            if (!$recursive) break;
            $dir = dirname($dir);
        }
    }

    /**
     * Search for files matching the given search term
     *
     * @param string $searchWord Pattern to search for
     * @return FileSearchResultInterface Collection of matching files
     */
    protected function search(string $searchWord): FileSearchResultInterface
    {
        $folder = $this->getFolderFromStorage('/');

        $searchDemand = FileSearchDemand::createForSearchTerm($searchWord)->withRecursive();
        $files = $folder->searchFiles($searchDemand);

        return $files;
    }
}
