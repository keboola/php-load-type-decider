<?php

declare(strict_types=1);

namespace Keboola\Package\LoadTypeDecider;

/**
 * The outcome of {@see LoadTypeDecider::decide()}: the load type the server
 * picks when the caller does not pin one explicitly (`preferred`), plus the full
 * set the caller is allowed to choose from (`possible`, always contains COPY).
 */
final readonly class LoadTypeDecision
{
    /**
     * @param list<LoadType> $possible
     */
    public function __construct(
        public LoadType $preferred,
        public array $possible,
    ) {
    }
}
