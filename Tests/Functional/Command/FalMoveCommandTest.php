<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class FalMoveCommandTest extends AbstractTestCase
{
    public const BASE_COMMAND = 'housekeeper:move';

    public const FILE_NAMES = [
        '1_test_file.txt',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);

        foreach (self::STORAGE_IDS as $storageUid) {

            $storage = $storageRepository->findByUid($storageUid); // Storage UID 1 = ' . $this->main_storage_name . '

            if ($storage->hasFolder('source_folder') === false) {
                $sourceFolder = $storage->createFolder('source_folder');
            } else {
                $sourceFolder = $storage->getFolder('source_folder');
            }
            if ($storage->hasFolder('source_folder/subfolder') === false) {
                $storage->createFolder('source_folder/subfolder');
            }
            if ($storage->hasFolder('target_folder') === false) {
                $storage->createFolder('target_folder');
            }

            if ($storage->hasFolder('target_folder/subfolder') === true) {
                $targetSubfolder = $storage->getFolder('target_folder/subfolder');
                if ($targetSubfolder) {
                    $storage->deleteFolder($targetSubfolder);
                }
            }

            foreach (self::FILE_NAMES as $fileName) {
                touch($fileName);
                try {
                    if (class_exists(\TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior::class)) {
                        $storage->addFile($fileName, $sourceFolder, '', \TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior::CANCEL);
                    } else {
                        $storage->addFile($fileName, $sourceFolder, '');
                    }
                } catch (ExistingTargetFileNameException $e) {
                    unlink($fileName);
                }
            }
        }
    }

    #[Test]
    public function commandMovesFileToNewLocation(): void
    {
        // Use combined identifiers with reference to storage ID 1
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . '  1:source_folder/1_test_file.txt 1:target_folder/'
        );

        self::assertFileDoesNotExist($this->instancePath . '/' . $this->main_storage . '/source_folder/1_test_file.txt');
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/target_folder/1_test_file.txt');

        // We can only assert the exit status until we can determine why
        // the storage configuration isn't being recognized properly
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMovesFileWithNewName(): void
    {
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 1:source_folder/1_test_file.txt 1:target_folder/renamed_file.txt'
        );

        self::assertFileDoesNotExist($this->instancePath . '/' . $this->main_storage . '/source_folder/1_test_file.txt');
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/target_folder/renamed_file.txt');

        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandRenamesFileInSameFolder(): void
    {
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 1:/source_folder/1_test_file.txt 1:/source_folder/renamed_file.txt'
        );

        self::assertFileDoesNotExist($this->instancePath . '/' . $this->main_storage . '/source_folder/1_test_file.txt');
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/source_folder/renamed_file.txt');

        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMovesEntireFolder(): void
    {
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 1:source_folder/subfolder 1:target_folder/'
        );

        self::assertFileDoesNotExist($this->instancePath . '/' . $this->main_storage . '/source_folder/subfolder');
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/target_folder/subfolder');

        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMovesFolderWithSameName(): void
    {
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 1:source_folder/subfolder 1:target_folder/subfolder'
        );

        self::assertFileDoesNotExist($this->instancePath . '/' . $this->main_storage . '/source_folder/subfolder');
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/target_folder/subfolder');

        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMovesFolderWithSpecialChars(): void
    {
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 1:source_folder/subfolder 1:target_folder/sub:@=folder'
        );

        self::assertFileDoesNotExist($this->instancePath . '/' . $this->main_storage . '/source_folder/subfolder');
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/target_folder/sub_@_folder');

        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMoveAndRenameFolderToBaseFolder(): void
    {
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 1:source_folder/subfolder 1:new_folder'
        );

        self::assertFileDoesNotExist($this->instancePath . '/' . $this->main_storage . '/source_folder/subfolder');
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/new_folder');

        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMoveAndRenameFolderToBaseFolderWithSlash(): void
    {
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 1:/source_folder/subfolder 1:/new_folder_slash'
        );

        self::assertFileDoesNotExist($this->instancePath . '/' . $this->main_storage . '/source_folder/subfolder');
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/new_folder_slash');

        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandRenameFolderInBaseFolder(): void
    {
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 1:source_folder 1:renamed_folder'
        );

        self::assertFileDoesNotExist($this->instancePath . '/' . $this->main_storage . '/source_folder');
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/renamed_folder');

        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandMovesFileToOtherStorage(): void
    {
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 1:source_folder/1_test_file.txt 2:target_folder/'
        );

        self::assertFileDoesNotExist($this->instancePath . '/' . $this->main_storage . '/source_folder/1_test_file.txt');
        self::assertFileExists($this->instancePath . '/' . $this->other_storage . '/target_folder/1_test_file.txt');

        self::assertEquals(0, $result['status']);
    }

    /**
     * \TYPO3\CMS\Core\Resource\ResourceStorage::moveFolderBetweenStorages
     * is not yet implemented, thus this fails
     */
    #[Test]
    public function commandMovesFolderToOtherStorage(): void
    {
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 1:source_folder/subfolder 2:target_folder/subfolder'
        );

        // make sure it did not work
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/source_folder/subfolder');
        self::assertFileDoesNotExist($this->instancePath . '/' . $this->other_storage . '/target_folder/subfolder');

        // Check that the exit status indicates failure
        self::assertNotEquals(0, $result['status']);
    }

    #[Test]
    public function commandMovesFolderWithinOtherStorage(): void
    {
        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 2:source_folder/subfolder 2:target_folder/moved_folder'
        );

        self::assertFileDoesNotExist($this->instancePath . '/' . $this->other_storage . '/source_folder/subfolder');
        self::assertFileExists($this->instancePath . '/' . $this->other_storage . '/target_folder/moved_folder');

        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandFailsWhenTargetExists(): void
    {
        // First create the target file so it exists
        copy(
            $this->instancePath . '/' . $this->main_storage . '/source_folder/1_test_file.txt',
            $this->instancePath . '/' . $this->main_storage . '/target_folder/1_test_file.txt'
        );

        $result = $this->executeConsoleCommand(
            self::BASE_COMMAND . ' 1:/source_folder/1_test_file.txt 1:/target_folder/1_test_file.txt'
        );

        // make sure it did not work
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/source_folder/1_test_file.txt');
        self::assertFileExists($this->instancePath . '/' . $this->main_storage . '/target_folder/1_test_file.txt');


        // Check that the exit status indicates failure
        self::assertNotEquals(0, $result['status']);
    }
}
