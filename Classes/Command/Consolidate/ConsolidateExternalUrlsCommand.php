<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Command\Consolidate;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use Elementareteilchen\Housekeeper\Command\AbstractCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Command for consolidating external URLs in the TYPO3 database
 *
 * This command finds and updates external URLs in the TYPO3 database
 * that match a specified search pattern. It can perform search and replace
 * operations across multiple database tables to update URLs to a new format.
 */
class ConsolidateExternalUrlsCommand extends AbstractCommand
{
    private const DEFAULT_PATH = 'fileadmin';
    private const HTML_CONTENT_TYPE = 'html';
    private const LINK_PATTERN = '((?:href|src)\\s*=\\s*"|^)(%s)("|$)';
    private const T3_URL_TEMPLATE = 't3://%s?uid=%d%s';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $languages = [];

    private bool $log = false;
    private string $siteId = '';
    private string $domain = '';
    private string $path = self::DEFAULT_PATH;

    /**
     * Constructor
     *
     * @param ResourceFactory $resourceFactory
     * @param StorageRepository $storageRepository
     * @param SiteFinder $siteFinder
     */
    public function __construct(
        ResourceFactory $resourceFactory,
        StorageRepository $storageRepository,
        private readonly SiteFinder $siteFinder
    ) {
        parent::__construct($resourceFactory, $storageRepository);
    }

