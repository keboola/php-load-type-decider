<?php

declare(strict_types=1);

namespace Keboola\Package\LoadTypeDecider;

use Keboola\Package\LoadTypeDecider\Exception\InvalidInputException;

final class LoadTypeDecider
{
    public const WORKSPACE_TYPE_SNOWFLAKE = 'snowflake';
    public const WORKSPACE_TYPE_BIGQUERY = 'bigquery';

    private const CLONE_SUPPORTED_WORKSPACE_TYPES = [
        self::WORKSPACE_TYPE_SNOWFLAKE,
        self::WORKSPACE_TYPE_BIGQUERY,
    ];

    /**
     * Decide which load type the server uses for a workspace table load.
     *
     * This is the single source of truth for the load-type decision across the
     * platform: it computes both the `preferred` type (used when the caller does
     * not pin one) and the full `possible` set (for UIs offering a manual
     * override). It never reads features from a database — every feature gate is
     * supplied via {@see LoadTypeDeciderFeatures}.
     *
     * Preference rules:
     *   - BigQuery with `bigqueryDefaultImView` on, for a full load (no
     *     filtering options) where VIEW is viable -> VIEW. Lets a project default
     *     IM loads to a live VIEW instead of a snapshot.
     *   - otherwise CLONE when viable (zero-copy, identical row semantics).
     *   - otherwise COPY (always available).
     *
     * VIEW is only auto-preferred for BigQuery behind the feature, and only for a
     * full load: a VIEW reflects the whole source table and cannot honor
     * filters / columns / time-windows, so auto-promoting a filtered request to
     * VIEW would silently drop the filter. Such requests fall through to COPY.
     * For every other backend VIEW stays a manual, opt-in choice and never
     * becomes the preference even when it is in `possible`.
     *
     * @param array<string, mixed> $tableInfo     Storage API table detail (see {@see canClone()}).
     * @param string               $workspaceType Target workspace backend.
     * @param array<string, mixed> $exportOptions Options about to be passed to the workspace load.
     */
    public static function decide(
        array $tableInfo,
        string $workspaceType,
        array $exportOptions,
        LoadTypeDeciderFeatures $features,
    ): LoadTypeDecision {
        $canClone = self::canClone($tableInfo, $workspaceType, $exportOptions);

        $canView = self::canUseView($tableInfo, $workspaceType);
        // Snowflake VIEW loads are gated behind the project read-only-storage
        // input-mapping feature; without it VIEW is not offered.
        if ($workspaceType === self::WORKSPACE_TYPE_SNOWFLAKE && !$features->snowflakeReadOnlyStorage) {
            $canView = false;
        }

        $possible = [LoadType::COPY];
        if ($canClone) {
            $possible[] = LoadType::CLONE;
        }
        if ($canView) {
            $possible[] = LoadType::VIEW;
        }

        // A VIEW reflects the whole source table, so it must not be auto-preferred
        // for a request carrying filtering options it would silently drop. The
        // only option a full BigQuery load legitimately carries is `overwrite`.
        $isFullLoad = array_diff(array_keys($exportOptions), ['overwrite']) === [];

        if ($workspaceType === self::WORKSPACE_TYPE_BIGQUERY
            && $features->bigqueryDefaultImView
            && $canView
            && $isFullLoad
        ) {
            $preferred = LoadType::VIEW;
        } elseif ($canClone) {
            $preferred = LoadType::CLONE;
        } else {
            $preferred = LoadType::COPY;
        }

        return new LoadTypeDecision($preferred, $possible);
    }

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
        $isWorkspaceBigQuery = $workspaceType === self::WORKSPACE_TYPE_BIGQUERY;
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
                    implode(', ', array_diff(array_keys($exportOptions), ['overwrite'])),
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
        // `dropTimestampColumn` is honored by Snowflake's CLONE job
        // (WorkspaceLoadCloneJob, Snowflake-only) — strip it so it does not block
        // CLONE there. On BigQuery the CLONE path never consumes the option, so
        // leaving it in the bag (below) correctly disqualifies CLONE and the load
        // falls back to COPY (which has no `_timestamp`).
        if ($workspaceType === self::WORKSPACE_TYPE_SNOWFLAKE) {
            unset($exportOptions['dropTimestampColumn']);
        }

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

        // BigQuery refuses `CREATE TABLE ... CLONE` against an Analytics-Hub-linked
        // dataset (`Cannot clone tables from a linked dataset.`) regardless of the
        // publisher's restrictedExportPolicy. Confirmed against BQ on 2026-05-20.
        // Reject here so the rule is centralized for every IM-load / dry-run caller.
        if ($workspaceType === self::WORKSPACE_TYPE_BIGQUERY
            && array_key_exists('isLinked', $tableInfo['bucket'])
            && $tableInfo['bucket']['isLinked'] === true
        ) {
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
        if ($isBackendMatch && $workspaceType === self::WORKSPACE_TYPE_BIGQUERY) {
            return true;
        }

        if ($isBackendMatch
            && $backend === self::WORKSPACE_TYPE_SNOWFLAKE
            && array_key_exists('hasExternalSchema', $tableInfo['bucket'])
            && $tableInfo['bucket']['hasExternalSchema'] === true
        ) {
            // allow view for buckets with external schema
            return true;
        }

        return false;
    }
}
