<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\PaymentMilestone;
use App\Models\PaymentSchedule;
use App\Models\PaymentScheduleVersion;
use App\Models\RFQ;
use App\Models\User;
use App\Support\PaymentMilestoneRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentScheduleService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listTemplates(): array
    {
        return array_values(array_map(function (array $template) {
            return [
                'key' => $template['key'],
                'name' => $template['name'],
                'milestones' => array_map(fn (array $m) => $this->normalizeMilestoneInput($m), $template['milestones']),
            ];
        }, config('payment_term_templates', [])));
    }

    public function findForMrf(MRF $mrf): ?PaymentSchedule
    {
        return PaymentSchedule::query()
            ->where('mrf_id', $mrf->id)
            ->with(['milestones', 'creator:id,name,email'])
            ->first();
    }

    /**
     * Create or update schedule when payment_milestones[] is sent on MRF/RFQ/PO payloads.
     */
    public function applyFromRequest(MRF $mrf, User $user, Request $request): ?PaymentSchedule
    {
        if (! PaymentMilestoneRequest::provided($request)) {
            return null;
        }

        $rows = PaymentMilestoneRequest::resolve($request);
        PaymentMilestoneRequest::validatePercentages($rows);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'payment_milestones' => 'At least one payment milestone is required when payment_milestones is provided.',
            ]);
        }

        $input = ['milestones' => PaymentMilestoneRequest::toScheduleMilestones($rows)];

        if ($this->findForMrf($mrf)) {
            return $this->update($mrf, $user, $input);
        }

        return $this->create($mrf, $user, $input);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function paymentMilestonesForApi(?PaymentSchedule $schedule): array
    {
        if (! $schedule) {
            return [];
        }

        $schedule->loadMissing('milestones');

        return $schedule->milestones->map(fn (PaymentMilestone $m) => [
            'label' => $m->label,
            'percentage' => (float) $m->percentage,
            'trigger_condition' => $m->trigger_condition,
            'triggerCondition' => $m->trigger_condition,
        ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function paymentMilestonesForMrf(MRF $mrf): array
    {
        return $this->paymentMilestonesForApi($this->findForMrf($mrf));
    }

    public function assertEditable(PaymentSchedule $schedule): void
    {
        if ($schedule->isLocked()) {
            throw ValidationException::withMessages([
                'paymentSchedule' => 'Payment schedule is locked after PO generation and cannot be edited.',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $milestones
     */
    public function validateMilestonePercentages(array $milestones): void
    {
        $total = 0.0;

        foreach ($milestones as $milestone) {
            $total += (float) ($milestone['percentage'] ?? 0);
        }

        if (abs($total - 100.0) > 0.001) {
            throw ValidationException::withMessages([
                'milestones' => sprintf(
                    'Milestone percentages must total 100%% (current total: %s%%).',
                    number_format($total, 2, '.', '')
                ),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(MRF $mrf, User $user, array $input): PaymentSchedule
    {
        if ($this->findForMrf($mrf)) {
            throw ValidationException::withMessages([
                'paymentSchedule' => 'A payment schedule already exists for this MRF. Use PUT to update.',
            ]);
        }

        $milestones = $this->resolveMilestonesFromInput($input);
        $this->validateMilestonePercentages($milestones);

        return DB::transaction(function () use ($mrf, $user, $input, $milestones) {
            $schedule = PaymentSchedule::create([
                'mrf_id' => $mrf->id,
                'template_name' => $input['template_key'] ?? $input['templateKey'] ?? null,
                'total_percentage_check' => 100,
                'created_by' => $user->id,
                'version' => 1,
            ]);

            $this->persistMilestones($schedule, $milestones);

            PaymentScheduleVersion::create([
                'payment_schedule_id' => $schedule->id,
                'version' => 1,
                'changed_by' => $user->id,
                'snapshot_before' => null,
                'snapshot_after' => $this->snapshot($schedule->fresh(['milestones'])),
                'created_at' => now(),
            ]);

            $this->syncRfqPaymentTermsSummary($mrf, $schedule->fresh(['milestones']));

            return $schedule->fresh(['milestones', 'creator:id,name,email']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(MRF $mrf, User $user, array $input): PaymentSchedule
    {
        $schedule = $this->findForMrf($mrf);

        if (! $schedule) {
            throw ValidationException::withMessages([
                'paymentSchedule' => 'No payment schedule exists for this MRF. Use POST to create.',
            ]);
        }

        $this->assertEditable($schedule);

        $milestones = $this->resolveMilestonesFromInput($input);
        $this->validateMilestonePercentages($milestones);

        return DB::transaction(function () use ($schedule, $user, $input, $milestones, $mrf) {
            $before = $this->snapshot($schedule->loadMissing('milestones'));
            $nextVersion = $schedule->version + 1;

            $schedule->update([
                'template_name' => $input['template_key'] ?? $input['templateKey'] ?? $schedule->template_name,
                'total_percentage_check' => 100,
                'version' => $nextVersion,
            ]);

            $schedule->milestones()->delete();
            $this->persistMilestones($schedule, $milestones);

            PaymentScheduleVersion::create([
                'payment_schedule_id' => $schedule->id,
                'version' => $nextVersion,
                'changed_by' => $user->id,
                'snapshot_before' => $before,
                'snapshot_after' => $this->snapshot($schedule->fresh(['milestones'])),
                'created_at' => now(),
            ]);

            $schedule = $schedule->fresh(['milestones', 'creator:id,name,email']);
            $this->syncRfqPaymentTermsSummary($mrf, $schedule);

            return $schedule;
        });
    }

    public function lockOnPoGeneration(MRF $mrf, float $poTotal): ?PaymentSchedule
    {
        $schedule = $this->findForMrf($mrf);

        if (! $schedule) {
            return null;
        }

        if ($schedule->isLocked()) {
            return $schedule;
        }

        $this->recalculateAmounts($schedule, $poTotal);

        $schedule->update(['locked_at' => now()]);

        return $schedule->fresh(['milestones']);
    }

    public function recalculateAmounts(PaymentSchedule $schedule, float $poTotal): void
    {
        $schedule->loadMissing('milestones');
        $remaining = round($poTotal, 2);
        $count = $schedule->milestones->count();

        foreach ($schedule->milestones->values() as $index => $milestone) {
            if ($index === $count - 1) {
                $amount = $remaining;
            } else {
                $amount = round($poTotal * ((float) $milestone->percentage / 100), 2);
                $remaining = round($remaining - $amount, 2);
            }

            $milestone->update(['amount' => max(0, $amount)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(PaymentSchedule $schedule): array
    {
        $schedule->loadMissing(['milestones', 'creator:id,name,email']);

        return [
            'id' => $schedule->id,
            'mrfId' => $schedule->mrf_id,
            'templateKey' => $schedule->template_name,
            'template_key' => $schedule->template_name,
            'templateName' => $this->templateDisplayName($schedule->template_name),
            'totalPercentageCheck' => (float) $schedule->total_percentage_check,
            'version' => $schedule->version,
            'isLocked' => $schedule->isLocked(),
            'lockedAt' => $schedule->locked_at?->toIso8601String(),
            'approvedAt' => $schedule->approved_at?->toIso8601String(),
            'summary' => $this->summaryText($schedule),
            'createdBy' => $schedule->creator ? [
                'id' => $schedule->creator->id,
                'name' => $schedule->creator->name,
            ] : null,
            'milestones' => $schedule->milestones->map(fn (PaymentMilestone $m) => $this->milestoneToApi($m))->values()->all(),
            'createdAt' => $schedule->created_at?->toIso8601String(),
            'updatedAt' => $schedule->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function milestonesForPoPdf(PaymentSchedule $schedule, float $poTotal, string $currency = 'NGN'): array
    {
        if (! $schedule->isLocked()) {
            $this->recalculateAmounts($schedule, $poTotal);
        }

        $schedule->loadMissing('milestones');

        return $schedule->milestones->map(function (PaymentMilestone $m) use ($currency) {
            return [
                'number' => $m->milestone_number,
                'label' => $m->label,
                'percentage' => number_format((float) $m->percentage, 2, '.', '') . '%',
                'amount' => $this->formatMoney((float) ($m->amount ?? 0), $currency),
                'trigger' => $this->triggerConditionLabel($m->trigger_condition),
            ];
        })->values()->all();
    }

    public function summaryText(PaymentSchedule $schedule): string
    {
        $schedule->loadMissing('milestones');

        return $schedule->milestones
            ->map(fn (PaymentMilestone $m) => sprintf(
                '%s%% %s',
                rtrim(rtrim(number_format((float) $m->percentage, 2, '.', ''), '0'), '.'),
                $m->label
            ))
            ->implode(' / ');
    }

    public function triggerConditionLabel(string $trigger): string
    {
        return match ($trigger) {
            PaymentMilestone::TRIGGER_ON_ADVANCE => 'Advance',
            PaymentMilestone::TRIGGER_UPON_DELIVERY => 'Upon Delivery',
            PaymentMilestone::TRIGGER_UPON_COMPLETION => 'Upon Completion',
            default => ucwords(str_replace('_', ' ', $trigger)),
        };
    }

    public function hasAdvanceMilestone(?PaymentSchedule $schedule): bool
    {
        if (! $schedule) {
            return false;
        }

        $schedule->loadMissing('milestones');

        return $schedule->milestones->contains(
            fn (PaymentMilestone $m) => $m->trigger_condition === PaymentMilestone::TRIGGER_ON_ADVANCE
        );
    }

    public function isAdvanceOnlySchedule(?PaymentSchedule $schedule): bool
    {
        if (! $schedule) {
            return false;
        }

        $schedule->loadMissing('milestones');

        if ($schedule->milestones->isEmpty()) {
            return false;
        }

        return $schedule->milestones->every(
            fn (PaymentMilestone $m) => $m->trigger_condition === PaymentMilestone::TRIGGER_ON_ADVANCE
        );
    }

    public function requiresDeliveryConfirmationStage(?PaymentSchedule $schedule): bool
    {
        if (! $schedule) {
            return false;
        }

        $schedule->loadMissing('milestones');

        foreach ($schedule->milestones as $milestone) {
            if ($this->milestoneRequiresDeliveryConfirmation($milestone)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<PaymentMilestone>
     */
    public function milestonesWithOperationalDocumentRequirements(?PaymentSchedule $schedule): array
    {
        if (! $schedule) {
            return [];
        }

        $schedule->loadMissing('milestones');

        return $schedule->milestones
            ->filter(fn (PaymentMilestone $m) => count($this->requiredDocumentsForMilestone($m)) > 0)
            ->values()
            ->all();
    }

    public function currentPendingMilestone(?PaymentSchedule $schedule): ?PaymentMilestone
    {
        if (! $schedule) {
            return null;
        }

        $schedule->loadMissing('milestones');

        return $schedule->milestones
            ->first(fn (PaymentMilestone $m) => ! in_array($m->status, [
                PaymentMilestone::STATUS_PAID,
                PaymentMilestone::STATUS_COMPLETE,
            ], true));
    }

    /**
     * @return list<string>
     */
    public function requiredDocumentsForMilestone(PaymentMilestone $milestone): array
    {
        $documents = is_array($milestone->required_documents) ? $milestone->required_documents : [];

        return array_values(array_unique(array_filter($documents, fn ($d) => is_string($d) && $d !== '')));
    }

    public function milestoneRequiresDeliveryConfirmation(PaymentMilestone $milestone): bool
    {
        if ($milestone->trigger_condition === PaymentMilestone::TRIGGER_UPON_DELIVERY) {
            return true;
        }

        $deliveryDocs = ['grn', 'waybill'];

        return (bool) array_intersect($this->requiredDocumentsForMilestone($milestone), $deliveryDocs);
    }

    public function allMilestonesFinanciallyComplete(?PaymentSchedule $schedule): bool
    {
        if (! $schedule) {
            return false;
        }

        $schedule->loadMissing('milestones');

        if ($schedule->milestones->isEmpty()) {
            return false;
        }

        return $schedule->milestones->every(
            fn (PaymentMilestone $m) => in_array($m->status, [
                PaymentMilestone::STATUS_PAID,
                PaymentMilestone::STATUS_COMPLETE,
            ], true)
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function resolveMilestonesFromInput(array $input): array
    {
        $templateKey = $input['template_key'] ?? $input['templateKey'] ?? null;
        $custom = $input['milestones'] ?? null;

        if (is_array($custom) && count($custom) > 0) {
            return array_map(fn (array $m) => $this->normalizeMilestoneInput($m), $custom);
        }

        if (is_string($templateKey) && $templateKey !== '') {
            $template = config("payment_term_templates.{$templateKey}");

            if (! is_array($template) || empty($template['milestones'])) {
                throw ValidationException::withMessages([
                    'template_key' => "Unknown payment term template: {$templateKey}",
                ]);
            }

            return array_map(fn (array $m) => $this->normalizeMilestoneInput($m), $template['milestones']);
        }

        throw ValidationException::withMessages([
            'milestones' => 'Provide template_key or milestones array.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $milestone
     * @return array<string, mixed>
     */
    private function normalizeMilestoneInput(array $milestone): array
    {
        return [
            'milestone_number' => (int) ($milestone['milestone_number'] ?? $milestone['milestoneNumber'] ?? 0),
            'label' => trim((string) ($milestone['label'] ?? '')),
            'percentage' => (float) ($milestone['percentage'] ?? 0),
            'trigger_condition' => (string) ($milestone['trigger_condition'] ?? $milestone['triggerCondition'] ?? PaymentMilestone::TRIGGER_ON_ADVANCE),
            'required_documents' => $milestone['required_documents'] ?? $milestone['requiredDocuments'] ?? [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $milestones
     */
    private function persistMilestones(PaymentSchedule $schedule, array $milestones): void
    {
        usort($milestones, fn ($a, $b) => ($a['milestone_number'] ?? 0) <=> ($b['milestone_number'] ?? 0));

        $previousId = null;

        foreach ($milestones as $milestone) {
            if ($milestone['label'] === '') {
                throw ValidationException::withMessages([
                    'milestones' => 'Each milestone must have a label.',
                ]);
            }

            if ($milestone['milestone_number'] < 1) {
                throw ValidationException::withMessages([
                    'milestones' => 'Each milestone must have milestone_number >= 1.',
                ]);
            }

            $created = PaymentMilestone::create([
                'payment_schedule_id' => $schedule->id,
                'milestone_number' => $milestone['milestone_number'],
                'label' => $milestone['label'],
                'percentage' => $milestone['percentage'],
                'trigger_condition' => $milestone['trigger_condition'],
                'required_documents' => is_array($milestone['required_documents']) ? $milestone['required_documents'] : [],
                'status' => PaymentMilestone::STATUS_PENDING,
                'predecessor_milestone_id' => $previousId,
            ]);

            $previousId = $created->id;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(PaymentSchedule $schedule): array
    {
        return [
            'template_name' => $schedule->template_name,
            'total_percentage_check' => (float) $schedule->total_percentage_check,
            'version' => $schedule->version,
            'locked_at' => $schedule->locked_at?->toIso8601String(),
            'milestones' => $schedule->milestones->map(fn (PaymentMilestone $m) => [
                'milestone_number' => $m->milestone_number,
                'label' => $m->label,
                'percentage' => (float) $m->percentage,
                'amount' => $m->amount !== null ? (float) $m->amount : null,
                'trigger_condition' => $m->trigger_condition,
                'required_documents' => $m->required_documents ?? [],
                'status' => $m->status,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function milestoneToApi(PaymentMilestone $milestone): array
    {
        return [
            'id' => $milestone->id,
            'milestoneNumber' => $milestone->milestone_number,
            'milestone_number' => $milestone->milestone_number,
            'label' => $milestone->label,
            'percentage' => (float) $milestone->percentage,
            'amount' => $milestone->amount !== null ? (float) $milestone->amount : null,
            'triggerCondition' => $milestone->trigger_condition,
            'trigger_condition' => $milestone->trigger_condition,
            'triggerLabel' => $this->triggerConditionLabel($milestone->trigger_condition),
            'requiredDocuments' => $milestone->required_documents ?? [],
            'required_documents' => $milestone->required_documents ?? [],
            'status' => $milestone->status,
            'paidAmount' => (float) $milestone->paid_amount,
            'paidAt' => $milestone->paid_at?->toIso8601String(),
            'financeApReference' => $milestone->finance_ap_reference,
            'predecessorMilestoneId' => $milestone->predecessor_milestone_id,
        ];
    }

    private function syncRfqPaymentTermsSummary(MRF $mrf, PaymentSchedule $schedule): void
    {
        $summary = $this->summaryText($schedule);

        RFQ::query()
            ->where('mrf_id', $mrf->id)
            ->update(['payment_terms' => $summary]);
    }

    private function templateDisplayName(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $template = config("payment_term_templates.{$key}");

        return is_array($template) ? ($template['name'] ?? $key) : $key;
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $symbol = strtoupper($currency) === 'NGN' ? '₦' : $currency . ' ';

        return $symbol . number_format($amount, 2, '.', ',');
    }
}
