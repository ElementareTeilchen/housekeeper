<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;

class ConsolidateExternalUrlsCommandTest extends AbstractTestCase
{
    public const BASE_COMMAND = 'housekeeper:consolidate-external-urls testsite -n';

    protected function setUp(): void
    {
        parent::setUp();

        // Set up the site configuration
        $this->setUpSiteConfiguration(
            'testsite',  // This should match your test command identifier
            'https://example.com',
            [
                'base' => '/',
                'languages' => [
                    [
                        'title' => 'English',
                        'enabled' => true,
                        'languageId' => 0,
                        'base' => '/',
                        'typo3Language' => 'default',
                        'locale' => 'en_US.UTF-8',
                        'iso-639-1' => 'en',
                        'navigationTitle' => 'English',
                        'hreflang' => 'en-us',
                        'direction' => 'ltr',
                        'flag' => 'us',
                    ],
                ],
            ]
        );
    }

    /**
     * Create a site configuration for testing
     */
    protected function setUpSiteConfiguration(string $identifier, string $baseUrl, array $configuration = []): void
    {
        $configuration = array_replace_recursive(
            [
                'rootPageId' => 1,
                'base' => $baseUrl,
            ],
            $configuration
        );

        // Create sites configuration directory if it doesn't exist
        $sitesConfigurationDir = $this->instancePath . '/typo3conf/sites';
        if (!file_exists($sitesConfigurationDir)) {
            mkdir($sitesConfigurationDir, 0755, true);
        }

        // Create site directory
        $siteDir = $sitesConfigurationDir . '/' . $identifier;
        if (!file_exists($siteDir)) {
            mkdir($siteDir, 0755, true);
        }

        // Write site configuration
        file_put_contents(
            $siteDir . '/config.yaml',
            \Symfony\Component\Yaml\Yaml::dump($configuration, 99)
        );
    }

    #[Test]
    public function commandReturnsZeroWithNoMatchingUrls(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/tt_content_no_external_urls.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -t tt_content -f bodytext -d example.com');

        // Verify no changes were made to the database
        $this->assertCSVDataSet(__DIR__ . '/DataSet/tt_content_no_external_urls.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandConvertsExternalUrlsToInternalLinks(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/tt_content_external_urls.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -t tt_content -f bodytext -d example.com -p fileadmin');

        // Verify external URLs were converted
        $this->assertCSVDataSet(__DIR__ . '/DataSet/tt_content_external_urls_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandDoesNothingInDryRunMode(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/tt_content_external_urls.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -t tt_content -f bodytext -d example.com --dry-run');

        // Verify no changes were made to the database
        $this->assertCSVDataSet(__DIR__ . '/DataSet/tt_content_external_urls.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandProcessesAllTcaFieldsWhenAllOptionIsUsed(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/multiple_tables_external_urls.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -a -d example.com');

        // Verify external URLs were converted in all configured tables
        $this->assertCSVDataSet(__DIR__ . '/DataSet/multiple_tables_external_urls_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }
}
