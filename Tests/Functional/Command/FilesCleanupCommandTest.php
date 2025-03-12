<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class FilesCleanupCommandTest extends AbstractTestCase
{
    public const BASE_COMMAND = 'housekeeper:cleanup-files .png';
    public const FILE_NAMES = [
        '1_test_file.png',
        '2_test_file.png',
        '3_test_file.png',
        '4_normal_file.txt',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);

        foreach (self::STORAGE_IDS as $storageUid) {
            // create empty file in storage
            $storage = $storageRepository->findByUid($storageUid);
            $targetDir = $this->instancePath . '/' . $storage->getName();
            foreach (self::FILE_NAMES as $fileName) {
                $targetFile = $targetDir . '/' . $fileName;
                if (!file_exists($targetFile)) {
                    touch($targetFile);
                }
            }
            $this->reindexStorage($storage);
        }
        // clear cache
        // $this->executeConsoleCommand('cache:flush');
    }

    protected function reindexStorage($storage): void
    {
        $fileIndexRepository = GeneralUtility::makeInstance(FileIndexRepository::class);

        $files = $storage->getRootLevelFolder()->getFiles();
        foreach ($files as $file) {
            $fileIndexRepository->add($file);
        }
    }

    #[Test]
    public function commandFindsAndDeletesMatchingFiles(): void
    {
        // $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_cleanup.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' --update-refindex');

        // Verify files matching the identifier were deleted
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_cleanup_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandDoesNothingInDryRunMode(): void
    {
        // $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_cleanup.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -n --dry-run');

        // Verify no changes were made to the database
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_cleanup.csv');
        self::assertEquals(0, $result['status']);
    }

    //#[Test] fixme not working yet
    public function commandUpdatesReferenceIndexWhenOptionIsProvided(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_cleanup_with_references.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' --update-refindex');

        // Verify files were deleted and references were updated
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_cleanup_with_references_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandCanWorkWithSpecificStorageId(): void
    {
        //        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_cleanup_multiple_storages.csv');

        // Test with storage ID 2
        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -n -s 2');

        // Verify only files in storage 2 were affected
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_cleanup_multiple_storages_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }
}
