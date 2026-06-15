<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PassengerEligibility;
use Illuminate\Http\Request;

class EligiblePassengersController extends Controller
{
    public function index(Request $request)
    {
        $users = PassengerEligibility::eligibleUsersQuery()
            ->select(['id', 'name', 'email', 'phone', 'department', 'supply_chain_role'])
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%' . $request->get('q') . '%';
                $q->where(function ($b) use ($term): void {
                    $b->where('name', 'like', $term)->orWhere('email', 'like', $term);
                });
            })
            ->paginate(50);

        $items = collect($users->items())->map(function ($user) {
            $row = $user->toArray();
            $row['role'] = $user->supply_chain_role ?? $user->scmRole();

            return $row;
        })->all();

        return response()->json([
            'success' => true,
            'users' => $items,
            'pagination' => [
                'currentPage' => $users->currentPage(),
                'lastPage' => $users->lastPage(),
                'perPage' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }
}
