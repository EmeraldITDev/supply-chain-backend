<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\DepartmentMatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SetDesignatedRequisitionCreator implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $targetUserId,
        private readonly string $departmentLabel,
    ) {}

    public function handle(): void
    {
        $memberIds = DepartmentMatcher::matchingUserIds($this->departmentLabel);

        DB::transaction(function () use ($memberIds): void {
            if ($memberIds !== []) {
                DB::table('users')
                    ->whereIn('id', $memberIds)
                    ->where('id', '!=', $this->targetUserId)
                    ->where('designated_requisition_creator', true)
                    ->update([
                        'designated_requisition_creator' => false,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('users')
                ->where('id', $this->targetUserId)
                ->update([
                    'designated_requisition_creator' => true,
                    'updated_at' => now(),
                ]);
        });
    }
}
