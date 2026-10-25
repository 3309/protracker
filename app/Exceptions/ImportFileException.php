<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when an uploaded file cannot be read as the chosen format at all,
 * for example when required columns are missing. Nothing is saved.
 */
class ImportFileException extends Exception
{
    //
}
