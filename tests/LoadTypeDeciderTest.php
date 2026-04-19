<?php

declare(strict_types=1);

namespace Keboola\Package\LoadTypeDecider\Tests;

use Generator;
use Keboola\Package\LoadTypeDecider\Exception\InvalidInputException;
use Keboola\Package\LoadTypeDecider\LoadTypeDecider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LoadTypeDeciderTest extends TestCase
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
                'bucket' => ['backend' => 'bigquery'],
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
            'bigquery',
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
        yield 'unsupported backend' => [
            [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'synapse'],
                'isAlias' => false,
            ],
            'synapse',
            ['overwrite' => false],
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
    }

    /**
     * @param array<string, mixed> $tableInfo
     * @param array<string, mixed> $exportOptions
     */
    #[DataProvider('decideCanUseViewProvider')]
    public function testDecideCanUseView(
        array $tableInfo,
        string $workspaceType,
        array $exportOptions,
        string $currentProjectId,
        bool $expected,
    ): void {
        self::assertEquals(
            $expected,
            LoadTypeDecider::canUseView($tableInfo, $workspaceType, $exportOptions, $currentProjectId),
        );
    }

    /**
     * @return Generator<string, array{
     *     tableInfo: array<string, mixed>,
     *     workspaceType: string,
     *     exportOptions: array<string, mixed>,
     *     currentProjectId: string,
     *     expected: bool,
     * }>
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
            'exportOptions' => [],
            'currentProjectId' => '123',
            'expected' => true,
        ];

        yield 'BigQuery Table with overwrite option' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => ['overwrite' => true],
            'currentProjectId' => '123',
            'expected' => true,
        ];

        yield 'BigQuery Shared Table from different project' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => true,
                'sourceTable' => ['project' => ['id' => '321']],
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [],
            'currentProjectId' => '123',
            'expected' => true,
        ];

        yield 'BigQuery Alias in current project' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => true,
                'sourceTable' => ['project' => ['id' => '123']],
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => [],
            'currentProjectId' => '123',
            'expected' => false,
        ];

        yield 'BigQuery Table with filter options' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => ['seconds' => 5],
            'currentProjectId' => '123',
            'expected' => false,
        ];

        yield 'BigQuery Table with rows limit' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => ['rows' => 1],
            'currentProjectId' => '123',
            'expected' => false,
        ];

        yield 'BigQuery Table with columns filter' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            'exportOptions' => ['columns' => ['col1']],
            'currentProjectId' => '123',
            'expected' => false,
        ];

        yield 'Table Different Backend' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'snowflake',
            'exportOptions' => [],
            'currentProjectId' => '123',
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
            'exportOptions' => [],
            'currentProjectId' => '123',
            'expected' => true,
        ];

        yield 'Snowflake external bucket with overwrite' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake', 'hasExternalSchema' => true],
                'isAlias' => false,
            ],
            'workspaceType' => 'snowflake',
            'exportOptions' => ['overwrite' => true],
            'currentProjectId' => '123',
            'expected' => true,
        ];

        yield 'Snowflake external bucket with filter options' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake', 'hasExternalSchema' => true],
                'isAlias' => false,
            ],
            'workspaceType' => 'snowflake',
            'exportOptions' => ['seconds' => 5],
            'currentProjectId' => '123',
            'expected' => false,
        ];

        yield 'Snowflake normal bucket' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake', 'hasExternalSchema' => false],
                'isAlias' => false,
            ],
            'workspaceType' => 'snowflake',
            'exportOptions' => [],
            'currentProjectId' => '123',
            'expected' => false,
        ];
    }

    /**
     * @param array<string, mixed> $tableInfo
     */
    #[DataProvider('checkViableBigQueryLoadMethodExceptionProvider')]
    public function testCheckViableBigQueryLoadMethodException(
        array $tableInfo,
        string $workspaceType,
        string $expected,
    ): void {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage($expected);
        LoadTypeDecider::checkViableBigQueryLoadMethod(
            $tableInfo,
            $workspaceType,
        );
    }

    /**
     * @return Generator<string, array{
     *     tableInfo: array<string, mixed>,
     *     workspaceType: string,
     *     expected: string,
     * }>
     */
    public static function checkViableBigQueryLoadMethodExceptionProvider(): Generator
    {
        yield 'Snowflake Table to bigquery workspace' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'snowflake'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            'expected' => 'Workspace type "bigquery" does not match table backend type "snowflake" when loading Bigquery table "foo.bar".',
        ];
        yield 'BigQuery Table to snowflake workspace' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'snowflake',
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            'expected' => 'Workspace type "snowflake" does not match table backend type "bigquery" when loading Bigquery table "foo.bar".',
        ];
    }

    /**
     * @param array<string, mixed> $tableInfo
     */
    #[DataProvider('checkViableBigQueryLoadMethodPassProvider')]
    public function testCheckViableBigQueryLoadMethodPass(
        array $tableInfo,
        string $workspaceType,
    ): void {
        $this->expectNotToPerformAssertions();
        LoadTypeDecider::checkViableBigQueryLoadMethod(
            $tableInfo,
            $workspaceType,
        );
    }

    /**
     * @return Generator<string, array{
     *     tableInfo: array<string, mixed>,
     *     workspaceType: string,
     * }>
     */
    public static function checkViableBigQueryLoadMethodPassProvider(): Generator
    {
        yield 'BigQuery Table' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
            ],
            'workspaceType' => 'bigquery',
        ];

        yield 'BigQuery Alias Table' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => true,
                'sourceTable' => ['project' => ['id' => '123']],
            ],
            'workspaceType' => 'bigquery',
        ];
    }
}
