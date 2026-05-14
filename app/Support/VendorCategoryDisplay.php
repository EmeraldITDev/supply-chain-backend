<?php

namespace App\Support;

/**
 * Human-readable vendor category strings (e.g. append free-text for "Others").
 */
final class VendorCategoryDisplay
{
    /**
     * Build a display label for vendor category, expanding "Others" with optional free text.
     *
     * Supports comma-separated multi-values from the UI, e.g.
     * "Accommodation/Hotel, Others" + categoryOther "Plumbing"
     * → "Accommodation/Hotel, Others: Plumbing"
     */
    public static function format(?string $category, ?string $categoryOther): string
    {
        $category = trim((string) $category);
        if ($category === '') {
            return '';
        }

        $other = trim((string) $categoryOther);
        if ($other === '') {
            return $category;
        }

        $parts = array_map('trim', explode(',', $category));
        $out = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^Others\s*:/iu', $part)) {
                $out[] = $part;

                continue;
            }
            if (strcasecmp($part, 'Others') === 0) {
                $out[] = 'Others: ' . $other;

                continue;
            }
            $out[] = $part;
        }

        return implode(', ', $out);
    }
}
