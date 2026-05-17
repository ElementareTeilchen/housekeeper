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
            [
                'rootPageId' => 1,
                'base' => '/',
                'languages' => [
                    [
                        'title' => 'English',
                        'enabled' => true,
                        'languageId' => 0,
                        'base' => '/',
                        'locale' => 'en_US.UTF-8',
                        'navigationTitle' => 'EN',
                        'flag' => 'us',
                    ],
                    [
                        'title' => 'German',
                        'enabled' => true,
                        'languageId' => 1,
                        'base' => '/de',
                        'locale' => 'de_DE.UTF-8',
                        'navigationTitle' => 'DE',
                        'flag' => 'de',
                    ],
                    [
                        'title' => 'French',
                        'enabled' => true,
                        'languageId' => 5,
                        'base' => '/fr',
                        'locale' => 'fr_FR.UTF-8',
                        'navigationTitle' => 'FR',
                        'flag' => 'fr',
                    ],
                ],
            ]
        );
    }

    #[Test]
    public function commandReturnsZeroWithNoMatchingUrls(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandReturnsZeroWithNoMatchingUrls.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -t tt_content -f bodytext -d example.com');

        // Verify no changes were made to the database
        $this->assertCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandReturnsZeroWithNoMatchingUrls.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandConvertsExternalPageUrls(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandConvertsExternalPageUrls.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -t tt_content -f bodytext -d example.com -p fileadmin');

        $this->assertCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandConvertsExternalPageUrls_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandConvertsExternalFileUrls(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandConvertsExternalFileUrls.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -t tt_content -f bodytext -d example.com -p fileadmin');

        $this->assertCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandConvertsExternalFileUrls_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandDoesNotConvertCTypeHtmlBodytextValues(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandDoesNotConvertCTypeHtmlBodytextValues.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -t tt_content -f bodytext -d example.com -p fileadmin');

        $this->assertCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandDoesNotConvertCTypeHtmlBodytextValues.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandDoesNotConvertNonTagAttributesInTcaTypeTextFields(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandDoesNotConvertNonTagAttributesInTcaTypeTextFields.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -t tt_content -f bodytext -d example.com -p fileadmin');

        $this->assertCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandDoesNotConvertNonTagAttributesInTcaTypeTextFields_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandConsolidatesUrlsToDifferentLanguagesCorrectly(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandConsolidatesUrlsToDifferentLanguagesCorrectly.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -t tt_content -f bodytext -d example.com -p fileadmin');

        $this->assertCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandConsolidatesUrlsToDifferentLanguagesCorrectly_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandDoesNothingInDryRunMode(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandDoesNothingInDryRunMode.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -t tt_content -f bodytext -d example.com --dry-run');

        // Verify no changes were made to the database
        $this->assertCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandDoesNothingInDryRunMode.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test]
    public function commandProcessesAllTcaFieldsWhenAllOptionIsUsed(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandProcessesAllTcaFieldsWhenAllOptionIsUsed.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' -a -d example.com');

        // Verify external URLs were converted in all configured tables
        $this->assertCSVDataSet(__DIR__ . '/DataSet/ConsolidateExternalUrlsCommand/commandProcessesAllTcaFieldsWhenAllOptionIsUsed_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }
}
