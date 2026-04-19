<?php

declare(strict_types=1);

namespace Keboola\Package\LoadTypeDecider\Exception;

use Keboola\CommonExceptions\ApplicationExceptionInterface;
use RuntimeException;

/**
 * Signals a caller contract violation: a method on LoadTypeDecider was invoked
 * with inputs that the caller was expected to validate upstream (e.g. calling
 * checkViableBigQueryLoadMethod() with a non-BigQuery table/workspace combo).
 *
 * This is an internal invariant check, not user input validation — callers
 * must translate user-facing errors into their own domain exceptions before
 * reaching this decider.
 */
final class InvalidInputException extends RuntimeException implements ApplicationExceptionInterface
{
}
