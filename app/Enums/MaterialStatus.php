<?php

namespace App\Enums;

/**
 * Material Status Enum
 *
 * Defines the lifecycle status values for material movements.
 */
enum MaterialStatus: string
{
    case PENDING = 'pending';
    case IN_TRANSIT = 'in_transit';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';

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
            'in_transit' => self::IN_TRANSIT,
            'delivered' => self::DELIVERED,
            'cancelled' => self::CANCELLED,
            default => null,
        };
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending Pickup',
            self::IN_TRANSIT => 'In Transit',
            self::DELIVERED => 'Delivered',
            self::CANCELLED => 'Cancelled',
        };
    }
}
