<?php

namespace IPP\Student;

use IPP\Core\Exception\IPPException;
use IPP\Core\ReturnCode;
use Throwable;

/**
 * Exception for runtime error - non-existent variable
 */
class VariableAccessException extends IPPException
{
    public function __construct(string $message = "Runtime error - non-existent variable", ?Throwable $previous = null)
    {
        parent::__construct($message, ReturnCode::VARIABLE_ACCESS_ERROR, $previous, false);
    }
}
