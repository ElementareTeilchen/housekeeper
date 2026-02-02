<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class FilesCleanupCommandTest extends AbstractTestCase
{
    public const BASE_COMMAND = 'housekeeper:cleanup-files';

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

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n .png");
        self::assertEquals(0, $result['status']);

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/testfile.png");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/testfile.txt");
    }

    #[Test]
    public function commandCanWorkWithSpecificStorageId(): void
    {
        $firstStorageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}-1";
        $firstStorage = $this->createStorage(
            $firstStorageBasePath,
            [
                '' => [
                    'testfile.png',
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
                    'testfile.png',
                    'testfile.txt',
                ],
            ]
        );
        $secondStorageId = $secondStorage->getUid();

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$firstStorageId} .png");
        self::assertEquals(0, $result['status']);
        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$secondStorageId} .txt");
        self::assertEquals(0, $result['status']);

        self::assertFileDoesNotExist("{$this->instancePath}/{$firstStorageBasePath}/testfile.png");
        self::assertFileExists("{$this->instancePath}/{$firstStorageBasePath}/testfile.txt");

        self::assertFileExists("{$this->instancePath}/{$secondStorageBasePath}/testfile.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$secondStorageBasePath}/testfile.txt");
    }

    #[Test]
    public function commandFindsAndDeletesFilesMatchingExtension(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'testimg1.png',
                    'testimg2.png',
                    'testfile1.txt',
                ],
                'subfolder' => [
                    'testimg3.png',
                    'testfile2.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId} .png");
        self::assertEquals(0, $result['status']);

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/testimg1.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/testimg2.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/subfolder/testimg3.png");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/testfile1.txt");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/subfolder/testfile2.txt");
    }

    #[Test]
    public function commandFindsAndDeletesFilesMatchingFilename(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'target.png',
                    'target-with-suffix.png',
                    'prefixed-target.png',
                    'anotherfile.txt',
                ],
                'subfolder' => [
                    'target.png',
                    'target-with-suffix.png',
                    'prefixed-target.png',
                    'anotherfile.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId} target");
        self::assertEquals(0, $result['status']);

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/anotherfile.txt");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/subfolder/anotherfile.txt");

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/target.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/target-with-suffix.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/prefixed-target.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/subfolder/target.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/subfolder/target-with-suffix.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/subfolder/prefixed-target.png");
    }

    #[Test]
    public function commandDeletesFilesMatchingFilenameAndExtension(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'target.png',
                    'target-with-suffix.png',
                    'prefixed-target.png',
                    'anotherfile.txt',
                ],
                'subfolder' => [
                    'target.png',
                    'target-with-suffix.png',
                    'prefixed-target.png',
                    'anotherfile.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId} target.png");
        self::assertEquals(0, $result['status']);

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/anotherfile.txt");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/subfolder/anotherfile.txt");

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/target-with-suffix.png");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/subfolder/target-with-suffix.png");

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/target.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/prefixed-target.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/subfolder/target.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/subfolder/prefixed-target.png");
    }

    #[Test]
    public function commandDoesNothingInDryRunMode(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'testimg1.png',
                    'testimg2.png',
                    'testimg3.png',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId} --dry-run .png");
        self::assertEquals(0, $result['status']);

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/testimg1.png");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/testimg2.png");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/testimg3.png");
    }

    #[Test]
    public function commandCanNotDeleteDeniedFiles(): void
    {
        // Temporarily disable, so we can create all file types via FAL API
        $GLOBALS['TYPO3_CONF_VARS']['BE']['fileDenyPattern'] = '';

        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'testfile.png',
                    'testfile.txt',
                    'testfile.pdf',
                    'testfile.php',
                    'testfile.php.test',
                    'testfile.cgi',
                ],
            ]
        );
        $storageId = $storage->getUid();

        // The console command is executed in a separate context, so our modified fileDenyPattern does not apply there!
        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId} testfile");
        self::assertEquals(0, $result['status']);

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/testfile.php");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/testfile.php.test");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/testfile.cgi");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/testfile.txt");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/testfile.png");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/testfile.pdf");
    }

    #[Test]
    public function commandDoesNotDeleteFilesNotIndexedInFal(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            []
        );
        $storageId = $storage->getUid();

        GeneralUtility::writeFile(
            "{$this->instancePath}/{$storageBasePath}/testfile.txt",
            '',
            true
        );
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/testfile.txt");

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId} testfile.txt");
        self::assertEquals(0, $result['status']);

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/testfile.txt");
    }

    #[Test]
    public function commandDeletesFalRecordData(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'testfile.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $file = $storage->getFile('testfile.txt');
        self::assertNotEmpty($file);;
        self::assertNotEmpty($this->getRecordDatabaseRowByUid('sys_file', $file->getUid()));
        self::assertNotEmpty($this->getRecordDatabaseRowByUid('sys_file_metadata', $file->getUid()));

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId} testfile.txt");
        self::assertEquals(0, $result['status']);

        self::assertEmpty($this->getRecordDatabaseRowByUid('sys_file', $file->getUid()));
        self::assertEmpty($this->getRecordDatabaseRowByUid('sys_file_metadata', $file->getUid()));
        self::assertTrue($this->falFileAccessFails($storage, 'testfile.txt'));
    }

    #[Test]
    public function commandDoesNotDeleteFalRecordDataOfMissingFile(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'testfile.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        unlink("{$this->instancePath}/{$storageBasePath}/testfile.txt");

        $this->reindexStorage($storage);

        $file = $storage->getFile('testfile.txt');
        self::assertTrue($file->isMissing());
        self::assertTrue(
            $this->getRecordDatabaseRowByUid('sys_file', $file->getUid())['missing'] === 1
        );

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId} testfile.txt");
        self::assertEquals(0, $result['status']);

        self::assertNotEmpty(
            $this->getRecordDatabaseRowByUid('sys_file', $file->getUid())
        );
        self::assertNotEmpty(
            $this->getRecordDatabaseRowByUid('sys_file_metadata', $file->getUid())
        );
        self::assertFalse($this->falFileAccessFails($storage, 'testfile.txt'));
    }

    #[Test]
    public function commandDoesNotDeleteFileWronglyMarkedAsMissing(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'testfile.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $file = $storage->getFile('testfile.txt');

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

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -s {$storageId} testfile.txt");
        self::assertEquals(0, $result['status']);

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/testfile.txt");
        self::assertNotEmpty(
            $this->getRecordDatabaseRowByUid('sys_file', $file->getUid())
        );
        self::assertNotEmpty(
            $this->getRecordDatabaseRowByUid('sys_file_metadata', $file->getUid())
        );
        self::assertFalse($this->falFileAccessFails($storage, 'testfile.txt'));
    }

    #[Test]
    public function commandRemovesEmptyParentFolder(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'dir-to-delete' => [
                    'delete.txt',
                ],
                'dir-to-delete-2' => [
                    'delete.txt',
                    'also-delete.txt',
                ],
                'dir-to-delete-3' => [
                    'delete.txt',
                ],
                'dir-to-delete-3/dir-to-delete' => [
                    'delete.txt',
                ],
                'dir-to-keep' => [
                    'delete.txt',
                    'keep.txt',
                ],
                'dir-to-keep-2' => [],
                'dir-to-keep_2/dir-to-delete' => [
                    'delete.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -e -s {$storageId} delete.txt");
        self::assertEquals(0, $result['status']);

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/dir-to-delete/");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/dir-to-delete-2/");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/dir-to-keep-2/dir-to-delete/");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/dir-to-delete-3/");

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/dir-to-keep/");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/dir-to-keep-2/");
    }

    #[Test]
    public function commandRemovesEmptyParentFolderRecursively(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'dir-to-delete' => [
                    'delete.txt',
                ],
                'dir-to-delete/dir-to-delete' => [
                    'delete.txt',
                ],
                'dir-to-delete-2' => [],
                'dir-to-delete-2/dir-to-delete' => [
                    'delete.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " -n -e recursive -s {$storageId} delete.txt");
        self::assertEquals(0, $result['status']);

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/dir-to-delete/");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/dir-to-delete-2/");
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
        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " --update-refindex -s {$storageId} delete.txt");
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
        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " --update-refindex -s {$storageId} delete.txt");
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
    public function commandDoesNotRemoveReferencedFile(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                '' => [
                    'delete.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $file = $storage->getFileByIdentifier('/delete.txt');

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

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " --update-refindex -s {$storageId} delete.txt");
        self::assertEquals(0, $result['status']);

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/delete.txt");
    }
}
