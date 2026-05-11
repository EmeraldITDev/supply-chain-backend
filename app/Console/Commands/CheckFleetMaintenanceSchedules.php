<?php

namespace App\Console\Commands;

use App\Models\Logistics\Vehicle;
use App\Models\Logistics\VehicleMaintenance;
use App\Notifications\VehicleMaintenanceOverdueNotification;
use App\Notifications\VehicleMaintenanceUpcomingNotification;
use App\Services\Logistics\FleetComplianceNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckFleetMaintenanceSchedules extends Command
{
    protected $signature = 'fleet:check-maintenance';

    protected $description = 'Mark overdue maintenance, set vehicles inactive, and notify logistics staff';

    public function handle(FleetComplianceNotificationService $notifySvc): int
    {
        $today = Carbon::today();
        $week = $notifySvc->weekPeriodKey();

        foreach (
            VehicleMaintenance::query()
                ->with('vehicle')
                ->where('status', VehicleMaintenance::STATUS_SCHEDULED)
                ->whereNotNull('next_due_at')
                ->cursor() as $maintenance
        ) {
            $vehicle = $maintenance->vehicle;
            if (!$vehicle instanceof Vehicle) {
                continue;
            }

            $next = Carbon::parse($maintenance->next_due_at)->startOfDay();

            if ($next->lt($today)) {
                $maintenance->update(['status' => VehicleMaintenance::STATUS_OVERDUE]);
                $vehicle->update([
                    'status' => Vehicle::STATUS_INACTIVE,
                    'status_inactive_reason' => Vehicle::INACTIVE_REASON_MAINTENANCE_OVERDUE,
                ]);

                if ($notifySvc->shouldDispatch($maintenance, 'maint_overdue_inactive', $week)) {
                    $daysOverdue = (int) $next->diffInDays($today);
                    $notifySvc->notifyRecipients(
                        new VehicleMaintenanceOverdueNotification($maintenance, max(1, $daysOverdue))
                    );
                }

                continue;
            }

            if ($next->lte($today->copy()->addDays(14)) && $notifySvc->shouldDispatch($maintenance, 'maint_upcoming', $week)) {
                $notifySvc->notifyRecipients(new VehicleMaintenanceUpcomingNotification($maintenance));
            }
        }

        $this->info('Fleet maintenance check complete.');

        return self::SUCCESS;
    }
}
