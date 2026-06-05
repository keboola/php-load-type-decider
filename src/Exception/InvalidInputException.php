<?php

declare(strict_types=1);

namespace Keboola\LoadTypeDecider\Exception;

use Keboola\CommonExceptions\ApplicationExceptionInterface;
use RuntimeException;

final class InvalidInputException extends RuntimeException implements ApplicationExceptionInterface
{
}
