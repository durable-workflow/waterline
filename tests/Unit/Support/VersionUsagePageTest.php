<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use Waterline\Support\VersionUsagePage;
use Waterline\Tests\Fixtures\VersionUsageFixture;

final class VersionUsagePageTest extends TestCase
{
    public function testClassificationRemainsExactlyAsRecordedForEveryUnknownReason(): void
    {
        foreach (['marker_absent', 'history_unavailable', 'history_invalid'] as $reason) {
            $page = VersionUsageFixture::page();
            $page['runs'][2]['unknown_reason'] = $reason;
            self::assertSame($page, VersionUsagePage::validate($page, VersionUsageFixture::query()));
            self::assertNull($page['runs'][2]['recorded_version']);
            self::assertArrayNotHasKey('safe_to_remove', $page);
        }
    }

    public function testAFilteredPageKeepsAllStateCountsAndTheOpaqueCursor(): void
    {
        $query = VersionUsageFixture::query();
        $query['state'] = 'unknown';
        $query['cursor'] = 'not-json. opaque ';
        $page = VersionUsageFixture::page($query);
        $page['runs'] = [$page['runs'][2]];
        $page['returned_count'] = 1;
        self::assertSame($page, VersionUsagePage::validate($page, $query));
        self::assertSame(3, $page['counts']['total']);
    }

    public function testAllRunsCanCloseBetweenPagesWithoutLosingTheUpperBound(): void
    {
        $page = VersionUsageFixture::page();
        $page['counts'] = ['total' => 0, 'legacy' => 0, 'current' => 0, 'unknown' => 0];
        $page['runs'] = [];
        $page['returned_count'] = 0;
        self::assertSame($page, VersionUsagePage::validate($page, VersionUsageFixture::query()));
    }

    public function testInstanceIdentifiersAreNotTruncatedToAnInventedConsumerLimit(): void
    {
        $page = VersionUsageFixture::page();
        $page['runs'][2]['instance_id'] = str_repeat('instance-', 40);
        self::assertSame($page, VersionUsagePage::validate($page, VersionUsageFixture::query()));
    }

    public function testAnEmptyContinuationCanStillHaveMatchesBeforeItsCursor(): void
    {
        $query = VersionUsageFixture::query();
        $query['cursor'] = 'opaque';
        $page = VersionUsageFixture::page($query);
        $page['runs'] = [];
        $page['returned_count'] = 0;
        self::assertSame($page, VersionUsagePage::validate($page, $query));
    }

    public function testAnInitialTerminalPageCannotOmitMatchingRuns(): void
    {
        $page = VersionUsageFixture::page();
        $page['runs'] = [];
        $page['returned_count'] = 0;
        $this->expectException(UnexpectedValueException::class);
        VersionUsagePage::validate($page, VersionUsageFixture::query());
    }

    public function testAContinuationIsFullAndItsCursorAdvances(): void
    {
        $query = VersionUsageFixture::query();
        $query['cursor'] = 'old';
        $query['limit'] = 1;
        $page = VersionUsageFixture::page($query);
        $page['runs'] = [$page['runs'][0]];
        $page['returned_count'] = 1;
        $page['has_more'] = true;
        $page['next_cursor'] = 'new';
        self::assertSame($page, VersionUsagePage::validate($page, $query));
        $page['next_cursor'] = 'old';
        $this->expectException(UnexpectedValueException::class);
        VersionUsagePage::validate($page, $query);
    }

    /** @param array<string, mixed> $page */
    #[DataProvider('invalidPages')]
    public function testInvalidAnswersAreErrorsInsteadOfEmptyOrCurrent(array $page): void
    {
        $this->expectException(UnexpectedValueException::class);
        VersionUsagePage::validate($page, VersionUsageFixture::query());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPages(): iterable
    {
        foreach ([
            'foreign namespace' => ['namespace', 'foreign'],
            'changed workflow case' => ['workflow_type', 'orders'],
            'changed change point' => ['change_id', 'carrier-api '],
            'changed minimum' => ['min_version', 2],
            'unknown schema' => ['schema_version', 2],
            'boolean count' => ['counts.unknown', true],
            'unreconciled count' => ['counts.total', 0],
            'unknown is not current' => ['runs.2.recorded_version', 1],
            'unrecognised unknown' => ['runs.2.unknown_reason', 'deployed_current'],
            'missing marker not a known decision' => ['runs.2.state', 'current'],
            'legacy threshold' => ['runs.0.recorded_version', 1],
            'signed range' => ['runs.0.recorded_version', -2147483649],
            'closed run' => ['runs.0.status', 'completed'],
            'duplicate run' => ['runs.1.run_id', 'run-1'],
            'upper bound' => ['through_run_id', 'run-0'],
            'invented removal authority' => ['safe_to_remove', true],
            'cursor without continuation' => ['next_cursor', 'opaque'],
            'bad returned count' => ['returned_count', 0],
        ] as $label => [$path, $value]) {
            $page = VersionUsageFixture::page();
            $target = &$page;
            $parts = explode('.', $path);
            $last = array_pop($parts);
            foreach ($parts as $part) {
                $target = &$target[$part];
            }
            $target[$last] = $value;
            unset($target);
            yield $label => [$page];
        }
    }
}
