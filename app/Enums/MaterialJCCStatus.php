<?php

namespace App\Enums;

/**
 * Material JCC Status Enum
 *
 * Defines the status values for Material Job Completion Certificates.
 */
enum MaterialJCCStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';

    /**
     * Get all status values as array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a status value is valid
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::values());
    }

    /**
     * Get status from string (case-insensitive)
     */
    public static function fromString(string $status): ?self
    {
        return match(strtolower($status)) {
            'draft' => self::DRAFT,
            'submitted' => self::SUBMITTED,
            'approved' => self::APPROVED,
            default => null,
        };
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::DRAFT => 'Draft',
            self::SUBMITTED => 'Submitted for Approval',
            self::APPROVED => 'Approved',
        };
    }
}
