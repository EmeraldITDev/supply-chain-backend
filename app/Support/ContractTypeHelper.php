<?php

namespace App\Support;

class ContractTypeHelper
{
    public const STANDARD_TYPES = ['emerald', 'oando', 'dangote', 'heritage'];

    public static function normalize(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    public static function isStandard(?string $contractType): bool
    {
        return in_array(self::normalize($contractType), self::STANDARD_TYPES, true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function standardOptions(): array
    {
        return [
            ['value' => 'emerald', 'label' => 'Emerald'],
            ['value' => 'oando', 'label' => 'Oando'],
            ['value' => 'dangote', 'label' => 'Dangote'],
            ['value' => 'heritage', 'label' => 'Heritage'],
        ];
    }
}
