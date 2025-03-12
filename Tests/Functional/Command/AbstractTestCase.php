<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Tests\Functional\Command;

use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractTestCase extends FunctionalTestCase
{
    protected $logFile = __DIR__ . '/../../../.Build/var/log/last_command_output.txt';

    public const STORAGE_IDS = [1, 2];
    protected $main_storage = 'fileadmin';
    protected $other_storage = 'otherstorage';

    /**
     * Set up the test case
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure otherstorage directory exists
        if (!is_dir($this->instancePath . '/' . $this->other_storage)) {
            mkdir($this->instancePath . '/' . $this->other_storage, 0755, true);
        }

        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storage = $storageRepository->findByUid(2);

        if ($storage === null) {
            $storageRepository->createLocalStorage($this->other_storage, $this->other_storage . '/', 'relative');
        }

        //        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_storage.csv');
    }

    /**
     * Tear down the test case
     */
    protected function tearDown(): void
    {
        parent::tearDown();

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
