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
            ->select(['id', 'name', 'email', 'phone', 'department', 'role'])
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%' . $request->get('q') . '%';
                $q->where(function ($b) use ($term): void {
                    $b->where('name', 'like', $term)->orWhere('email', 'like', $term);
                });
            })
            ->paginate(50);

        return response()->json([
            'success' => true,
            'users' => $users->items(),
            'pagination' => [
                'currentPage' => $users->currentPage(),
                'lastPage' => $users->lastPage(),
                'perPage' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }
}
