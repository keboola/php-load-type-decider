<?php

declare(strict_types=1);

namespace Keboola\Package\LoadTypeDecider;

use Keboola\Package\LoadTypeDecider\Exception\InvalidInputException;

class LoadTypeDecider
{
    private const CLONE_SUPPORTED_BACKENDS = ['snowflake', 'bigquery'];

    /**
     * @param array<string, mixed> $tableInfo
     */
    public static function checkViableBigQueryLoadMethod(
        array $tableInfo,
        string $workspaceType,
    ): void {
        if ($workspaceType !== 'bigquery' || $tableInfo['bucket']['backend'] !== 'bigquery') {
            throw new InvalidInputException(sprintf(
                'Workspace type "%s" does not match table backend type "%s" when loading BigQuery table "%s".',
                $workspaceType,
                $tableInfo['bucket']['backend'],
                $tableInfo['id'],
            ));
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
            !in_array($workspaceType, self::CLONE_SUPPORTED_BACKENDS, true)
        ) {
            return false;
        }

        if (array_key_exists('hasExternalSchema', $tableInfo['bucket'])
            && $tableInfo['bucket']['hasExternalSchema'] === true
        ) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $tableInfo
     * @param array<string, mixed> $exportOptions
     */
    public static function canUseView(
        array $tableInfo,
        string $workspaceType,
        array $exportOptions,
        string $currentProjectId,
    ): bool {
        $backend = $tableInfo['bucket']['backend'];
        $isBackendMatch = $backend === $workspaceType;

        if (!$isBackendMatch) {
            return false;
        }

        if ($workspaceType !== 'bigquery'
            && !($backend === 'snowflake'
                && array_key_exists('hasExternalSchema', $tableInfo['bucket'])
                && $tableInfo['bucket']['hasExternalSchema'] === true)
        ) {
            return false;
        }

        $hasOtherThanOverwriteOptions = $exportOptions && array_keys($exportOptions) !== ['overwrite'];
        if ($hasOtherThanOverwriteOptions) {
            return false;
        }

        $isAliasInCurrentProject = $tableInfo['isAlias'] &&
            ((string) $tableInfo['sourceTable']['project']['id'] === $currentProjectId);
        if ($isAliasInCurrentProject) {
            return false;
        }

        return true;
    }
}
