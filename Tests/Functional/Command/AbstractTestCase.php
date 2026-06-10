<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Tests\Functional\Command;

use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Console\Application as ConsoleApplication;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractTestCase extends FunctionalTestCase
{
    protected $logFile = __DIR__ . '/../../../.Build/var/log/last_command_output.txt';

    protected array $testExtensionsToLoad = [
        'elementareteilchen/housekeeper',
    ];

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
            $folder = $folderIdentifier === '' ? $storage->getRootLevelFolder(false) : $storage->createFolder($folderIdentifier);
            foreach ($fileNames as $fileName) {
                $storage->createFile($fileName, $folder);
            }
        }

        return $storage;
    }

    protected function setStorageWritableFlag(int $uid, bool $isWritable): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('sys_file_storage')
            ->update(
                'sys_file_storage',
                ['is_writable' => (int)$isWritable],
                ['uid' => $uid],
                [
                    Connection::PARAM_INT,
                    Connection::PARAM_INT,
                ]
            );
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
            Yaml::dump($configuration, 99),
            true
        );
    }

    protected function getRecordDatabaseRowByUid(string $table, int $uid): array|false
    {
        return $this->getConnectionPool()
            ->getConnectionForTable($table)
            ->select(
                ['*'],
                $table,
                ['uid' => $uid]
            )
            ->fetchAssociative();
    }

    /**
     * Search for a specific refindex entry.
     *
     * @param string $referencedTable The table that IS referenced
     * @param int $referencedUid The UID of the record that IS referenced
     * @param string $referencingTable The table that HOLDS the reference
     * @param string $referencingField The field of the table that HOLDS the reference
     * @param int $referencingUid The UID of the record that HOLDS the reference
     */
    protected function getReferenceIndexEntry(
        string $referencedTable,
        int $referencedUid,
        string $referencingTable,
        string $referencingField,
        int $referencingUid
    ): array|false {
        return $this->getConnectionPool()
            ->getConnectionForTable('sys_refindex')
            ->select(
                ['*'],
                'sys_refindex',
                [
                    'ref_table' => $referencedTable,
                    'ref_uid' => $referencedUid,
                    'tablename' => $referencingTable,
                    'field' => $referencingField,
                    'recuid' => $referencingUid,
                ]
            )
            ->fetchAssociative();
    }

    protected function reindexStorage(ResourceStorage $storage): void
    {
        GeneralUtility::makeInstance(Indexer::class, $storage)->processChangesInStorages();
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
        $input = new StringInput(vsprintf($cmdline, array_map(escapeshellarg(...), $args)));
        $input->setInteractive(false);
        $output = new BufferedOutput();

        Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $application = new ConsoleApplication('TYPO3 CMS', (new Typo3Version())->getVersion());
        $application->setAutoExit(false);
        $application->setDispatcher($this->get(EventDispatcherInterface::class));
        $application->setCommandLoader($this->get(CommandRegistry::class));
        $status = $application->run($input, $output);
        $outputContent = $output->fetch();

        // append output to file
        // make sure the directory exists
        if (!file_exists(dirname((string) $this->logFile))) {
            mkdir(dirname((string) $this->logFile), 0777, true);
        }
        $entry = $cmdline . "\n" . $outputContent . "\n########################\n\n";
        file_put_contents($this->logFile, $entry, FILE_APPEND);

        return [
            'status' => $status,
            'output' => $outputContent,
        ];
    }

    /**
     * Returns `true`, if file access via FAL API fails and causes an exception.
     */
    protected function falFileAccessFails(ResourceStorage $storage, string $identifier): bool
    {
        try {
            $storage->getFile($identifier);
        } catch (\Throwable) {
            return true;
        }
        return false;
    }
}
