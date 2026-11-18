<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Last step of the GitHub Actions deploy (.github/workflows/deploy.yml).
 *
 * cPanel has no SSH, so after the new code is uploaded the workflow calls
 * this endpoint to clear Laravel's caches and run `migrate --force`.
 * Without the right X-Deploy-Token header (DEPLOY_TOKEN in .env, at least
 * 32 characters) it answers 404, as if the route did not exist.
 */
class DeployController
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('services.deploy.token');
        $given = (string) $request->header('X-Deploy-Token');

        if (strlen($expected) < 32 || ! hash_equals($expected, $given)) {
            abort(404);
        }

        @set_time_limit(300);

        // Make PHP pick up the files that were just uploaded.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $steps = [
            ['config:clear', []],
            ['route:clear', []],
            ['view:clear', []],
            ['migrate', ['--force' => true]],
        ];

        $output = [];
        foreach ($steps as [$command, $arguments]) {
            try {
                $exitCode = Artisan::call($command, $arguments);
                $output[$command] = trim(Artisan::output());
            } catch (Throwable $e) {
                $exitCode = 1;
                $output[$command] = $e->getMessage();
            }

            if ($exitCode !== 0) {
                return response()->json(['ok' => false, 'failed' => $command, 'output' => $output], 500);
            }
        }

        return response()->json(['ok' => true, 'output' => $output]);
    }
}
