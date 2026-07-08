<?php

namespace App\Services;

use App\Models\Logistics\Trip;
use App\Models\MRF;
use App\Models\SRF;
use App\Models\VendorRegistration;
use App\Support\TableColumnCache;
use App\Support\TripDisplayStatus;
use Illuminate\Support\Collection;

class RoleDashboardQueueService
{
  private const SRF_SCD_LIST_COLUMNS = [
    'id', 'srf_id', 'formatted_id', 'title', 'service_type', 'description', 'justification',
    'duration', 'department', 'requester_id', 'requester_name', 'current_stage',
    'urgency', 'estimated_cost', 'created_at', 'date',
  ];

  private const MRF_QUEUE_LIST_COLUMNS = [
    'id', 'mrf_id', 'formatted_id', 'title', 'category', 'urgency', 'requester_name',
    'estimated_cost', 'created_at', 'workflow_state', 'current_stage', 'first_approval_by_role',
  ];

  private const TRIP_QUEUE_LIST_COLUMNS = [
    'id', 'trip_code', 'title', 'purpose', 'origin', 'destination', 'workflow_stage', 'status',
    'approval_status', 'scheduled_departure_at', 'scheduled_arrival_at', 'booking_scope',
    'created_at', 'created_by', 'po_number', 'unsigned_po_url', 'signed_po_url',
  ];

  /**
   * @return array<string, int>
   */
  public function scdPendingCounts(): array
  {
    return DashboardStatsCache::remember('dashboard.supply_chain_director.queues.counts', function (): array {
      return [
        'pending_vendor_registrations' => $this->pendingVendorRegistrationsQuery()->count(),
        'pending_mrf_scd_first_approval' => $this->pendingParallelFirstApprovalQuery()->count(),
        'pending_srf_scd_approval' => $this->pendingSrfScdApprovalQuery()->count(),
        'pending_trip_approvals' => $this->pendingTripScdApprovalQuery()->count(),
        'pending_purchase_orders' => $this->pendingTripPoSignatureQuery()->count(),
      ];
    });
  }

  /**
   * @return array<string, int>
   */
  public function executivePendingCounts(): array
  {
    return DashboardStatsCache::remember('dashboard.executive.queues.counts', function (): array {
      return [
        'pending_mrf_executive_first_approval' => $this->pendingParallelFirstApprovalQuery()->count(),
      ];
    });
  }

  /**
   * @return Collection<int, array<string, mixed>>
   */
  public function pendingVendorRegistrationItems(int $limit): Collection
  {
    return $this->pendingVendorRegistrationsQuery()
      ->orderByDesc('created_at')
      ->limit($limit)
      ->get(['id', 'company_name', 'category', 'email', 'contact_person', 'status', 'created_at'])
      ->map(static fn (VendorRegistration $reg): array => [
        'id' => $reg->id,
        'companyName' => $reg->company_name,
        'category' => $reg->category,
        'email' => $reg->email,
        'contactPerson' => $reg->contact_person,
        'createdAt' => $reg->created_at?->toIso8601String(),
        'status' => $reg->status,
      ]);
  }

  /**
   * @return Collection<int, array<string, mixed>>
   */
  public function pendingMrfParallelFirstApprovalItems(int $limit): Collection
  {
    return $this->pendingParallelFirstApprovalQuery()
      ->orderByDesc('created_at')
      ->limit($limit)
      ->get()
      ->map(fn (MRF $mrf): array => $this->mapMrfQueueItem($mrf));
  }

  /**
   * @return Collection<int, array<string, mixed>>
   */
  public function pendingSrfScdApprovalItems(int $limit): Collection
  {
    return $this->pendingSrfScdApprovalQuery()
      ->with(['requester:id,name,email'])
      ->orderByDesc('created_at')
      ->limit($limit)
      ->get()
      ->map(fn (SRF $srf): array => $this->mapSrfScdItem($srf));
  }

  /**
   * @return Collection<int, array<string, mixed>>
   */
  public function pendingTripScdApprovalItems(int $limit): Collection
  {
    return $this->pendingTripScdApprovalQuery()
      ->with(['creator:id,name,email,department'])
      ->orderByDesc('created_at')
      ->limit($limit)
      ->get()
      ->map(fn (Trip $trip): array => $this->mapTripScdApprovalItem($trip));
  }

