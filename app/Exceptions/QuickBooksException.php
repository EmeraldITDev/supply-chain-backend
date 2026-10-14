<?php

namespace App\Exceptions;

use RuntimeException;

class QuickBooksException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $faultCode = null,
        public readonly ?string $intuitTid = null,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
