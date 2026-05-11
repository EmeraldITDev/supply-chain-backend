<?php

namespace App\Enums;

/**
 * Material Condition Enum
 *
 * Defines the condition status for materials before pickup.
 */
enum MaterialCondition: string
{
    case NEW = 'new';
    case USED = 'used';
    case DAMAGED = 'damaged';

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
            'new' => self::NEW,
            'used' => self::USED,
            'damaged' => self::DAMAGED,
            default => null,
        };
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::NEW => 'New',
            self::USED => 'Used',
            self::DAMAGED => 'Damaged',
        };
    }
}
