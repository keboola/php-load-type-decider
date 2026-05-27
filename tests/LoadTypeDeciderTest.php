<?php

declare(strict_types=1);

namespace Keboola\Package\LoadTypeDecider\Tests;

use Generator;
use Keboola\Package\LoadTypeDecider\Exception\InvalidInputException;
use Keboola\Package\LoadTypeDecider\LoadType;
use Keboola\Package\LoadTypeDecider\LoadTypeDecider;
use Keboola\Package\LoadTypeDecider\LoadTypeDeciderFeatures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoadTypeDecider::class)]
final class LoadTypeDeciderTest extends TestCase
{
    /**
     * @param array<string, mixed> $tableInfo
     * @param array<string, mixed> $exportOptions
     */
    #[DataProvider('decideCanCloneProvider')]
    public function testDecideCanClone(
        array $tableInfo,
        string $workspaceType,
        array $exportOptions,
        bool $expected,
    ): void {
        self::assertEquals($expected, LoadTypeDecider::canClone($tableInfo, $workspaceType, $exportOptions));
    }

    /**
     * @return Generator<string, array{array<string, mixed>, string, array<string, mixed>, bool}>
     */
    public static function decideCanCloneProvider(): Generator
    {
        yield 'Different Backends' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'redshift'],
                'isAlias' => false,
            ],
            'snowflake',
            [],
            false,
        ];
        yield 'Different Backends 2' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake'],
                'isAlias' => false,
            ],
            'redshift',
            [],
            false,
        ];
        yield 'Filtered' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake'],
                'isAlias' => false,
            ],
            'snowflake',
            [
                'changed_since' => '-2 days',
            ],
            false,
        ];
        yield 'cloneable snowflake' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake'],
                'isAlias' => false,
            ],
            'snowflake',
            ['overwrite' => false],
            true,
        ];
        yield 'snowflake with empty export options' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake'],
                'isAlias' => false,
            ],
            'snowflake',
            [],
            false,
        ];
        yield 'bigquery with empty export options' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'bigquery',
            [],
            false,
        ];
        yield 'redshift' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'redshift'],
                'isAlias' => false,
            ],
            'redshift',
            [],
            false,
        ];
        yield 'cloneable bigquery' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'bigquery',
            ['overwrite' => false],
            true,
        ];
        yield 'bigquery table to snowflake workspace' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'snowflake',
            ['overwrite' => false],
            false,
        ];
        yield 'bigquery alias table' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => true,
                'aliasColumnsAutoSync' => true,
            ],
            'bigquery',
            ['overwrite' => false],
            true,
        ];
        yield 'bigquery alias filtered columns' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => true,
                'aliasColumnsAutoSync' => false,
            ],
            'bigquery',
            ['overwrite' => false],
            false,
        ];
        yield 'bigquery alias filtered rows' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => true,
                'aliasColumnsAutoSync' => true,
                'aliasFilter' => [
                    'column' => 'PassengerId',
                    'operator' => 'eq',
                    'values' => ['12'],
                ],
            ],
            'bigquery',
            ['overwrite' => false],
            false,
        ];
        yield 'bigquery external bucket' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery', 'hasExternalSchema' => true],
                'isAlias' => false,
            ],
            'bigquery',
            ['overwrite' => false],
            false,
        ];
        // BigQuery refuses `CREATE TABLE ... CLONE` against an Analytics-Hub-linked
        // dataset (`Cannot clone tables from a linked dataset.`) regardless of the
        // publisher's restrictedExportPolicy. Validated against BQ on 2026-05-20.
        yield 'bigquery linked bucket: CLONE rejected' => [
            [
                'id' => 'in.c-linked.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery', 'isLinked' => true],
                'isAlias' => false,
            ],
            'bigquery',
            ['overwrite' => false],
            false,
        ];
        yield 'bigquery non-linked bucket: CLONE allowed' => [
            [
                'id' => 'in.c-main.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery', 'isLinked' => false],
                'isAlias' => false,
            ],
            'bigquery',
            ['overwrite' => false],
            true,
        ];
        // The linked-bucket rule is BigQuery-specific (Snowflake's data-sharing
        // model does not have the Analytics-Hub egress restriction). Snowflake
        // tables in linked buckets should remain CLONE-eligible.
        yield 'snowflake linked bucket: CLONE allowed' => [
            [
                'id' => 'in.c-linked.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake', 'isLinked' => true],
                'isAlias' => false,
            ],
            'snowflake',
            ['overwrite' => false],
            true,
        ];
        yield 'bigquery filtered' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'bigquery',
            [
                'changed_since' => '-2 days',
            ],
            false,
        ];
        yield 'alias table' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake'],
                'isAlias' => true,
                'aliasColumnsAutoSync' => true,
            ],
            'snowflake',
            ['overwrite' => false],
            true,
        ];
        yield 'alias filtered columns' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake'],
                'isAlias' => true,
                'aliasColumnsAutoSync' => false,
            ],
            'snowflake',
            ['overwrite' => false],
            false,
        ];
        yield 'alias filtered rows' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake'],
                'isAlias' => true,
                'aliasColumnsAutoSync' => true,
                'aliasFilter' => [
                    'column' => 'PassengerId',
                    'operator' => 'eq',
                    'values' => ['12'],
                ],
            ],
            'snowflake',
            ['overwrite' => false],
            false,
        ];
        yield 'snowflake external bucket' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake', 'hasExternalSchema' => true],
                'isAlias' => false,
            ],
            'snowflake',
            ['overwrite' => false],
            false,
        ];
        // `dropTimestampColumn` is consumed by Snowflake's CLONE job, so it is
        // stripped here and CLONE stays viable.
        yield 'snowflake dropTimestampColumn: CLONE allowed' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake'],
                'isAlias' => false,
            ],
            'snowflake',
            ['overwrite' => false, 'dropTimestampColumn' => true],
            true,
        ];
        // BigQuery's CLONE path never consumes `dropTimestampColumn`, so it is
        // NOT stripped and the leftover option disqualifies CLONE.
        yield 'bigquery dropTimestampColumn: CLONE blocked' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'bigquery',
            ['overwrite' => false, 'dropTimestampColumn' => true],
            false,
        ];
    }

    /**
     * @param array<string, mixed> $tableInfo
     */
    #[DataProvider('decideCanUseViewProvider')]
    public function testDecideCanUseView(
        array $tableInfo,
        string $workspaceType,
        bool $expected,
    ): void {
        self::assertEquals($expected, LoadTypeDecider::canUseView($tableInfo, $workspaceType));
    }

    /**
     * @return Generator<string, array{tableInfo: array<string, mixed>, workspaceType: string, expected: bool}>
     */
    public static function decideCanUseViewProvider(): Generator
    {
        yield 'BigQuery Table' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'expected' => true,
        ];

        yield 'BigQuery Shared Table' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => true,
                'sourceTable' => ['project' => ['id' => '321']],
            ],
            'workspaceType' => 'bigquery',
            'expected' => true,
        ];

        yield 'Table Overwrite Different Backend' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'snowflake',
            'expected' => false,
        ];

        yield 'Snowflake external bucket' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake', 'hasExternalSchema' => true],
                'isAlias' => false,
            ],
            'workspaceType' => 'snowflake',
            'expected' => true,
        ];

        yield 'Snowflake normal bucket' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake', 'hasExternalSchema' => false],
                'isAlias' => false,
            ],
            'workspaceType' => 'snowflake',
            'expected' => false,
        ];
    }

    /**
     * @param array<string, mixed> $tableInfo
     * @param array<string, mixed> $exportOptions
     */
    #[DataProvider('checkViableLoadMethodExceptionProvider')]
    public function testCheckViableLoadMethodException(
        array $tableInfo,
        string $workspaceType,
        array $exportOptions,
        string $expected,
    ): void {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage($expected);
        LoadTypeDecider::checkViableLoadMethod($tableInfo, $workspaceType, $exportOptions, '123');
    }

    /**
     * @return Generator<string, array{
     *     tableInfo: array<string, mixed>,
     *     workspaceType: string,
     *     exportOptions: array<string, mixed>,
     *     expected: string,
     * }>
     */
    public static function checkViableLoadMethodExceptionProvider(): Generator
    {
        yield 'BigQuery Table Alias' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => true,
                'sourceTable' => ['project' => ['id' => '123']],
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [],
            'expected' => 'Table "foo.bar" is an alias, which is not supported when loading BigQuery tables.',
        ];

        yield 'Filtered BigQuery Table' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [
                'seconds' => 5,
            ],
            'expected' => 'Option "seconds" is not supported when loading BigQuery table "foo.bar".',
        ];

        yield 'BigQuery Table with limit' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [
                'rows' => 1,
            ],
            'expected' => 'Option "rows" is not supported when loading BigQuery table "foo.bar".',
        ];

        yield 'BigQuery Table with whereOperator' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [
                'whereOperator' => 'and',
            ],
            'expected' => 'Option "whereOperator" is not supported when loading BigQuery table "foo.bar".',
        ];

        yield 'BigQuery Table with whereColumn' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [
                'whereColumn' => 'name',
            ],
            'expected' => 'Option "whereColumn" is not supported when loading BigQuery table "foo.bar".',
        ];

        yield 'BigQuery Table with whereValues' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [
                'whereValues' => ['foo'],
            ],
            'expected' => 'Option "whereValues" is not supported when loading BigQuery table "foo.bar".',
        ];

        yield 'BigQuery Table with columns' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [
                'columns' => [],
            ],
            'expected' => 'Option "columns" is not supported when loading BigQuery table "foo.bar".',
        ];

        yield 'BigQuery Table with overwrite plus unsupported option' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [
                'overwrite' => true,
                'columns' => [],
            ],
            // the message MUST NOT list 'overwrite' since overwrite IS supported
            'expected' => 'Option "columns" is not supported when loading BigQuery table "foo.bar".',
        ];

        yield 'Snowflake Table to bigquery workspace' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [
                'columns' => [],
            ],
            'expected' => 'Workspace type "bigquery" does not match table backend type "snowflake" when loading BigQuery table "foo.bar".',
        ];
    }

    /**
     * @param array<string, mixed> $tableInfo
     * @param array<string, mixed> $exportOptions
     */
    #[DataProvider('checkViableLoadMethodPassProvider')]
    public function testCheckViableLoadMethodPass(
        array $tableInfo,
        string $workspaceType,
        array $exportOptions,
    ): void {
        $this->expectNotToPerformAssertions();
        LoadTypeDecider::checkViableLoadMethod($tableInfo, $workspaceType, $exportOptions, '123');
    }

    /**
     * @return Generator<string, array{
     *     tableInfo: array<string, mixed>,
     *     workspaceType: string,
     *     exportOptions: array<string, mixed>,
     * }>
     */
    public static function checkViableLoadMethodPassProvider(): Generator
    {
        yield 'BigQuery cross-project shared table (isAlias=true, different project)' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => true,
                'sourceTable' => ['project' => ['id' => '321']],
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [],
        ];

        yield 'Filtered BigQuery Table' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [
                'overwrite' => true,
            ],
        ];

        yield 'Snowflake workspace' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'snowflake',
            'exportOptions' => [
                'columns' => [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $tableInfo
     * @param array<string, mixed> $exportOptions
     * @param list<LoadType>       $expectedPossible
     */
    #[DataProvider('decideProvider')]
    public function testDecide(
        array $tableInfo,
        string $workspaceType,
        array $exportOptions,
        LoadTypeDeciderFeatures $features,
        LoadType $expectedPreferred,
        array $expectedPossible,
    ): void {
        $decision = LoadTypeDecider::decide($tableInfo, $workspaceType, $exportOptions, $features);

        self::assertSame($expectedPreferred, $decision->preferred);
        self::assertSame($expectedPossible, $decision->possible);
    }

    /**
     * @return Generator<string, array{
     *     tableInfo: array<string, mixed>,
     *     workspaceType: string,
     *     exportOptions: array<string, mixed>,
     *     features: LoadTypeDeciderFeatures,
     *     expectedPreferred: LoadType,
     *     expectedPossible: list<LoadType>,
     * }>
     */
    public static function decideProvider(): Generator
    {
        $bigqueryTable = [
            'id' => 'in.c-main.bar',
            'name' => 'bar',
            'bucket' => ['backend' => 'bigquery'],
            'isAlias' => false,
        ];
        $snowflakeTable = [
            'id' => 'in.c-main.bar',
            'name' => 'bar',
            'bucket' => ['backend' => 'snowflake'],
            'isAlias' => false,
        ];

        yield 'bigquery, default-im-view OFF: CLONE preferred, VIEW still possible' => [
            'tableInfo' => $bigqueryTable,
            'workspaceType' => 'bigquery',
            'exportOptions' => ['overwrite' => false],
            'features' => new LoadTypeDeciderFeatures(bigqueryDefaultImView: false, snowflakeReadOnlyStorage: false),
            'expectedPreferred' => LoadType::CLONE,
            'expectedPossible' => [LoadType::COPY, LoadType::CLONE, LoadType::VIEW],
        ];

        yield 'bigquery, default-im-view ON: VIEW preferred' => [
            'tableInfo' => $bigqueryTable,
            'workspaceType' => 'bigquery',
            'exportOptions' => ['overwrite' => false],
            'features' => new LoadTypeDeciderFeatures(bigqueryDefaultImView: true, snowflakeReadOnlyStorage: false),
            'expectedPreferred' => LoadType::VIEW,
            'expectedPossible' => [LoadType::COPY, LoadType::CLONE, LoadType::VIEW],
        ];

        // VIEW is viable for BQ even when CLONE is not, so the default-view flag
        // still yields VIEW (no CLONE in possible).
        yield 'bigquery external bucket, default-im-view ON: VIEW preferred, CLONE blocked' => [
            'tableInfo' => [
                'id' => 'in.c-ext.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery', 'hasExternalSchema' => true],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => ['overwrite' => false],
            'features' => new LoadTypeDeciderFeatures(bigqueryDefaultImView: true, snowflakeReadOnlyStorage: false),
            'expectedPreferred' => LoadType::VIEW,
            'expectedPossible' => [LoadType::COPY, LoadType::VIEW],
        ];

        // Filtered export disqualifies CLONE; a VIEW would silently drop the
        // filter, so even with the flag ON the safe default is COPY. VIEW stays in
        // `possible` (a UI may still offer it as an explicit, filter-dropping choice).
        yield 'bigquery filtered, default-im-view ON: COPY preferred (filter would be dropped by VIEW)' => [
            'tableInfo' => $bigqueryTable,
            'workspaceType' => 'bigquery',
            'exportOptions' => ['changed_since' => '-1 day'],
            'features' => new LoadTypeDeciderFeatures(bigqueryDefaultImView: true, snowflakeReadOnlyStorage: false),
            'expectedPreferred' => LoadType::COPY,
            'expectedPossible' => [LoadType::COPY, LoadType::VIEW],
        ];

        // Regular (non-external) Snowflake table: CLONE is viable, VIEW is not
        // (Snowflake VIEW loads require an external-schema bucket).
        yield 'snowflake regular table: CLONE preferred' => [
            'tableInfo' => $snowflakeTable,
            'workspaceType' => 'snowflake',
            'exportOptions' => ['overwrite' => false],
            'features' => new LoadTypeDeciderFeatures(bigqueryDefaultImView: false, snowflakeReadOnlyStorage: true),
            'expectedPreferred' => LoadType::CLONE,
            'expectedPossible' => [LoadType::COPY, LoadType::CLONE],
        ];

        // External-schema Snowflake bucket: CLONE blocked, VIEW viable when the
        // read-only-storage feature is on. VIEW is never the Snowflake default,
        // so the preference is COPY.
        yield 'snowflake external bucket, RO-storage ON: VIEW possible, COPY preferred' => [
            'tableInfo' => [
                'id' => 'in.c-ext.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake', 'hasExternalSchema' => true],
                'isAlias' => false,
            ],
            'workspaceType' => 'snowflake',
            'exportOptions' => ['overwrite' => false],
            'features' => new LoadTypeDeciderFeatures(bigqueryDefaultImView: false, snowflakeReadOnlyStorage: true),
            'expectedPreferred' => LoadType::COPY,
            'expectedPossible' => [LoadType::COPY, LoadType::VIEW],
        ];

        yield 'snowflake external bucket, RO-storage OFF: VIEW not offered, only COPY' => [
            'tableInfo' => [
                'id' => 'in.c-ext.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake', 'hasExternalSchema' => true],
                'isAlias' => false,
            ],
            'workspaceType' => 'snowflake',
            'exportOptions' => ['overwrite' => false],
            'features' => new LoadTypeDeciderFeatures(bigqueryDefaultImView: false, snowflakeReadOnlyStorage: false),
            'expectedPreferred' => LoadType::COPY,
            'expectedPossible' => [LoadType::COPY],
        ];

        // default-im-view is BigQuery-only: it never flips the Snowflake default.
        yield 'snowflake, default-im-view ON: still CLONE preferred (BQ-only flag)' => [
            'tableInfo' => $snowflakeTable,
            'workspaceType' => 'snowflake',
            'exportOptions' => ['overwrite' => false],
            'features' => new LoadTypeDeciderFeatures(bigqueryDefaultImView: true, snowflakeReadOnlyStorage: true),
            'expectedPreferred' => LoadType::CLONE,
            'expectedPossible' => [LoadType::COPY, LoadType::CLONE],
        ];
    }
}
