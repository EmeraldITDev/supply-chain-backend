<?php

namespace App\Enums;

/**
 * Trip Vendor Submission Status Enum
 * 
 * Defines the valid status values for vendor submissions on trips.
 */
enum TripVendorSubmissionStatus: string
{
    case PENDING = 'pending';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

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
            'pending' => self::PENDING,
            'submitted' => self::SUBMITTED,
            'approved' => self::APPROVED,
            'rejected' => self::REJECTED,
            default => null,
        };
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending Submission',
            self::SUBMITTED => 'Submitted',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
        };
    }
}
