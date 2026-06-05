<?php

declare(strict_types=1);

namespace Keboola\LoadTypeDecider;

/**
 * Project-feature inputs to {@see LoadTypeDecider::decide()}.
 *
 * The library never reads these from a database — the caller resolves the
 * project features one level up and passes the booleans in. Bundling them in a
 * DTO keeps `decide()`'s signature stable as more feature gates are added.
 */
final readonly class LoadTypeDeciderFeatures
{
    public function __construct(
        /**
         * When true, BigQuery input-mapping loads default to VIEW instead of
         * CLONE (project feature `bigquery-default-im-view`). The caller can
         * still override the choice with an explicit load type.
         */
        public bool $bigqueryDefaultImView,
        /**
         * When true, Snowflake VIEW loads are permitted (project read-only
         * storage input-mapping feature). When false, VIEW is not offered for
         * Snowflake.
         */
        public bool $snowflakeReadOnlyStorage,
    ) {
    }
}
