<?php

namespace App\Enums;

/**
 * Vendor Registration Status Enum
 * 
 * Defines the valid status values for vendor registrations.
 * These must match the database ENUM constraint exactly (case-sensitive).
 */
enum VendorRegistrationStatus: string
{
    case PENDING = 'Pending';
    case APPROVED = 'Approved';
    case REJECTED = 'Rejected';

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
     * Get status from string (case-insensitive) with proper casing
     */
    public static function fromString(string $status): ?self
    {
        return match(strtolower($status)) {
            'pending' => self::PENDING,
            'approved' => self::APPROVED,
            'rejected' => self::REJECTED,
            default => null,
        };
    }
}
