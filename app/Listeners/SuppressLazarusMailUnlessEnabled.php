<?php

namespace App\Listeners;

use App\Mail\MRFApprovedMail;
use App\Mail\MRFCreatedMail;
use App\Mail\MRFRejectedMail;
use App\Mail\POGeneratedMail;
use App\Mail\POReadyForSignatureMail;
use App\Mail\PORevisedForResignMail;
use App\Mail\POSignedMail;
use App\Mail\VendorSelectionApprovedMail;
use App\Notifications\MRFApprovedNotification;
use App\Notifications\MRFRejectedNotification;
use App\Notifications\MRFRequesterUpdatedNotification;
use App\Notifications\MRFSubmittedNotification;
use App\Notifications\ProcurementActionReminderNotification;
use App\Support\LazarusNotificationSettings;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class SuppressLazarusMailUnlessEnabled
{
    /**
     * Mail and notification classes that can be turned on from Settings.
     * Anything else addressed to Lazarus is dropped.
     *
     * @var array<class-string, string>
     */
    private const CLASS_TOGGLES = [
        MRFCreatedMail::class => 'mrf_submitted',
        MRFApprovedMail::class => 'mrf_submitted',
        MRFRejectedMail::class => 'mrf_submitted',
        MRFSubmittedNotification::class => 'mrf_submitted',
        MRFApprovedNotification::class => 'mrf_submitted',
        MRFRejectedNotification::class => 'mrf_submitted',
        MRFRequesterUpdatedNotification::class => 'mrf_requester_updated',
        VendorSelectionApprovedMail::class => 'vendor_selection_approved',
        POGeneratedMail::class => 'po_generated',
        POReadyForSignatureMail::class => 'po_generated',
        POSignedMail::class => 'po_signed',
        PORevisedForResignMail::class => 'po_revised',
        ProcurementActionReminderNotification::class => 'approval_reminders',
    ];

    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;
        if (! $this->includesLazarus($message)) {
            return null;
        }

        if ($this->isAccountAccessMail($message)) {
            return null;
        }

        $toggle = $this->toggleFor($event);
        if ($toggle !== null && LazarusNotificationSettings::enabled($toggle)) {
            return null;
        }

        $to = $this->withoutLazarus($message->getTo());
        $cc = $this->withoutLazarus($message->getCc());
        $bcc = $this->withoutLazarus($message->getBcc());

        Log::info('Suppressed email to Lazarus because the Settings switch is off', [
            'subject' => $message->getSubject(),
            'toggle' => $toggle,
        ]);

        if ($to === []) {
            return false;
        }

        $message->to(...$to);
        $message->cc(...$cc);
        $message->bcc(...$bcc);

        return null;
    }

    private function toggleFor(MessageSending $event): ?string
    {
        $class = $event->data['__laravel_mailable']
            ?? $event->data['__laravel_notification']
            ?? null;

        if (! is_string($class)) {
            return null;
        }

        return self::CLASS_TOGGLES[$class] ?? null;
    }

    private function isAccountAccessMail(Email $message): bool
    {
        $subject = strtolower((string) $message->getSubject());

        return str_contains($subject, 'password reset')
            || str_contains($subject, 'reset password');
    }

    /**
     * @param  array<int, Address>  $addresses
     */
    private function includesLazarus(Email $message): bool
    {
        foreach ([...$message->getTo(), ...$message->getCc(), ...$message->getBcc()] as $address) {
            if (LazarusNotificationSettings::isLazarusEmail($address->getAddress())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return list<Address>
     */
    private function withoutLazarus(array $addresses): array
    {
        return array_values(array_filter(
            $addresses,
            fn (Address $address) => ! LazarusNotificationSettings::isLazarusEmail($address->getAddress())
        ));
    }
}
