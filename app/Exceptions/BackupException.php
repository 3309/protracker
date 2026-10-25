<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a backup or restore cannot be done safely. The message
 * explains why in plain language, and nothing is changed.
 */
class BackupException extends Exception
{
    //
}
