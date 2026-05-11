<?php

namespace App\Enums;

/**
 * Condition On Arrival Enum
 *
 * Defines the condition status for materials upon delivery/arrival.
 */
enum ConditionOnArrival: string
{
    case GOOD = 'good';
    case DAMAGED = 'damaged';
    case PARTIAL = 'partial';

    /**
     * Get all condition values as array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a condition value is valid
     */
    public static function isValid(string $condition): bool
    {
        return in_array($condition, self::values());
    }

    /**
     * Get condition from string (case-insensitive)
     */
    public static function fromString(string $condition): ?self
    {
        return match(strtolower($condition)) {
            'good' => self::GOOD,
            'damaged' => self::DAMAGED,
            'partial' => self::PARTIAL,
            default => null,
        };
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::GOOD => 'Good Condition',
            self::DAMAGED => 'Damaged',
            self::PARTIAL => 'Partial Delivery',
        };
    }
}
