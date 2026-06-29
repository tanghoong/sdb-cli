<?php

declare(strict_types=1);

namespace Sdb\Exception;

/**
 * Thrown for user-facing usage problems (bad flags, malformed --where, etc).
 * Mapped to exit code 2 by SdbApplication.
 */
final class UsageException extends \RuntimeException
{
}
