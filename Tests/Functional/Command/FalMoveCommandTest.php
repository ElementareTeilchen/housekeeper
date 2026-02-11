<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;

class FalMoveCommandTest extends AbstractTestCase
{
    public const BASE_COMMAND = 'housekeeper:move';

    #[Test]
    public function commandMovesFileToNewLocation(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'source_folder' => [
                    '1_test_file.txt',
                ],
                'target_folder' => [],
            ]
        );
        $storageId = $storage->getUid();

        // Use combined identifiers with reference to storage ID 1
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$storageId}:source_folder/1_test_file.txt {$storageId}:target_folder/"
        );

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/source_folder/1_test_file.txt");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/target_folder/1_test_file.txt");
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMovesFileWithNewName(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'source_folder' => [
                    '1_test_file.txt',
                ],
                'target_folder' => [],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$storageId}:source_folder/1_test_file.txt {$storageId}:target_folder/renamed_file.txt"
        );

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/source_folder/1_test_file.txt");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/target_folder/renamed_file.txt");
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandRenamesFileInSameFolder(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'source_folder' => [
                    '1_test_file.txt',
                ],
                'target_folder' => [],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$storageId}:/source_folder/1_test_file.txt {$storageId}:/source_folder/renamed_file.txt"
        );

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/source_folder/1_test_file.txt");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/source_folder/renamed_file.txt");
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMovesEntireFolder(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'source_folder' => [],
                'source_folder/subfolder' => [],
                'target_folder' => [],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$storageId}:source_folder/subfolder {$storageId}:target_folder/"
        );

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/source_folder/subfolder");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/target_folder/subfolder");
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMovesFolderWithSameName(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'source_folder' => [],
                'source_folder/subfolder' => [],
                'target_folder' => [],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$storageId}:source_folder/subfolder {$storageId}:target_folder/subfolder"
        );

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/source_folder/subfolder");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/target_folder/subfolder");
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMovesFolderWithSpecialChars(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'source_folder' => [],
                'source_folder/subfolder' => [],
                'target_folder' => [],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$storageId}:source_folder/subfolder {$storageId}:target_folder/sub:@=folder"
        );

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/source_folder/subfolder");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/target_folder/sub_@_folder");
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMoveAndRenameFolderToBaseFolder(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'source_folder' => [],
                'source_folder/subfolder' => [],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$storageId}:source_folder/subfolder {$storageId}:new_folder"
        );

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/source_folder/subfolder");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/new_folder");
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMoveAndRenameFolderToBaseFolderWithSlash(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'source_folder' => [],
                'source_folder/subfolder' => [],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$storageId}:/source_folder/subfolder {$storageId}:/new_folder_slash"
        );

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/source_folder/subfolder");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/new_folder_slash");

        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandRenameFolderInBaseFolder(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'source_folder' => [],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$storageId}:source_folder {$storageId}:renamed_folder"
        );

        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/source_folder");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/renamed_folder");
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMovesFileToOtherStorage(): void
    {
        $firstStorageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}-1";
        $firstStorage = $this->createStorage(
            $firstStorageBasePath,
            [
                'source_folder' => [
                    '1_test_file.txt',
                ],
            ]
        );
        $firstStorageId = $firstStorage->getUid();

        $secondStorageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}-2";
        $secondStorage = $this->createStorage(
            $secondStorageBasePath,
            [
                'target_folder' => [],
            ]
        );
        $secondStorageId = $secondStorage->getUid();

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$firstStorageId}:source_folder/1_test_file.txt {$secondStorageId}:target_folder/"
        );

        self::assertFileDoesNotExist("{$this->instancePath}/{$firstStorageBasePath}/source_folder/1_test_file.txt");
        self::assertFileExists("{$this->instancePath}/{$secondStorageBasePath}/target_folder/1_test_file.txt");
        self::assertEquals(0, $result['status']);
    }

    /**
     * \TYPO3\CMS\Core\Resource\ResourceStorage::moveFolderBetweenStorages
     * is not yet implemented, thus this fails
     */
    #[Test]
    public function commandDoesNotMoveFolderToOtherStorage(): void
    {
        $firstStorageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}-1";
        $firstStorage = $this->createStorage(
            $firstStorageBasePath,
            [
                'source_folder' => [],
                'source_folder/subfolder' => [],
            ]
        );
        $firstStorageId = $firstStorage->getUid();

        $secondStorageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}-2";
        $secondStorage = $this->createStorage(
            $secondStorageBasePath,
            [
                'target_folder' => [],
            ]
        );
        $secondStorageId = $secondStorage->getUid();

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$firstStorageId}:/source_folder/subfolder 2:target_folder/subfolder"
        );

        // make sure it did not work
        self::assertFileExists("{$this->instancePath}/{$firstStorageBasePath}/source_folder/subfolder");
        self::assertFileDoesNotExist("{$this->instancePath}/{$firstStorageBasePath}/target_folder/subfolder");

        // Check that the exit status indicates failure
        self::assertNotEquals(0, $result['status']);
    }

    #[Test]
    public function commandFailsWhenTargetExists(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'source_folder' => [
                    '1_test_file.txt',
                ],
                'target_folder' => [
                    '1_test_file.txt',
                ],
            ]
        );
        $storageId = $storage->getUid();

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$storageId}:/source_folder/1_test_file.txt {$storageId}:/target_folder/1_test_file.txt"
        );

        // make sure it did not work
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/source_folder/1_test_file.txt");
        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/target_folder/1_test_file.txt");

        // Check that the exit status indicates failure
        self::assertNotEquals(0, $result['status']);
    }

    #[Test]
    public function commandDoesNotMoveFilesInNonWritableStorage(): void
    {
        $storageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}";
        $storage = $this->createStorage(
            $storageBasePath,
            [
                'source_folder' => [
                    'testfile.txt',
                ],
                'target_folder' => [
                ],
            ]
        );
        $storageId = $storage->getUid();
        $this->setStorageWritableFlag($storageId, false);

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$storageId}:/source_folder/testfile.txt {$storageId}:/target_folder/testfile.txt"
        );
        self::assertNotEquals(0, $result['status']);

        self::assertFileExists("{$this->instancePath}/{$storageBasePath}/source_folder/testfile.txt");
        self::assertFileDoesNotExist("{$this->instancePath}/{$storageBasePath}/target_folder/testfile.txt");
    }

    #[Test]
    public function commandDoesNotMoveFilesToNonWritableStorage(): void
    {
        $firstStorageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}-1";
        $firstStorage = $this->createStorage(
            $firstStorageBasePath,
            [
                '' => [
                    'testfile.txt',
                ],
            ]
        );
        $firstStorageId = $firstStorage->getUid();

        $secondStorageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}-2";
        $secondStorage = $this->createStorage(
            $secondStorageBasePath,
            [
                '' => [],
            ]
        );
        $secondStorageId = $secondStorage->getUid();

        $this->setStorageWritableFlag($secondStorageId, false);

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$firstStorageId}:/testfile.txt {$secondStorageId}:/testfile.txt"
        );
        self::assertNotEquals(0, $result['status']);

        self::assertFileExists("{$this->instancePath}/{$firstStorageBasePath}/testfile.txt");
        self::assertFileDoesNotExist("{$this->instancePath}/{$secondStorageBasePath}/testfile.txt");
    }

    #[Test]
    public function commandDoesNotMoveFilesFromNonWritableStorage(): void
    {
        $firstStorageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}-1";
        $firstStorage = $this->createStorage(
            $firstStorageBasePath,
            [
                '' => [
                    'testfile.txt',
                ],
            ]
        );
        $firstStorageId = $firstStorage->getUid();

        $secondStorageBasePath = "storage-{$this->generateUniqueIdentifier(__FUNCTION__)}-2";
        $secondStorage = $this->createStorage(
            $secondStorageBasePath,
            [
                '' => [],
            ]
        );
        $secondStorageId = $secondStorage->getUid();

        $this->setStorageWritableFlag($firstStorageId, false);

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . " {$firstStorageId}:/testfile.txt {$secondStorageId}:/testfile.txt"
        );
        self::assertNotEquals(0, $result['status']);

        self::assertFileExists("{$this->instancePath}/{$firstStorageBasePath}/testfile.txt");
        self::assertFileDoesNotExist("{$this->instancePath}/{$secondStorageBasePath}/testfile.txt");
    }
}
