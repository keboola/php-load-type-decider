# Load Type Decider

Single source of truth for Keboola's workspace table **load-type decision** —
which mechanism the server uses to load a Storage table into a workspace:

- `COPY` — always available fallback.
- `CLONE` — zero-copy, identical row semantics (Snowflake / BigQuery).
- `VIEW` — live view over the source table.

The library is intentionally **dependency-free of the platform**: it never reads
features from a database or talks to any backend. The caller resolves project
features one level up and passes them in as plain booleans via
`LoadTypeDeciderFeatures`. This keeps the decision logic centralized, pure, and
trivially testable.

## Installation

```bash
composer require keboola/php-load-type-decider
```

## Usage

```php
use Keboola\LoadTypeDecider\LoadTypeDecider;
use Keboola\LoadTypeDecider\LoadTypeDeciderFeatures;

$decision = LoadTypeDecider::decide(
    $tableInfo,      // Storage API table detail
    $workspaceType,  // 'snowflake' | 'bigquery'
    $exportOptions,  // options about to be passed to the workspace load
    new LoadTypeDeciderFeatures(
        bigqueryDefaultImView: false,
        snowflakeReadOnlyStorage: true,
    ),
);

$decision->preferred;  // LoadType the server picks when none is pinned
$decision->possible;   // full set the caller may choose from (always contains COPY)
```

Call `LoadTypeDecider::checkViableLoadMethod()` before deciding to reject loads
that no method can satisfy (throws `Exception\InvalidInputException`).

## Development

This package is developed inside the [keboola/connection](https://github.com/keboola/connection)
monorepo and mirrored read-only to
[keboola/php-load-type-decider](https://github.com/keboola/php-load-type-decider).
Open pull requests against the monorepo.

```bash
composer install
composer ci      # lint + phpcs + phpstan + tests
```

## License

MIT — see [LICENSE](LICENSE).
