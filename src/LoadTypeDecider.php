<?php

declare(strict_types=1);

namespace Keboola\LoadTypeDecider;

use Keboola\LoadTypeDecider\Exception\InvalidInputException;

/**
 * Storage API table detail, narrowed to the fields the decider reads. Unsealed
 * (`...`) so callers may pass the full table payload; only these keys are typed.
 *
 * @phpstan-type StorageTableInfo array{
 *     id: string,
 *     isAlias: bool,
 *     aliasColumnsAutoSync?: mixed,
 *     aliasFilter?: mixed,
 *     bucket: array{backend: string, hasExternalSchema?: bool, isLinked?: bool, ...},
 *     sourceTable?: array{project: array{id: int|string, ...}, ...},
 *     ...
 * }
 */
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
     * @param StorageTableInfo $tableInfo     Storage API table detail (see {@see canClone()}).
     * @param string               $workspaceType Target workspace backend.
     * @param array<string, mixed> $exportOptions Options about to be passed to the workspace load.
     */
    public static function decide(
        array $tableInfo,
        string $workspaceType,
        array $exportOptions,
        LoadTypeDeciderFeatures $features,
    ): LoadTypeDecision {
        // Normalize the well-known `overwrite` default so the library is a
        // self-contained source of truth: `canClone()` requires the option bag to
        // be exactly `['overwrite']`, while `$isFullLoad` below treats a bag with
        // no filtering options as full. Without this, a caller that passes an
        // empty bag (omitting `overwrite`) would be classified as a full load yet
        // get CLONE disqualified — an inconsistency. Idempotent when `overwrite`
        // is already present.
        if (!array_key_exists('overwrite', $exportOptions)) {
            $exportOptions['overwrite'] = false;
        }

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
     *      !== $currentProjectId` to distinguish them. When `isAlias` is true
     *      but `sourceTable.project.id` is missing the two cannot be told
     *      apart, so the method fails fast rather than allowing the load. See
     *      https://keboolaglobal.slack.com/archives/C055HSMKX51/p1699434828910109
     *
     * @param StorageTableInfo $tableInfo       Storage API table detail
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
        // `dropTimestampColumn` is CLONE-compatible on both backends (see canClone(), which strips it
        // before its strict-keys check). Strip it here too so this preflight stays consistent: a caller
        // following the documented order (checkViableLoadMethod() -> canClone()) must not have a BigQuery
        // clone load carrying `dropTimestampColumn` rejected here as an unsupported filtering option.
        unset($exportOptions['dropTimestampColumn']);
        $hasOtherThanOverwriteOptions = $exportOptions && array_keys($exportOptions) !== ['overwrite'];
        $tableId = $tableInfo['id'];

        if (!$isWorkspaceBigQuery) {
            return;
        }

        if ($isBackendMismatch) {
            throw new InvalidInputException(sprintf(
                'Workspace type "%s" does not match table backend type "%s" when loading BigQuery table "%s".',
                $workspaceType,
                $tableInfo['bucket']['backend'],
                $tableId,
            ));
        }

        if ($hasOtherThanOverwriteOptions) {
            throw new InvalidInputException(sprintf(
                'Option "%s" is not supported when loading BigQuery table "%s".',
                implode(', ', array_diff(array_keys($exportOptions), ['overwrite'])),
                $tableId,
            ));
        }

        /* isAlias means that the table is EITHER an alias OR a table shared from a different project.
            Surprisingly, the table shared from different project IS supported, but the alias is not.
            We tell them apart via sourceTable.project.id; the contract requires it when isAlias is true,
            so fail fast when it is missing rather than silently allowing a possibly-unsupported alias.
            https://keboolaglobal.slack.com/archives/C055HSMKX51/p1699434828910109
        */
        if (!$tableInfo['isAlias']) {
            return;
        }

        if (!isset($tableInfo['sourceTable']['project']['id'])) {
            throw new InvalidInputException(sprintf(
                'Table "%s" is an alias but does not carry "sourceTable.project.id", so a local alias '
                . 'cannot be distinguished from a cross-project shared table for a BigQuery load.',
                $tableId,
            ));
        }

        if ((string) $tableInfo['sourceTable']['project']['id'] === $currentProjectId) {
            throw new InvalidInputException(sprintf(
                'Table "%s" is an alias, which is not supported when loading BigQuery tables.',
                $tableId,
            ));
        }
    }

    /**
     * @param StorageTableInfo $tableInfo
     * @param array<string, mixed> $exportOptions
     */
    public static function canClone(array $tableInfo, string $workspaceType, array $exportOptions): bool
    {
        // `dropTimestampColumn` is honored by the CLONE path on both supported
        // backends — Snowflake via WorkspaceLoadCloneJob, BigQuery via
        // LoadTableWithDriver dropping `_timestamp` with a follow-up ALTER TABLE
        // after the clone. Strip it so the leftover option does not disqualify
        // CLONE in the strict-keys check below.
        unset($exportOptions['dropTimestampColumn']);

        // An empty `columns`/`column_types` does not project the table — it means "load
        // every column", which a CLONE (a whole-table copy) does — so it must not disqualify
        // CLONE in the strict-keys check below. The input-mapping-load runner forwards the
        // component config verbatim, so these keys arrive even when nothing is projected. A
        // table always has columns, so `null`, `[]`, and an absent key are equivalent and are
        // all stripped. Any NON-empty value is a real projection and is left in place to
        // (correctly) block CLONE — we do not compare it against the table's column set: the
        // runner never sends a full-column list, so that case is not worth the complexity.
        foreach (['columns', 'column_types'] as $columnsKey) {
            if (array_key_exists($columnsKey, $exportOptions)
                && ($exportOptions[$columnsKey] === null || $exportOptions[$columnsKey] === [])
            ) {
                unset($exportOptions[$columnsKey]);
            }
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
     * @param StorageTableInfo $tableInfo
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
