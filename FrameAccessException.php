<?php

namespace IPP\Student;

use IPP\Core\Exception\IPPException;
use IPP\Core\ReturnCode;
use Throwable;

/**
 * Exception for runtime error - non-existent frame
 */
class FrameAccessException extends IPPException
{
    public function __construct(string $message = "Runtime error - non-existent frame", ?Throwable $previous = null)
    {
        parent::__construct($message, ReturnCode::FRAME_ACCESS_ERROR, $previous, false);
    }
}
