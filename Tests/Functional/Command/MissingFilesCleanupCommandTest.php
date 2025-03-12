<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class MissingFilesCleanupCommandTest extends AbstractTestCase
{
    public const BASE_COMMAND = 'housekeeper:cleanup-missing -n';

    protected function setup(): void
    {
        parent::setUp();
    }

    /**
     * Ensures that the file is indexed properly in TYPO3.
     */
    protected function reindexFile(string $fileIdentifier): void
    {
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storage = $storageRepository->findByUid(1); // fileadmin storage

        $fileIndexRepository = GeneralUtility::makeInstance(FileIndexRepository::class);

        try {
            $file = $storage->getFile($fileIdentifier);
            $fileIndexRepository->add($file);
        } catch (\TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException $e) {
            echo "File does not exist in storage: " . $fileIdentifier;
        }
    }

    #[Test]
    public function commandFindsAndDeletesMissingFiles(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_missing.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);

        // Verify missing files were deleted
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_missing_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandDoesNothingInDryRunMode(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_missing.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' --dry-run');

        // Verify no changes were made to the database
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_missing.csv');
        self::assertEquals(0, $result['status']);
    }

    //#[Test] fixme not working yet
    public function commandUpdatesReferenceIndexWhenOptionIsProvided(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_missing_with_references.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' --update-refindex');

        // Verify missing files were deleted and references were updated
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_missing_with_references_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandCanWorkWithSpecificStorageId(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_missing_multiple_storages.csv');

        // Test with storage ID 2
        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -s 2');

        // Verify only files in storage 2 were affected
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_missing_multiple_storages_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }
}
