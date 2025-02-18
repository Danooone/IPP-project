<?php

namespace IPP\Student;

use IPP\Core\Exception\IPPException;
use IPP\Core\ReturnCode;
use Throwable;

/**
 * Exception for runtime error - bad string operation
 */
class StringOperationException extends IPPException
{
    public function __construct(string $message = "Runtime error - bad string operation", ?Throwable $previous = null)
    {
        parent::__construct($message, ReturnCode::STRING_OPERATION_ERROR, $previous, false);
    }
}