  /**
   * @return Collection<int, array<string, mixed>>
   */
  public function pendingTripPoSignatureItems(int $limit): Collection
  {
    return $this->pendingTripPoSignatureQuery()
      ->with(['creator:id,name,email,department'])
      ->orderByDesc('created_at')
      ->limit($limit)
      ->get()
      ->map(fn (Trip $trip): array => $this->mapTripPoSignatureItem($trip));
  }

  /**
   * @return Collection<int, array<string, mixed>>
   */
  public function recentVendorRegistrationItems(int $limit = 20): Collection
  {
    return DashboardStatsCache::remember(
      'dashboard.supply_chain_director.recent_registrations',
      fn (): Collection => VendorRegistration::query()
        ->select(['id', 'company_name', 'category', 'status', 'approved_by', 'approved_at', 'vendor_id', 'created_at'])
        ->with(['vendor:id,name', 'approver:id,name'])
        ->orderByDesc('created_at')
        ->limit($limit)
        ->get()
        ->map(static fn (VendorRegistration $reg): array => [
          'id' => $reg->id,
          'companyName' => $reg->company_name,
          'category' => $reg->category,
          'status' => $reg->status,
          'approvedBy' => $reg->approver?->name,
          'approvedAt' => $reg->approved_at?->toIso8601String(),
          'createdAt' => $reg->created_at?->toIso8601String(),
        ]),
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function mapMrfQueueItem(MRF $mrf): array
  {
    return [
      'id' => $mrf->id,
      'mrfId' => $mrf->mrf_id,
      'formattedId' => $mrf->formatted_id,
      'title' => $mrf->title,
      'category' => $mrf->category,
      'urgency' => $mrf->urgency,
      'requesterName' => $mrf->requester_name,
      'estimatedCost' => $mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null,
      'workflowState' => $mrf->workflow_state,
      'currentStage' => $mrf->current_stage,
      'firstApprovalByRole' => $mrf->first_approval_by_role,
      'createdAt' => $mrf->created_at?->toIso8601String(),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function mapSrfScdItem(SRF $srf): array
  {
    $requesterName = $srf->requester_name
      ?: ($srf->relationLoaded('requester') && $srf->requester ? $srf->requester->name : null);

    return [
      'id' => $srf->id,
      'srfId' => $srf->srf_id,
      'formattedId' => $srf->formatted_id,
      'title' => $srf->title,
      'serviceType' => $srf->service_type,
      'service_type' => $srf->service_type,
      'description' => $srf->description,
      'justification' => $srf->justification,
      'duration' => $srf->duration,
      'department' => $srf->department,
      'requesterName' => $requesterName,
      'requester_name' => $requesterName,
      'requester' => [
        'id' => (int) $srf->requester_id,
        'name' => $requesterName,
        'email' => $srf->requester?->email,
      ],
      'currentStage' => $srf->current_stage,
      'current_stage' => $srf->current_stage,
      'urgency' => $srf->urgency,
      'estimatedCost' => $srf->estimated_cost !== null ? (float) $srf->estimated_cost : null,
      'estimated_cost' => $srf->estimated_cost !== null ? (float) $srf->estimated_cost : null,
      'createdAt' => $srf->created_at->toIso8601String(),
      'submittedDate' => $srf->date ? $srf->date->format('Y-m-d') : null,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function mapTripScdApprovalItem(Trip $trip): array
  {
    $requester = $trip->relationLoaded('creator') ? $trip->creator : null;
    $displayStatus = TripDisplayStatus::resolve($trip);

    return [
      'id' => $trip->id,
      'tripCode' => $trip->trip_code,
      'trip_code' => $trip->trip_code,
      'title' => $trip->title,
      'purpose' => $trip->purpose,
      'origin' => $trip->origin,
      'destination' => $trip->destination,
      'requesterName' => $requester?->name,
      'requester_name' => $requester?->name,
      'requesterDepartment' => $requester?->department,
      'requester_department' => $requester?->department,
      'requester' => $requester ? [
        'id' => $requester->id,
        'name' => $requester->name,
        'email' => $requester->email,
        'department' => $requester->department,
      ] : null,
      'workflowStage' => $trip->workflow_stage,
      'workflow_stage' => $trip->workflow_stage,
      'workflowStageLabel' => 'Pending Director Approval',
      'workflow_stage_label' => 'Pending Director Approval',
      'status' => $trip->status,
      'approvalStatus' => $trip->approval_status,
      'approval_status' => $trip->approval_status,
      'displayStatus' => $displayStatus,
      'display_status' => $displayStatus,
      'displayStatusLabel' => TripDisplayStatus::label($displayStatus),
      'display_status_label' => TripDisplayStatus::label($displayStatus),
      'scheduledDepartureAt' => $trip->scheduled_departure_at?->toIso8601String(),
      'scheduled_departure_at' => $trip->scheduled_departure_at?->toIso8601String(),
      'scheduledArrivalAt' => $trip->scheduled_arrival_at?->toIso8601String(),
      'scheduled_arrival_at' => $trip->scheduled_arrival_at?->toIso8601String(),
      'bookingScope' => $trip->booking_scope,
      'booking_scope' => $trip->booking_scope,
      'createdAt' => $trip->created_at?->toIso8601String(),
      'created_at' => $trip->created_at?->toIso8601String(),
      'detailPath' => '/api/trip-requests/'.$trip->id,
      'availableActions' => ['director_approve', 'director_reject', 'director_return'],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function mapTripPoSignatureItem(Trip $trip): array
  {
    $requester = $trip->relationLoaded('creator') ? $trip->creator : null;

    return [
      'id' => $trip->id,
      'tripCode' => $trip->trip_code,
      'trip_code' => $trip->trip_code,
      'title' => $trip->title,
      'purpose' => $trip->purpose,
      'origin' => $trip->origin,
      'destination' => $trip->destination,
      'requesterName' => $requester?->name,
      'requester_name' => $requester?->name,
      'requesterDepartment' => $requester?->department,
      'requester_department' => $requester?->department,
      'workflowStage' => $trip->workflow_stage,
      'workflow_stage' => $trip->workflow_stage,
      'status' => $trip->status,
      'approvalStatus' => $trip->approval_status,
      'approval_status' => $trip->approval_status,
      'poNumber' => $trip->po_number,
      'unsignedPoUrl' => $trip->unsigned_po_url,
      'unsigned_po_url' => $trip->unsigned_po_url,
      'signedPoUrl' => $trip->signed_po_url,
      'signed_po_url' => $trip->signed_po_url,
      'createdAt' => $trip->created_at?->toIso8601String(),
      'created_at' => $trip->created_at?->toIso8601String(),
    ];
  }

  private function pendingVendorRegistrationsQuery()
  {
    return VendorRegistration::query()->where('status', VendorRegistration::STATUS_PENDING);
  }

    private function pendingParallelFirstApprovalQuery()
    {
        return MRF::query()
            ->select($this->mrfQueueSelect())
            ->pendingParallelFirstApproval();
    }

  private function pendingSrfScdApprovalQuery()
  {
    return SRF::query()
      ->select(TableColumnCache::filterExisting('s_r_f_s', self::SRF_SCD_LIST_COLUMNS))
      ->where('status', 'Pending')
      ->where('current_stage', 'supply_chain_director_review');
  }

  private function pendingTripScdApprovalQuery()
  {
    return Trip::query()
      ->select(self::TRIP_QUEUE_LIST_COLUMNS)
      ->tripRequests()
      ->where('workflow_stage', Trip::WORKFLOW_SCD_APPROVAL)
      ->where('status', Trip::STATUS_SUBMITTED);
  }

  private function pendingTripPoSignatureQuery()
  {
    return Trip::query()
      ->select(self::TRIP_QUEUE_LIST_COLUMNS)
      ->tripRequests()
      ->where('workflow_stage', Trip::WORKFLOW_PO_PENDING_SIGN)
      ->whereNotNull('unsigned_po_url')
      ->where(function ($query): void {
        $query->whereNull('signed_po_url')
          ->orWhere('signed_po_url', '=', '');
      });
  }

  /**
   * @return list<string>
   */
  private function mrfQueueSelect(): array
  {
    return array_values(array_intersect(
      self::MRF_QUEUE_LIST_COLUMNS,
      MRF::resolveListApiSelect(),
    ));
  }
}
