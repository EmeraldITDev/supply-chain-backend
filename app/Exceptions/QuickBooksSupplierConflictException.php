<?php

namespace App\Exceptions;

use RuntimeException;

class QuickBooksSupplierConflictException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $conflictType,
        string $message,
        public readonly string $recommendedResolution,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
