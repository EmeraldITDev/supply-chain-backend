<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LazarusNotificationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LazarusNotificationSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if (! LazarusNotificationSettings::canManage($request->user())) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data' => $this->payload(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! LazarusNotificationSettings::canManage($user)) {
            return $this->forbidden();
        }

        $input = $request->input('toggles', $request->all());
        if (! is_array($input)) {
            return response()->json([
                'success' => false,
                'error' => 'Send each notification switch as true or false.',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $toggles = LazarusNotificationSettings::save($input);

        Log::info('Lazarus notification settings updated', [
            'user_id' => $user?->id,
            'toggles' => collect($toggles)->mapWithKeys(fn (array $toggle) => [
                $toggle['key'] => $toggle['enabled'],
            ])->all(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lazarus email settings saved.',
            'data' => [
                'email' => LazarusNotificationSettings::EMAIL,
                'toggles' => $toggles,
            ],
        ]);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'Only Supply Chain or Procurement can change these email settings.',
            'code' => 'FORBIDDEN',
        ], 403);
    }

    /**
     * @return array{email: string, toggles: list<array{key: string, label: string, description: string, enabled: bool}>}
     */
    private function payload(): array
    {
        return [
            'email' => LazarusNotificationSettings::EMAIL,
            'toggles' => LazarusNotificationSettings::all(),
        ];
    }
}
