<?php

declare(strict_types=1);

namespace Keboola\Package\LoadTypeDecider;

use Keboola\Package\LoadTypeDecider\Exception\InvalidInputException;

final class LoadTypeDecider
{
    private const CLONE_SUPPORTED_WORKSPACE_TYPES = ['snowflake', 'bigquery'];

    /**
     * Pre-flight validation for a workspace table load.
     *
     * Call this BEFORE {@see canClone()} / {@see canUseView()} to reject loads
     * that no load method (clone / view / copy) can satisfy. In the standard
     * input-mapping flow the order is:
     *
     *   checkViableLoadMethod()  -> throws if unsupported combo
     *   canClone()               -> prefer zero-copy CLONE when applicable
     *   canUseView()             -> otherwise prefer a VIEW when applicable
     *   (fallback)               -> COPY (always available)
     *
     * Today the method enforces only BigQuery-specific constraints; for every
     * other workspace type (snowflake / redshift / exasol / teradata) it is a
     * no-op because the COPY fallback can always handle the load.
     *
     * For a BigQuery workspace it throws when any of the following holds:
     *
     *   1. Backend mismatch — the bucket's backend is not 'bigquery'. Cross-
     *      backend loads are not supported for BigQuery workspaces at all
     *      (there is no COPY staging path to bridge Snowflake -> BigQuery,
     *      for example).
     *   2. Unsupported export options — BigQuery workspace loads only honor
     *      the 'overwrite' option. Any filtering / slicing option (columns,
     *      rows, changed_since, seconds, whereColumn, whereOperator,
     *      whereValues, ...) is rejected here rather than silently ignored
     *      so the caller sees a clear error.
     *   3. Alias in the current project — BigQuery does not support loading
     *      from a same-project alias. Note: `$tableInfo['isAlias']` is true
     *      for BOTH local aliases AND tables shared from a different project;
     *      the latter IS supported, so we check `sourceTable.project.id
     *      !== $currentProjectId` to distinguish them. See
     *      https://keboolaglobal.slack.com/archives/C055HSMKX51/p1699434828910109
     *
     * @param array<string, mixed> $tableInfo       Storage API table detail
     *                                              (must carry `id`, `isAlias`,
     *                                              `bucket.backend`, and
     *                                              `sourceTable.project.id`
     *                                              when `isAlias` is true).
     * @param string               $workspaceType   Target workspace backend
     *                                              (e.g. 'bigquery',
     *                                              'snowflake', ...).
     * @param array<string, mixed> $exportOptions   Options about to be passed
     *                                              to the workspace load.
     * @param string               $currentProjectId Project the caller is
     *                                              loading into; used to tell
     *                                              local aliases from
     *                                              cross-project shared tables.
     *
     * @throws InvalidInputException when the requested BigQuery load cannot
     *                               be served by any load method.
     */
    public static function checkViableLoadMethod(
        array $tableInfo,
        string $workspaceType,
        array $exportOptions,
        string $currentProjectId,
    ): void {
        $isWorkspaceBigQuery = $workspaceType === 'bigquery';
        $isBackendMismatch = $tableInfo['bucket']['backend'] !== $workspaceType;
        $hasOtherThanOverwriteOptions = $exportOptions && array_keys($exportOptions) !== ['overwrite'];
        $isAliasInCurrentProject = $tableInfo['isAlias'] &&
            ((string) $tableInfo['sourceTable']['project']['id'] === $currentProjectId);

        if ($isWorkspaceBigQuery) {
            if ($isBackendMismatch) {
                throw new InvalidInputException(sprintf(
                    'Workspace type "%s" does not match table backend type "%s" when loading BigQuery table "%s".',
                    $workspaceType,
                    $tableInfo['bucket']['backend'],
                    $tableInfo['id'],
                ));
            }

            if ($hasOtherThanOverwriteOptions) {
                throw new InvalidInputException(sprintf(
                    'Option "%s" is not supported when loading BigQuery table "%s".',
                    implode(', ', array_keys($exportOptions)),
                    $tableInfo['id'],
                ));
            }

            /* isAlias means that the table is EITHER an alias OR a table shared from a different project.
                Surprisingly, the table shared from different project IS supported, but the alias is not.
                https://keboolaglobal.slack.com/archives/C055HSMKX51/p1699434828910109
            */
            if ($isAliasInCurrentProject) {
                throw new InvalidInputException(sprintf(
                    'Table "%s" is an alias, which is not supported when loading BigQuery tables.',
                    $tableInfo['id'],
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $tableInfo
     * @param array<string, mixed> $exportOptions
     */
    public static function canClone(array $tableInfo, string $workspaceType, array $exportOptions): bool
    {
        if ($tableInfo['isAlias'] && (empty($tableInfo['aliasColumnsAutoSync']) || !empty($tableInfo['aliasFilter']))) {
            return false;
        }

        if (array_keys($exportOptions) !== ['overwrite'] ||
            ($tableInfo['bucket']['backend'] !== $workspaceType) ||
            !in_array($workspaceType, self::CLONE_SUPPORTED_WORKSPACE_TYPES, true)
        ) {
            return false;
        }

        if (array_key_exists('hasExternalSchema', $tableInfo['bucket'])
            && $tableInfo['bucket']['hasExternalSchema'] === true
        ) {
            // clone is not allowed for buckets with external schema
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $tableInfo
     */
    public static function canUseView(
        array $tableInfo,
        string $workspaceType,
    ): bool {
        $backend = $tableInfo['bucket']['backend'];
        $isBackendMatch = $backend === $workspaceType;
        if ($isBackendMatch && $workspaceType === 'bigquery') {
            return true;
        }

        if ($isBackendMatch
            && $backend === 'snowflake'
            && array_key_exists('hasExternalSchema', $tableInfo['bucket'])
            && $tableInfo['bucket']['hasExternalSchema'] === true
        ) {
            // allow view for buckets with external schema
            return true;
        }

        return false;
    }
}
