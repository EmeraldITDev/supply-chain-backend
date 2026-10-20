<?php

namespace App\Notifications;

use App\Models\MRF;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProcurementActionReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected MRF $mrf,
        protected int $hoursPending,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $label   = $this->mrf->formatted_id ?: $this->mrf->mrf_id;
        $state   = $this->mrf->workflow_state ?? 'unknown';
        $subject = $this->buildSubject($label, $state);
        $action  = $this->buildActionLabel($state);
        $deepLink = $this->buildDeepLink();

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->buildIntroLine($state, $label))
            ->line('**Record:** '.$label)
            ->line('**Title:** '.($this->mrf->title ?? 'N/A'))
            ->line('**Current stage:** '.$this->humanState($state))
            ->line('**Pending for:** approximately '.$this->hoursPending.' hour(s)')
            ->line($this->buildSlaLine())
            ->action($action, $deepLink)
            ->line('This is a reminder only. No records were changed automatically.')
            ->line('If someone else has taken action or the record has been updated, this reminder may be superseded.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'          => 'procurement_action_reminder',
            'mrf_id'        => $this->mrf->mrf_id,
            'formatted_id'  => $this->mrf->formatted_id,
            'title'         => $this->mrf->title,
            'workflow_state' => $this->mrf->workflow_state,
            'hours_pending' => $this->hoursPending,
            'action_url'    => $this->buildDeepLink(),
            'message'       => 'Procurement action reminder — no automatic status changes were applied.',
        ];
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private function buildSubject(string $label, string $state): string
    {
        return match (true) {
            in_array($state, ['parallel_first_approval', 'supply_chain_director_review'], true)
                => "Approval Required: Material Requisition {$label}",
            $state === 'executive_review'
                => "Approval Required: Material Requisition {$label}",
            in_array($state, ['pending_scd_signature', 'po_generated'], true)
                => "Action Required: Purchase Order Awaiting Signature — {$label}",
            in_array($state, ['procurement_review', 'vendor_selection_pending'], true)
                => "Action Required: Procurement Review Pending — {$label}",
            in_array($state, ['finance_handoff_pending', 'finance_in_review'], true)
                => "Action Required: Finance Processing Pending — {$label}",
            default
                => "Reminder: SCM Action Required — {$label}",
        };
    }

    private function buildActionLabel(string $state): string
    {
        return match (true) {
            in_array($state, ['parallel_first_approval', 'supply_chain_director_review', 'executive_review'], true)
                => 'Review and Approve',
            in_array($state, ['pending_scd_signature', 'po_generated'], true)
                => 'Review and Sign Purchase Order',
            in_array($state, ['procurement_review', 'vendor_selection_pending'], true)
                => 'Open Procurement Review',
            default
                => 'Open Record in SCM',
        };
    }

    private function buildIntroLine(string $state, string $label): string
    {
        return match (true) {
            in_array($state, ['parallel_first_approval', 'supply_chain_director_review', 'executive_review'], true)
                => "Material Requisition {$label} is awaiting your approval and has not been actioned for approximately {$this->hoursPending} hour(s).",
            in_array($state, ['pending_scd_signature', 'po_generated'], true)
                => "A Purchase Order for {$label} is ready and awaiting your signature. It has been pending for approximately {$this->hoursPending} hour(s).",
            in_array($state, ['procurement_review', 'vendor_selection_pending'], true)
                => "Requisition {$label} is awaiting procurement review or vendor selection action.",
            default
                => "A procurement item ({$label}) requires your attention. It has been in its current stage for approximately {$this->hoursPending} hour(s).",
        };
    }

    private function buildSlaLine(): string
    {
        if ($this->hoursPending >= 72) {
            return '⚠️ This item has been pending for more than 3 days.';
        }

        return '';
    }

    private function buildDeepLink(): string
    {
        $base  = rtrim((string) config('app.frontend_url', config('services.frontend_url', 'https://scm.emeraldcfze.com')), '/');
        $mrfId = $this->mrf->mrf_id;
        $state = (string) ($this->mrf->workflow_state ?? '');

        // PO signature — go directly to MRF detail with PO tab intent
        if (in_array($state, ['pending_scd_signature', 'po_generated'], true)) {
            return "{$base}/mrfs/{$mrfId}?tab=po&action=sign";
        }

        // All other states — go to MRF detail
        return "{$base}/mrfs/{$mrfId}";
    }

    private function humanState(string $state): string
    {
        $map = [
            'parallel_first_approval'          => 'Parallel First Approval (SCD + Executive)',
            'supply_chain_director_review'     => 'Supply Chain Director Review',
            'executive_review'                 => 'Executive Review',
            'lazarus_director_approval'        => 'Director Approval',
            'procurement_review'               => 'Procurement Review',
            'vendor_selection_pending'         => 'Vendor Selection',
            'pending_scd_signature'            => 'Awaiting SCD Signature',
            'po_generated'                     => 'Purchase Order Generated (Awaiting Signature)',
            'finance_handoff_pending'          => 'Finance Handoff Pending',
            'finance_in_review'                => 'Finance In Review',
        ];

        return $map[$state] ?? ucwords(str_replace('_', ' ', $state));
    }
}