    /**
     * Configure the command
     */
    public function configure()
    {
        $this->setHelp('
        Finds all links (href) and images (src) with a given path or URL pattern within the given table and field
        and replaces them with an internal link (t3://file?uid= or t3://page?uid=) if the file identifier or slug can be found in sys_file or pages.
        ');
        $this->addCommonOptions();
        $this->addOption('table', '-t', InputOption::VALUE_REQUIRED, 'The database table name')
            ->addOption('field', '-f', InputOption::VALUE_REQUIRED, 'The database field name')
            ->addOption('domain', '-d', InputOption::VALUE_REQUIRED, 'The domain to match. E.g. www.your-website.com')
            ->addOption('path', '-p', InputOption::VALUE_REQUIRED, 'The path to match. E.g. fileadmin. Defaults to fileadmin')
            ->addOption('all', '-a', InputOption::VALUE_NONE, 'Run on all fields defined in $GLOBALS[\'TCA\']')
            ->addOption('log', '-l', InputOption::VALUE_NONE, 'Write output to log file in var/log/consolidateExternalUrlsCommand_DATE.log')
            ->addArgument('site', InputArgument::REQUIRED, 'The identifier of the site');
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
            // Initialize common command properties
            $this->initializeCommand($input, $output);

            $this->dryRun = $input->getOption('dry-run');
            $this->log = $input->getOption('log');
            $this->domain = $input->getOption('domain');
            $this->path = $input->getOption('path') ?? 'fileadmin';

            $this->siteId = $input->getArgument('site');

            $runOnAllFields = $input->getOption('all');
            $tableName = $input->getOption('table');
            $fieldName = $input->getOption('field');

            if (!($tableName && $fieldName) && !$runOnAllFields) {
                $this->io->error('Invalid parameters. Please specify either -t and -f or -a.');
                return Command::INVALID;
            }

            if ($this->log) {
                $logFile = Environment::getVarPath() . '/log/consolidateExternalUrlsCommand_' . date('Y-m-d_H-i-s') . '.log';
                $logFileHandle = fopen($logFile, 'w');
                $verbosity = $output->getVerbosity();
                // if there is no verbosity set, set it to very verbose (for the scheduler)
                if ($verbosity === OutputInterface::VERBOSITY_NORMAL) {
                    $verbosity = OutputInterface::VERBOSITY_VERY_VERBOSE;
                }
                $output = new StreamOutput($logFileHandle, $verbosity);
                // Reinitialize io with the new output
                $this->io = new SymfonyStyle($input, $output);
            }

            $site = $this->siteFinder->getSiteByIdentifier($this->siteId);
            $this->io->writeln('Getting languages for site ' . $this->siteId);
            $siteConfiguration = $site->getConfiguration();
            $this->languages = $siteConfiguration['languages'];

            if ($runOnAllFields) {
                $this->runOnAllFromTCA();
            }

            if ($tableName && $fieldName) {
                $this->consolidateExternalUrls($tableName, $fieldName);
            }

            if ($this->log && isset($logFileHandle)) {
                fclose($logFileHandle);
            }

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->handleException($exception);
        }
    }

    /**
     * Consolidate external URLs
     *
     * @param string $tableName The database table name
     * @param string $fieldName The database field name
     */
    public function consolidateExternalUrls(string $tableName, string $fieldName)
    {
        $recordsProcessed = 0;
        $totalMatches = 0;
        $totalReplacedMatches = 0;

        $result = $this->findAllWithExternalUrls($tableName, $fieldName);
        $totalRecords = $result->rowCount();

        $regexp = $this->createRegexp();

        while ($record = $result->fetchAssociative()) {

            $fieldValue = $record[$fieldName];
            $uid = $record['uid'];
            $pid = $record['pid'];

            if ($tableName === 'tt_content' && $fieldName === 'bodytext' && $record['CType'] === 'html') {
                if ($this->io->isVeryVerbose()) {
                    $this->io->writeln("<comment>$recordsProcessed/$totalRecords: skipping CType 'html' record {$tableName}[uid=$uid].{$fieldName} / pid=$pid</comment>");
                }
                continue;
            }

            $recordsProcessed++;

            if ($this->io->isVeryVerbose()) {
                $this->io->writeln("<comment>$recordsProcessed/$totalRecords: checking record {$tableName}[uid=$uid].{$fieldName} / pid=$pid</comment>");
            }

            [$matches, $replacedMatches, $fieldValue] = $this->processPattern($regexp, $fieldValue);

            $totalMatches += $matches;
            $totalReplacedMatches += $replacedMatches;

            if ($fieldValue !== $record[$fieldName]) {

                if ($this->io->isVeryVerbose()) {
                    $this->io->writeln("<info>Updating {$tableName}[uid=$uid].{$fieldName}</info>");
                    if ($this->io->isDebug()) {
                        $this->debugFieldUpdates($fieldValue, $fieldValue);
                    }
                }

                if (!$this->dryRun) {
                    $this->updateRecord($tableName, $fieldName, $uid, $fieldValue);
                }
            }

            if ($matches === 0 && $this->io->isDebug()) {
                $this->logNoMatches($regexp, $fieldValue);
            }
        }
        $this->io->info("Processed $recordsProcessed records of field {$tableName}.{$fieldName}
                    Found $totalMatches occurrences of potential external URLs
                    Replaced $totalReplacedMatches matches with internal links. ");
    }

    /**
     * Process pattern
     *
     * @param string $regexp Regular expression
     * @param string $fieldValue Field value
     * @return array{int, int, string} Array containing [matches count, replaced matches count, updated field value]
     */
    private function processPattern(string $regexp, string $fieldValue): array
    {
        $replacedMatches = 0;
        if (!preg_match_all('%' . $regexp . '%', $fieldValue, $matches)) {
            return [0, 0, $fieldValue];
        }

        $matchCount = count($matches[0]);
        for ($i = 0; $i < $matchCount; $i++) {
            [$changed, $fieldValue] = $this->processMatch($fieldValue, $matches, $i);
            if ($changed) {
                $replacedMatches++;
            }
        }

        return [$matchCount, $replacedMatches, $fieldValue];
    }

    /**
     * Process a single match
     *
     * @param string $fieldValue Field value
     * @param array<int, array<int, string>> $matches Matches from preg_match_all
     * @param int $i Match index
     * @return array{bool, string} Array containing [was changed, updated field value]
     */
    private function processMatch(string $fieldValue, array $matches, int $i): array
    {
        $prefix = $matches[1][$i];
        $urlMatch = $matches[2][$i];

        if ($this->io->isVeryVerbose()) {
            $this->io->writeln("\t<comment>found match: $urlMatch</comment>");
        }

        [$type, $identifier, $anchor] = $this->parseUrl($urlMatch);
        $refResult = $type === 'file'
            ? $this->findSysFileByIdentifier($identifier)
            : $this->findPageBySlug(rtrim($identifier, '/'));

        $result = $refResult->fetchAssociative();
        if (!$result) {
            if ($this->io->isVeryVerbose()) {
                $this->io->writeln("\t<comment>no matching record found</comment>");
            }
            return [false, $fieldValue];
        }

        $uid = $this->resolveUid($result);
        $t3Url = sprintf(self::T3_URL_TEMPLATE, $type, $uid, $anchor);

        if ($this->io->isVerbose()) {
            $this->io->writeln(sprintf('<info>found %s: %d, replacing: %s with %s</info>', $type, $uid, $urlMatch, $t3Url));
        }

        $updatedValue = str_replace($prefix . $urlMatch, $prefix . $t3Url, $fieldValue);

        return [true, $updatedValue];
    }

    /**
     * Parse URL to extract type, identifier and anchor
     *
     * @param string $url The URL to parse
     * @return array{string, string, string} Array containing [type, identifier, anchor]
     */
    private function parseUrl(string $url): array
    {
        if (str_contains($url, '/' . $this->path . '/')) {
            $type = 'file';
            $path = preg_replace('/.*\/' . $this->path . '/', '', $url);
        } else {
            $type = 'page';
            $path = preg_replace('%https?://' . $this->domain . '%', '', $url);
        }

        $parts = explode('#', $path);
        $identifier = trim($parts[0]);
        $anchor = isset($parts[1]) ? '#' . ($type === 'page' ? ltrim($parts[1], 'c') : $parts[1]) : '';

        return [$type, $identifier, $anchor];
    }

    /**
     * Resolve the correct UID from a database result
     *
     * @param array<string, mixed> $result Database result
     * @return int The resolved UID
     */
    private function resolveUid(array $result): int
    {
        $uid = (int)$result['uid'];

        if (!empty($result['l10n_parent']) && (int)$result['l10n_parent'] > 0) {

            $uid = (int)$result['l10n_parent'];

            if ($this->io->isVeryVerbose()) {
                $this->io->writeln(sprintf("\tusing l10n_parent as uid: %d instead of %d", $uid, (int)$result['uid']));
            }
        }

        return $uid;
    }

    /**
     * Find all records with external URLs
     *
     * @param string $tableName The database table name
     * @param string $fieldName The database field name
     * @throws \Doctrine\DBAL\Exception
     */
    private function findAllWithExternalUrls(string $tableName, string $fieldName): Result
    {
        $select = ['uid', 'pid', $fieldName];
        $quote = '';

        if ($tableName === 'tt_content' && $fieldName === 'bodytext') {
            $select[] = 'CType';
            $quote = '"';
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($tableName);

        $pathPattern = '%' . $quote . '/' . $this->path . '/%';
        $httpsPattern = '%' . $quote . 'https://' . $this->domain . '/%';
        $httpPattern = '%' . $quote . 'http://' . $this->domain . '/%';

        return $queryBuilder
            ->select(...$select)
            ->from($tableName)
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like($fieldName, $queryBuilder->createNamedParameter($pathPattern)),
                    $queryBuilder->expr()->like($fieldName, $queryBuilder->createNamedParameter($httpsPattern)),
                    $queryBuilder->expr()->like($fieldName, $queryBuilder->createNamedParameter($httpPattern))
                )
            )
            ->executeQuery();
    }

    /**
     * Update record
     *
     * @param string $tableName The database table name
     * @param string $fieldName The database field name
     * @param int $uid UID
     * @param string $newFieldValue New field value
     */
    public function updateRecord(string $tableName, string $fieldName, int $uid, string $newFieldValue): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($tableName);

        $affectedRows = $queryBuilder
            ->update($tableName)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->set($fieldName, $newFieldValue)
            ->executeStatement();

        if ($affectedRows === 0) {
            $this->io->warning("Failed to update {$tableName}[uid=$uid].{$fieldName}");
        }
    }

