<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class MissingFilesCleanupCommandTest extends AbstractTestCase
{
    public const BASE_COMMAND = 'housekeeper:cleanup-missing';

    #[Test]
    public function commandCanWorkWithDefaultStorage(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'testimg.png',
                    'testfile.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        // First created storage after DB purge should have UID 1.
        // The rest of this test does not make sense if this assertion fails.
        self::assertEquals($storageId, 1);

        unlink("{$this->instancePath}/{$storageBasePath}/testimg.png");
        $this->reindexStorage($storage);

        self::assertFalse($this->falFileAccessFails($storage, 'testimg.png'));

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n");
        self::assertEquals(0, $result['status']);

        self::assertTrue($this->falFileAccessFails($storage, 'testimg.png'));
    }

    #[Test]
    public function commandCanWorkWithSpecificStorageId(): void
    {
        $firstStorageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}-1";
        $firstStorage = $this->createStorage(
            $firstStorageBasePath,
            [
                '' => [
                    'testimg.png',
                    'testfile.txt',
                ],
            ]
        );
        $firstStorageId = $firstStorage->getUid();

        $secondStorageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}-2";
        $secondStorage = $this->createStorage(
            $secondStorageBasePath,
            [
                '' => [
                    'testimg.png',
                    'testfile.txt',
                ],
            ]
        );
        $secondStorageId = $secondStorage->getUid();

        unlink("{$this->instancePath}/{$firstStorageBasePath}/testimg.png");
        unlink("{$this->instancePath}/{$secondStorageBasePath}/testfile.txt");
        $this->reindexStorage($firstStorage);
        $this->reindexStorage($secondStorage);

        self::assertFalse($this->falFileAccessFails($firstStorage, 'testimg.png'));
        self::assertFalse($this->falFileAccessFails($firstStorage, 'testfile.txt'));
        self::assertFalse($this->falFileAccessFails($secondStorage, 'testimg.png'));
        self::assertFalse($this->falFileAccessFails($secondStorage, 'testfile.txt'));

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$firstStorageId}");
        self::assertEquals(0, $result['status']);

        self::assertTrue($this->falFileAccessFails($firstStorage, 'testimg.png'));
        self::assertFalse($this->falFileAccessFails($firstStorage, 'testfile.txt'));
        self::assertFalse($this->falFileAccessFails($secondStorage, 'testimg.png'));
        self::assertFalse($this->falFileAccessFails($secondStorage, 'testfile.txt'));

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$secondStorageId}");
        self::assertEquals(0, $result['status']);

        self::assertTrue($this->falFileAccessFails($firstStorage, 'testimg.png'));
        self::assertFalse($this->falFileAccessFails($firstStorage, 'testfile.txt'));
        self::assertFalse($this->falFileAccessFails($secondStorage, 'testimg.png'));
        self::assertTrue($this->falFileAccessFails($secondStorage, 'testfile.txt'));
    }

    #[Test]
    public function commandFindsAndDeletesMissingFiles(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'missing.txt',
                    'somefile.txt',
                ],
                'missing' => [],
                'missing/folder' => [],
                'missing/folder/structure' => [
                    'missing.txt'
                ],
                'existing' => [],
                'existing/folder' => [],
                'existing/folder/structure' => [
                    'missing.txt',
                    'anotherfile.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        unlink("{$this->instancePath}/{$storageBasePath}/missing.txt");
        GeneralUtility::rmdir("{$this->instancePath}/{$storageBasePath}/missing", true);
        unlink("{$this->instancePath}/{$storageBasePath}/existing/folder/structure/missing.txt");
        $this->reindexStorage($storage);

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/somefile.txt");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/existing/folder/structure/anotherfile.txt");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/missing.txt");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/missing/folder/structure/missing.txt");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/existing/folder/structure/missing.txt");

        self::assertFalse($this->falFileAccessFails($storage, 'somefile.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'existing/folder/structure/anotherfile.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'missing.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'missing/folder/structure/missing.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'existing/folder/structure/missing.txt'));

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId}");
        self::assertEquals(0, $result['status']);

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/somefile.txt");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/existing/folder/structure/anotherfile.txt");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/missing.txt");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/missing/folder/structure/missing.txt");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/existing/folder/structure/missing.txt");

        self::assertFalse($this->falFileAccessFails($storage, 'somefile.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'existing/folder/structure/anotherfile.txt'));
        self::assertTrue($this->falFileAccessFails($storage, 'missing.txt'));
        self::assertTrue($this->falFileAccessFails($storage, 'missing/folder/structure/missing.txt'));
        self::assertTrue($this->falFileAccessFails($storage, 'existing/folder/structure/missing.txt'));
    }

    #[Test]
    public function commandDoesNotRecreateMissingDirectories(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'missing' => [],
                'missing/folder' => [],
                'missing/folder/structure' => [
                    'missing.txt'
                ],
            ]
        );
        $storageId = $storage->getUid();

        GeneralUtility::rmdir("{$this->instancePath}/{$storageBasePath}/missing", true);

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/missing/");

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId}");
        self::assertEquals(0, $result['status']);

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/missing/");
    }

    #[Test]
    public function commandDoesNothingInDryRunMode(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'missing.txt',
                    'somefile.txt',
                ],
                'missing' => [],
                'missing/folder' => [],
                'missing/folder/structure' => [
                    'missing.txt'
                ],
                'existing' => [],
                'existing/folder' => [],
                'existing/folder/structure' => [
                    'missing.txt',
                    'anotherfile.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        unlink("{$this->instancePath}/{$storageBasePath}/missing.txt");
        GeneralUtility::rmdir("{$this->instancePath}/{$storageBasePath}/missing", true);
        unlink("{$this->instancePath}/{$storageBasePath}/existing/folder/structure/missing.txt");
        $this->reindexStorage($storage);

        self::assertFalse($this->falFileAccessFails($storage, 'missing.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'somefile.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'missing/folder/structure/missing.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'existing/folder/structure/missing.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'existing/folder/structure/anotherfile.txt'));

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId} --dry-run");
        self::assertEquals(0, $result['status']);

        self::assertFalse($this->falFileAccessFails($storage, 'somefile.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'existing/folder/structure/anotherfile.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'missing.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'missing/folder/structure/missing.txt'));
        self::assertFalse($this->falFileAccessFails($storage, 'existing/folder/structure/missing.txt'));
    }

    #[Test]
    public function commandDoesNotRemoveFileWronglyMarkedAsMissing(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'missing.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();
        $file = $storage->getFile('missing.txt');

        $this->getConnectionPool()
            ->getConnectionForTable('sys_file')
            ->update(
                'sys_file',
                ['missing' => 1],
                ['uid' => $file->getUid()],
                [
                    Connection::PARAM_INT,
                    Connection::PARAM_INT,
                ]
            );

        self::assertFalse($this->falFileAccessFails($storage, 'missing.txt'));

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId}");
        self::assertEquals(0, $result['status']);

        self::assertFalse($this->falFileAccessFails($storage, 'missing.txt'));
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/missing.txt");
    }

    #[Test]
    public function commandUpdatesReferenceIndex(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'file-1.txt',
                    'file-2.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $file1 = $storage->getFileByIdentifier('/file-1.txt');
        $file2 = $storage->getFileByIdentifier('/file-2.txt');

        // Set up reference not yet indexed
        $this->importCSVDataSet(__DIR__ . '/DataSet/Generic/mock-page-with-content.csv');
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update(
                'tt_content',
                ['header_link' => "t3://file?uid={$file1->getUid()}"],
                ['uid' => 1],
                [
                    Connection::PARAM_STR,
                    Connection::PARAM_INT,
                ]
            );

        // Does not actually delete anything this time, only updates refindex
        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " --update-refindex -s {$storageId}");
        self::assertEquals(0, $result['status']);

        // Reference should exist after first commmand run
        self::assertNotEmpty(
            $this->getReferenceIndexEntry(
                'sys_file',
                $file1->getUid(),
                'tt_content',
                'header_link',
                1
            )
        );

        // Intentionally mess up refindex again by swapping the reference
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update(
                'tt_content',
                ['header_link' => "t3://file?uid={$file2->getUid()}"],
                ['uid' => 1],
                [
                    Connection::PARAM_STR,
                    Connection::PARAM_INT,
                ]
            );

        // Run command again
        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " --update-refindex -s {$storageId}");
        self::assertEquals(0, $result['status']);

        // References should be fixed again
        self::assertFalse(
            $this->getReferenceIndexEntry(
                'sys_file',
                $file1->getUid(),
                'tt_content',
                'header_link',
                1
            )
        );
        self::assertNotEmpty(
            $this->getReferenceIndexEntry(
                'sys_file',
                $file2->getUid(),
                'tt_content',
                'header_link',
                1
            )
        );
    }

    #[Test]
    public function commandDoesNotRemoveFalDataOfMissingFileWithReferences(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'missing.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();
        $file = $storage->getFile('missing.txt');

        $this->importCSVDataSet(__DIR__ . '/DataSet/Generic/mock-page-with-content.csv');
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update(
                'tt_content',
                ['header_link' => "t3://file?uid={$file->getUid()}"],
                ['uid' => 1],
                [
                    Connection::PARAM_STR,
                    Connection::PARAM_INT,
                ]
            );

        unlink("{$this->instancePath}/{$storageBasePath}/missing.txt");
        $this->reindexStorage($storage);

        self::assertFalse($this->falFileAccessFails($storage, 'missing.txt'));

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " --update-refindex -s {$storageId}");
        self::assertEquals(0, $result['status']);

        self::assertFalse($this->falFileAccessFails($storage, 'missing.txt'));
    }

    #[Test]
    public function commandRemovesFalDataOfMissingFileFromNonWritableStorage(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'missing.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $this->setStorageWritableFlag($storageId, false);

        $file = $storage->getFile('missing.txt');

        unlink("{$this->instancePath}/{$storageBasePath}/missing.txt");
        $this->reindexStorage($storage);

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId}");
        self::assertEquals(0, $result['status']);

        self::assertTrue($this->falFileAccessFails($storage, $file->getIdentifier()));
        self::assertFalse($this->getRecordDatabaseRowByUid('sys_file', $file->getUid()));
        self::assertFalse($this->getRecordDatabaseRowByUid('sys_file_metadata', $file->getUid()));
    }

    #[Test]
    public function commandDoesNotRecreateMissingDirectoriesInNonWritableStorage(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'missing' => [],
                'missing/folder' => [],
                'missing/folder/structure' => [
                    'missing.txt'
                ],
            ]
        );
        $storageId = $storage->getUid();

        GeneralUtility::rmdir("{$this->instancePath}/{$storageBasePath}/missing", true);

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/missing/");

        $this->setStorageWritableFlag($storageId, false);

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId}");
        self::assertEquals(0, $result['status']);

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/missing/");
    }
}
