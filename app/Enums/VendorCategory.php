<?php

namespace App\Enums;

/**
 * Vendor Category Enum
 * 
 * Defines all available vendor categories for the supply chain system.
 * These categories are used for vendor classification and filtering.
 */
enum VendorCategory: string
{
    case RAW_MATERIALS = 'Raw Materials';
    case CONSTRUCTION = 'Construction';
    case SAFETY_EQUIPMENT = 'Safety Equipment';
    case ELECTRONICS = 'Electronics';
    case MACHINERY = 'Machinery';
    case CHEMICALS = 'Chemicals';
    case LOGISTICS = 'Logistics';
    case PACKAGING = 'Packaging';
    case SERVICES = 'Services';
    case MAINTENANCE = 'Maintenance';
    case IT_SOLUTIONS = 'IT Solutions';
    case CONSULTING = 'Consulting';

    /**
     * Get all category values as array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all categories as key-value pairs for dropdown
     */
    public static function options(): array
    {
        return array_reduce(self::cases(), function($carry, $case) {
            $carry[$case->value] = $case->value;
            return $carry;
        }, []);
    }

    /**
     * Check if a category value is valid
     */
    public static function isValid(string $category): bool
    {
        return in_array($category, self::values());
    }

    /**
     * Get category from string (case-insensitive) with proper casing
     */
    public static function fromString(string $category): ?self
    {
        foreach (self::cases() as $case) {
            if (strtolower($case->value) === strtolower($category)) {
                return $case;
            }
        }
        return null;
    }
}
