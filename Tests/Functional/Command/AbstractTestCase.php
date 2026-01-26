<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Tests\Functional\Command;

use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractTestCase extends FunctionalTestCase
{
    protected $logFile = __DIR__ . '/../../../.Build/var/log/last_command_output.txt';

    /**
     * Create a fresh storage record.
     *
     * When working with the FAL, each test in itself should not rely on existing files or auto-generated file storages!
     * See TYPO3 documentation for the behavior:
     * https://docs.typo3.org/permalink/t3coreapi:testing-writing-functional-overview
     * Only for the first test of a test class, the instance directory will be completely purged. For each subsequent
     * test, only the database tables will be truncated. This means all FAL storages and records get removed, but the
     * files will still be there! So don't use the auto-generated fileadmin storage (which might not even be there)!
     * Create your storages manually for each test! Otherwise, side effects between tests might happen!
     *
     * ADDITIONAL NOTE: You are responsible for not passing garbage data to this function. Fix your calls if it fails!
     *
     * Example call:
     * ```
     * $this->createStorage(
     *     'some-name',
     *     [
     *         'some-folder' => [
     *             'test-file.txt',
     *             'test-file-2.txt',
     *         ],
     *         'some-folder/subfolder' => [
     *             'test-file-3.txt',
     *         ],
     *         'another-folder' => [],
     *         '' => [
     *             'root-level-file.txt',
     *         ],
     *     ]
     * );
     * ```
     *
     * This will result in a new storage with base path `some-name/`, containing the following files and folders:
     * - `some-folder/`
     * - `some-folder/test-file.txt`
     * - `some-folder/test-file-2.txt`
     * - `some-folder/subfolder/`
     * - `some-folder/subfolder/test-file-3.txt`
     * - `another-folder/`
     * - `root-level-file.txt`
     */
    protected function createStorage(string $basePath, array $initialFileStructure): ResourceStorage
    {
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);

        mkdir("{$this->instancePath}/{$basePath}", 0755);
        $storageUid = $storageRepository->createLocalStorage(
            $basePath,
            "{$basePath}/",
            'relative'
        );
        $storage = $storageRepository->findByUid($storageUid);

        foreach ($initialFileStructure as $folderIdentifier => $fileNames) {
            if ($folderIdentifier === '') {
                $folder = $storage->getRootLevelFolder(false);
            } else {
                $folder = $storage->createFolder($folderIdentifier);
            }
            foreach ($fileNames as $fileName) {
                $storage->createFile($fileName, $folder);
            }
        }

        return $storage;
    }

    /**
     * Create a site configuration for testing.
     *
     * NOTE: Calling this function will remove any site configuration directory with the same identifier.
     */
    protected function setUpSiteConfiguration(
        string $identifier,
        array $configuration = []
    ): void {
        $siteDir = "{$this->instancePath}/typo3conf/sites/{$identifier}/";

        GeneralUtility::rmdir($siteDir, true);
        GeneralUtility::mkdir_deep($siteDir);
        GeneralUtility::writeFile(
            "{$siteDir}/config.yaml",
            \Symfony\Component\Yaml\Yaml::dump($configuration, 99),
            true
        );
    }

    protected function generateUniqueIdentifier(string $input): string
    {
        return substr(sha1($input), 0, 7);
    }

    /**
     * Based on TYPO3\CMS\Core\Tests\Functional\Command\AbstractCommandTest::executeConsoleCommand
     */
    protected function executeConsoleCommand(string $cmdline, ...$args): array
    {
        $typo3File = __DIR__ . '/../../../.Build/bin/typo3';
        if (!file_exists($typo3File)) {
            throw new \RuntimeException(
                sprintf('Executable file <typo3> not found (using path <%s>). Make sure config:bin-dir is set to .Build/bin in composer.json', $typo3File)
            );
        }

        $cmd = vsprintf(PHP_BINARY . ' ' . $typo3File
            . ' ' . $cmdline, array_map(escapeshellarg(...), $args));

        $output = '';

        $handle = popen($cmd, 'r');
        while (!feof($handle)) {
            $output .= fgets($handle, 4096);
        }
        $status = pclose($handle);

        // append output to file
        // make sure the directory exists
        if (!file_exists(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0777, true);
        }
        $entry = $cmdline . "\n" . $output . "\n########################\n\n";
        file_put_contents($this->logFile, $entry, FILE_APPEND);

        return [
            'status' => $status,
            'output' => $output,
        ];
    }
}
