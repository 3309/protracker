<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when an import row cannot be turned into a record, for example
 * when no single open option matches it. The row stays in the review queue.
 */
class ImportRowException extends Exception
{
    //
}
