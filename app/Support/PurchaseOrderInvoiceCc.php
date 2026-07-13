<?php

namespace App\Support;

/**
 * Normalises PO invoice submission CC lists and ensures procurement is always copied.
 */
final class PurchaseOrderInvoiceCc
{
    public const PROCUREMENT_EMAIL = 'procurement@emeraldcfze.com';

    /**
     * Default CC string for new PO forms / empty drafts.
     */
    public static function defaultCc(): string
    {
        $configured = (string) config('scm.invoice_submission_cc', 'lateef.olanrewaju@emeraldcfze.com,'.self::PROCUREMENT_EMAIL);

        return self::merge($configured);
    }

    /**
     * Merge arbitrary CC values (comma/semicolon separated) and always include procurement.
     */
    public static function merge(?string ...$parts): string
    {
        $emails = [];
        foreach ($parts as $part) {
            if ($part === null || trim($part) === '') {
                continue;
            }
            foreach (preg_split('/[,;]+/', $part) ?: [] as $email) {
                $normalized = strtolower(trim($email));
                if ($normalized !== '' && filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                    $emails[$normalized] = $normalized;
                }
            }
        }

        $emails[self::PROCUREMENT_EMAIL] = self::PROCUREMENT_EMAIL;

        return implode(', ', array_values($emails));
    }
}
