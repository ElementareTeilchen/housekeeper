<?php

namespace Elementareteilchen\Housekeeper\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;

class SysLogCleanupCommandTest extends AbstractTestCase
{
    protected const BASE_COMMAND = 'housekeeper:cleanup-syslog';
    protected const MOCK_FILE_PATH = __DIR__ . '/DataSet/SysLogCleanupCommand';
    protected const DEFAULT_RETENTION_PERIOD = 10;
    protected const DEFAULT_CUTOFF_PERIOD = 360;

    #[Test]
    public function commandFailsIfRetentionPeriodGreaterThanCutoffPeriod(): void
    {
        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " --retentionPeriod 20 --cutoffPeriod 10");
        self::assertNotEquals(0, $result['status']);
    }

    #[Test]
    public function commandDoesNotTouchRecordsNewerThanRetentionPeriod(): void
    {
        $this->setUpSinglePeriodMockLogEntriesWithTstamp(
            time() - (self::DEFAULT_RETENTION_PERIOD - 1) * 86400
        );

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);
        self::assertEquals(0, $result['status']);

        $this->assertCSVDataSet(self::MOCK_FILE_PATH . '/sys_log-mock-entries-without-tstamp--single-period.csv');
    }

    #[Test]
    public function commandDeletesRecordsWithinRetentionPeriodCorrectly(): void
    {
        $this->setUpSinglePeriodMockLogEntriesWithTstamp(
            time() - (self::DEFAULT_RETENTION_PERIOD + 1) * 86400
        );

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);
        self::assertEquals(0, $result['status']);

        $expectedRemainingRecordsUidList = [107];
        foreach ($expectedRemainingRecordsUidList as $uid) {
            $this->assertIsArray($this->getRecordDatabaseRowByUid('sys_log', $uid));
        }

        $expectedDeletedRecordsUidList = [101, 102, 103, 104, 105, 106];
        foreach ($expectedDeletedRecordsUidList as $uid) {
            $this->assertFalse($this->getRecordDatabaseRowByUid('sys_log', $uid));
        }
    }

    #[Test]
    public function commandDeletesAllRecordsOlderThanCutoffPeriod(): void
    {
        $this->setUpSinglePeriodMockLogEntriesWithTstamp(
            time() - (self::DEFAULT_CUTOFF_PERIOD + 1) * 86400
        );

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);
        self::assertEquals(0, $result['status']);

        $expectedDeletedRecordsUidList = [101, 102, 103, 104, 105, 106, 107];
        foreach ($expectedDeletedRecordsUidList as $uid) {
            $this->assertFalse($this->getRecordDatabaseRowByUid('sys_log', $uid));
        }
    }

    #[Test]
    public function commandDoesNothingInDryRunMode(): void
    {
        $this->setUpSinglePeriodMockLogEntriesWithTstamp(
            time() - (self::DEFAULT_CUTOFF_PERIOD + 1) * 86400
        );

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' --dry-run');
        self::assertEquals(0, $result['status']);

        $this->assertCSVDataSet(self::MOCK_FILE_PATH . '/sys_log-mock-entries-without-tstamp--single-period.csv');
    }

    #[Test]
    public function commandHandlesMultiplePeriodsCorrectly(): void
    {
        $this->setUpMultiPeriodMockLogEntriesWithTstamp(
            time() - (self::DEFAULT_RETENTION_PERIOD - 1) * 86400,
            time() - (self::DEFAULT_RETENTION_PERIOD + 1) * 86400,
            time() - (self::DEFAULT_CUTOFF_PERIOD + 1) * 86400
        );

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);
        self::assertEquals(0, $result['status']);

        $expectedRemainingRecordsUidList = [101, 102, 103, 104, 105, 106, 107, 207];
        foreach ($expectedRemainingRecordsUidList as $uid) {
            $this->assertIsArray($this->getRecordDatabaseRowByUid('sys_log', $uid));
        }

        $expectedDeletedRecordsUidList = [201, 202, 203, 204, 205, 206, 301, 302, 303, 304, 305, 306, 307];
        foreach ($expectedDeletedRecordsUidList as $uid) {
            $this->assertFalse($this->getRecordDatabaseRowByUid('sys_log', $uid));
        }
    }

    #[Test]
    public function periodsCanBeSetViaArgumentsLow(): void
    {
        $retentionPeriod = self::DEFAULT_CUTOFF_PERIOD + 20;
        $cutoffPeriod = self::DEFAULT_CUTOFF_PERIOD + 30;

        $this->setUpMultiPeriodMockLogEntriesWithTstamp(
            time() - ($retentionPeriod - 1) * 86400,
            time() - ($retentionPeriod + 1) * 86400,
            time() - ($cutoffPeriod + 1) * 86400
        );

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " --retentionPeriod {$retentionPeriod} --cutoffPeriod {$cutoffPeriod}");
        self::assertEquals(0, $result['status']);

        $expectedRemainingRecordsUidList = [101, 102, 103, 104, 105, 106, 107, 207];
        foreach ($expectedRemainingRecordsUidList as $uid) {
            $this->assertIsArray($this->getRecordDatabaseRowByUid('sys_log', $uid));
        }

        $expectedDeletedRecordsUidList = [201, 202, 203, 204, 205, 206, 301, 302, 303, 304, 305, 306, 307];
        foreach ($expectedDeletedRecordsUidList as $uid) {
            $this->assertFalse($this->getRecordDatabaseRowByUid('sys_log', $uid));
        }
    }

    #[Test]
    public function periodsCanBeSetViaArgumentsHigh(): void
    {
        $retentionPeriod = self::DEFAULT_RETENTION_PERIOD - 6;
        $cutoffPeriod = self::DEFAULT_RETENTION_PERIOD - 3;

        $this->setUpMultiPeriodMockLogEntriesWithTstamp(
            time() - ($retentionPeriod - 1) * 86400,
            time() - ($retentionPeriod + 1) * 86400,
            time() - ($cutoffPeriod + 1) * 86400
        );

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . " --retentionPeriod {$retentionPeriod} --cutoffPeriod {$cutoffPeriod}");
        self::assertEquals(0, $result['status']);

        $expectedRemainingRecordsUidList = [101, 102, 103, 104, 105, 106, 107, 207];
        foreach ($expectedRemainingRecordsUidList as $uid) {
            $this->assertIsArray($this->getRecordDatabaseRowByUid('sys_log', $uid));
        }

        $expectedDeletedRecordsUidList = [201, 202, 203, 204, 205, 206, 301, 302, 303, 304, 305, 306, 307];
        foreach ($expectedDeletedRecordsUidList as $uid) {
            $this->assertFalse($this->getRecordDatabaseRowByUid('sys_log', $uid));
        }
    }

    /**
     * We cannot hard code a tstamp value into our mock CSV file directly, since the command works relative to the
     * current time.
     *
     * This function imports a 'simple' mock file and gives all entries the same tstamp value. These mock records are
     * intended to test a single period.
     */
    protected function setUpSinglePeriodMockLogEntriesWithTstamp(int $tstamp): void
    {
        $this->importCSVDataSet(self::MOCK_FILE_PATH . '/sys_log-mock-entries-without-tstamp--single-period.csv');
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_log');
        $queryBuilder->update('sys_log')
            ->set('tstamp', $tstamp, true, Connection::PARAM_INT)
            ->executeStatement();
    }

    /**
     * We cannot hard code a tstamp value into our mock CSV file directly, since the command works relative to the
     * current time.
     *
     * This function imports a 'more complex' mock file, where multiple periods exist simultaneously with different
     * tstamp values.
     *
     * @param int $tstampPeriod1 Set `tstamp` for values within UID 100 range
     * @param int $tstampPeriod2 Set `tstamp` for values within UID 200 range
     * @param int $tstampPeriod3 Set `tstamp` for values within UID 300 range
     */
    protected function setUpMultiPeriodMockLogEntriesWithTstamp(
        int $tstampPeriod1,
        int $tstampPeriod2,
        int $tstampPeriod3
    ): void {
        $this->importCSVDataSet(self::MOCK_FILE_PATH . '/sys_log-mock-entries-without-tstamp--multiple-periods.csv');

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_log');
        $queryBuilder->update('sys_log')
            ->set('tstamp', $tstampPeriod1, true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->gte('uid', $queryBuilder->createNamedParameter(100, Connection::PARAM_INT))
            )
            ->executeStatement();

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_log');
        $queryBuilder->update('sys_log')
            ->set('tstamp', $tstampPeriod2, true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->gte('uid', $queryBuilder->createNamedParameter(200, Connection::PARAM_INT))
            )
            ->executeStatement();

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_log');
        $queryBuilder->update('sys_log')
            ->set('tstamp', $tstampPeriod3, true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->gte('uid', $queryBuilder->createNamedParameter(300, Connection::PARAM_INT))
            )
            ->executeStatement();
    }
}
