<?php

namespace IPP\Student;

use IPP\Core\Exception\IPPException;
use IPP\Core\ReturnCode;
use Throwable;

/**
 * Exception for runtime error - bad operand types
 */
class OperandTypeException extends IPPException
{
    public function __construct(string $message = "Runtime error - bad operand types", ?Throwable $previous = null)
    {
        parent::__construct($message, ReturnCode::OPERAND_TYPE_ERROR, $previous, false);
    }
}
