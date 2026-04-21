<?php

declare(strict_types=1);

namespace Keboola\Package\LoadTypeDecider\Tests;

use Generator;
use Keboola\Package\LoadTypeDecider\Exception\InvalidInputException;
use Keboola\Package\LoadTypeDecider\LoadTypeDecider;
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

        yield 'BigQuery Table Overwrite' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
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
            'expected' => 'Table "foo.bar" is an alias, which is not supported when loading Bigquery tables.',
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
            'expected' => 'Option "seconds" is not supported when loading Bigquery table "foo.bar".',
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
            'expected' => 'Option "rows" is not supported when loading Bigquery table "foo.bar".',
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
            'expected' => 'Option "whereOperator" is not supported when loading Bigquery table "foo.bar".',
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
            'expected' => 'Option "whereColumn" is not supported when loading Bigquery table "foo.bar".',
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
            'expected' => 'Option "whereValues" is not supported when loading Bigquery table "foo.bar".',
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
            'expected' => 'Option "columns" is not supported when loading Bigquery table "foo.bar".',
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
            'expected' => 'Workspace type "bigquery" does not match table backend type "snowflake" when loading Bigquery table "foo.bar".',
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
        yield 'BigQuery Table Alias' => [
            'tableInfo' => [
                'id' => 'foo.bar',
                'name' => 'bar',
                'bucket' => ['backend' => 'bigquery'],
                'isAlias' => false,
                'sourceTable' => ['project' => ['id' => '123']],
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
}
