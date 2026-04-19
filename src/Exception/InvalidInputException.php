<?php

declare(strict_types=1);

namespace Keboola\Package\LoadTypeDecider\Exception;

use Keboola\CommonExceptions\ApplicationExceptionInterface;
use RuntimeException;

class InvalidInputException extends RuntimeException implements ApplicationExceptionInterface
{
}