    /**
     * Find sys file by identifier
     *
     * @param string $identifier Identifier
     * @return Result Result
     */
    public function findSysFileByIdentifier(string $identifier): Result
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file');

        $result = $queryBuilder
            ->select('uid', 'identifier')
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq('missing', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier, ParameterType::STRING))
            )
            ->executeQuery();
        return $result;
    }

    /**
     * Find page by slug
     *
     * @param string $slug Slug
     * @return Result Result
     */
    public function findPageBySlug(string $slug): Result
    {
        $languageId = 0;
        if (!empty($this->languages) && preg_match('%^/[a-zA-Z]{2}/.*%', $slug)) {
            foreach ($this->languages as $language) {
                if ($language['languageId'] > 0 && str_starts_with($slug, (string)$language['base'])) {
                    if ($this->io->isDebug()) {
                        $this->io->writeln("\t\t<comment>found language {$language['languageId']} for  $slug</comment>");
                    }
                    $languageId = $language['languageId'];
                    $slug = str_replace($language['base'], '/', $slug);
                    break;
                }
            }
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $result = $queryBuilder
            ->select('uid', 'slug', 'l10n_parent')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($slug)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER))
            )
            ->executeQuery();
        return $result;
    }

    /**
     * Log no matches
     *
     * @param string $regexp Regular expression
     * @param string $fieldValue Field value
     */
    public function logNoMatches(string $regexp, string $fieldValue)
    {
        $this->io->writeln("No matches found with regex $regexp");
        if ($this->io->isDebug()) {
            $this->io->writeln("\t-> likely matches that did not match the regex:");
            preg_match_all('%((?:<[^<]*)?"[^"\\s]*/' . $this->path . '/[^"]*)%', $fieldValue, $fileadminMatches);
            foreach (array_unique($fileadminMatches[0]) as $key => $value) {
                $this->io->writeln("\t\t$value");
            }
        } else {
            $this->io->writeln('Enable debug (-vvv) to see all matches');
        }
    }

    /**
     * Debug field updates
     *
     * @param mixed $fieldValue Field value
     * @param mixed $newFieldValue New field value
     */
    public function debugFieldUpdates(mixed $fieldValue, mixed $newFieldValue): void
    {
        $this->io->writeln('################### REPLACING');
        $this->io->writeln($fieldValue);
        $this->io->writeln('################### WITH ');
        $this->io->writeln($newFieldValue);
        $this->io->writeln('################### END ');
    }

    /**
     * Run on all from TCA
     */
    public function runOnAllFromTCA(): void
    {
        if ($this->io->isVerbose()) {
            $this->io->info('Getting tables and fields from TCA');
        }

        $search = function (array $array, string $field, string $term) {
            $iterator = new \RecursiveArrayIterator($array);
            $leafs = new \RecursiveIteratorIterator($iterator);
            $search = new \RegexIterator($leafs, sprintf('~%s~', $term));
            foreach ($search as $value) {
                $key = $leafs->key();
                if ($key === $field) {

                    $keys = [];
                    for ($i = $leafs->getDepth() - 1; $i >= 0; $i--) {
                        if ($i === 2 || $i === 0) {
                            array_unshift($keys, $leafs->getSubIterator($i)->key());
                        }
                    }
                    yield $keys;
                }
            }
        };

        $results = [];
        $matches = iterator_to_array($search($GLOBALS['TCA'], 'softref', 'typolink'));
        $matches = array_merge($matches, iterator_to_array($search($GLOBALS['TCA'], 'type', '^link$')));

        if ($this->io->isVerbose()) {
            $this->io->writeln('<info>Found the following:</info>');
        }
        foreach ($matches as $index => $match) {
            $tableName = $match[0];
            $fieldName = $match[1];
            $results[$tableName] = $fieldName;
            if ($this->io->isVerbose()) {
                $this->io->writeln("<comment>$tableName.$fieldName</comment>");
            }
        }
        foreach ($results as $tableName => $fieldName) {
            if (empty($fieldName)) {
                $this->io->warning("Skipping $tableName as no field could be found");
                continue;
            }
            if ($this->io->isVerbose()) {
                $this->io->info("Running on $tableName.$fieldName");
            }
            $this->consolidateExternalUrls($tableName, $fieldName);
        }
    }

    /**
     * @return string
     */
    public function createRegexp(): string
    {
        $domain = str_replace('.', '\.', $this->domain);
        $pattern = '(?:https?://' . $domain . ')?/' . $this->path . '/|https?://' . $domain . '/(?!' . $this->path . ')';
        $regexp = '((?:href|src)\\s*=\\s*"|^)((?:' . $pattern . ')[^"$]*)("|$)';
        return $regexp;
    }
}
