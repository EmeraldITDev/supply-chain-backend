<?php

namespace App\Enums;

/**
 * Trip Approval Status Enum
 * 
 * Defines the valid approval status values for trips with multi-vendor workflow.
 */
enum TripApprovalStatus: string
{
    case DRAFT = 'draft';
    case PENDING_REVIEW = 'pending_review';
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
            'draft' => self::DRAFT,
            'pending_review' => self::PENDING_REVIEW,
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
            self::DRAFT => 'Draft',
            self::PENDING_REVIEW => 'Pending Review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
        };
    }
}
