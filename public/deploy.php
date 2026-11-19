<?php

/**
 * Server half of the GitHub Actions deploy (.github/workflows/deploy.yml).
 *
 * cPanel has no SSH, so the workflow uploads the whole app as one file,
 * .deploy/release.zip, over FTP and then calls this script twice:
 *
 *   POST /deploy.php?step=extract  unpack the release over the app, then delete
 *                                  files that were in the previous release but
 *                                  are not in this one
 *   POST /deploy.php?step=migrate  clear Laravel's caches and run migrate --force
 *
 * .env, storage/ and .deploy/ are never written or deleted. The extract step
 * does not load Laravel, so a broken release can always be replaced by the next.
 * While the files are swapped in (a few seconds) the app answers 503 "updating"
 * (maintenance mode), unless it was already put down with php artisan down.
 *
 * Every request needs the header X-Deploy-Token equal to DEPLOY_TOKEN in the
 * app's .env (at least 32 characters). Otherwise the answer is a plain 404.
 */

declare(strict_types=1);

$base = dirname(__DIR__);
$deployDir = $base.'/.deploy';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ! tokenIsValid($base)) {
    respond(404, ['message' => 'Not Found']);
}

@set_time_limit(900);
ignore_user_abort(true);

if (! is_dir($deployDir) && ! @mkdir($deployDir, 0755, true)) {
    respond(500, ['ok' => false, 'error' => 'Cannot create .deploy/']);
}

// One deploy at a time.
$lock = fopen($deployDir.'/deploy.lock', 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    respond(409, ['ok' => false, 'error' => 'Another deploy is running']);
}

match ($_GET['step'] ?? '') {
    'extract' => extractRelease($base, $deployDir),
    'migrate' => migrate($base),
    default => respond(400, ['ok' => false, 'error' => 'Use ?step=extract or ?step=migrate']),
};

function extractRelease(string $base, string $deployDir): never
{
    $zipPath = $deployDir.'/release.zip';
    if (! is_file($zipPath)) {
        respond(400, ['ok' => false, 'error' => 'No .deploy/release.zip on the server']);
    }

    $started = microtime(true);
    $staging = $deployDir.'/staging';
    removeTree($staging);
    mkdir($staging, 0755, true);

    // 1. Unpack into a staging folder; the live app is untouched so far.
    try {
        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true || ! $zip->extractTo($staging)) {
                throw new RuntimeException('release.zip could not be unpacked (incomplete upload?)');
            }
            $zip->close();
        } else {
            (new PharData($zipPath))->extractTo($staging, null, true);
        }
    } catch (Throwable $e) {
        removeTree($staging);
        respond(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    $files = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        if ($item->isFile()) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($staging) + 1));
            if (! isProtected($relative)) {
                $files[] = $relative;
            }
        }
    }
    sort($files);

    if (! in_array('artisan', $files, true) || ! in_array('vendor/autoload.php', $files, true)) {
        removeTree($staging);
        respond(500, ['ok' => false, 'error' => 'release.zip has no artisan or vendor/autoload.php; nothing was changed']);
    }

    // While old and new files are mixed (a few seconds), visitors get a short
    // 503 "updating" answer instead of a possible error.
    $maintenance = maintenanceOn($base);
    $swapStarted = microtime(true);

    // 2. Move each file into place. rename() replaces a file in one step, so a
    //    visitor never gets a half-written file.
    $errors = [];
    foreach ($files as $relative) {
        $target = $base.'/'.$relative;
        $dir = dirname($target);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
            $errors[] = "mkdir failed: {$relative}";
            continue;
        }
        if (! @rename($staging.'/'.$relative, $target)) {
            $errors[] = "rename failed: {$relative}";
        }
    }

    // 3. Delete files the previous release had but this one does not.
    $manifestPath = $deployDir.'/manifest.json';
    $previous = is_file($manifestPath) ? (json_decode((string) file_get_contents($manifestPath), true) ?: []) : [];
    $removed = [];
    foreach (array_diff($previous, $files) as $relative) {
        if (! is_string($relative) || isProtected($relative) || str_contains($relative, '..')) {
            continue;
        }
        $target = $base.'/'.$relative;
        if (is_file($target) && @unlink($target)) {
            $removed[] = $relative;
            for ($dir = dirname($target); $dir !== $base && @rmdir($dir); $dir = dirname($dir)) {
                // Drop folders this left empty.
            }
        }
    }

    file_put_contents($manifestPath, json_encode($files, JSON_UNESCAPED_SLASHES));
    removeTree($staging);
    @unlink($zipPath);

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    if ($maintenance) {
        maintenanceOff($base);
    }

    respond($errors ? 500 : 200, [
        'ok' => ! $errors,
        'maintenance_seconds' => $maintenance ? round(microtime(true) - $swapStarted, 1) : 'skipped: the app was already in maintenance mode',
        'files' => count($files),
        'removed' => $removed,
        'errors' => array_slice($errors, 0, 50),
        'seconds' => round(microtime(true) - $started, 1),
    ]);
}

function migrate(string $base): never
{
    // The same as config:clear, route:clear and event:clear, done before
    // Laravel boots so a stale cache from the old release is never loaded.
    foreach (array_merge(['config.php', 'events.php'], array_map('basename', glob($base.'/bootstrap/cache/routes*.php') ?: [])) as $cached) {
        @unlink($base.'/bootstrap/cache/'.$cached);
    }

    $output = [];
    try {
        require $base.'/vendor/autoload.php';
        $app = require $base.'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        foreach ([['view:clear', []], ['migrate', ['--force' => true]]] as [$command, $arguments]) {
            $exitCode = $kernel->call($command, $arguments);
            $output[$command] = trim($kernel->output());
            if ($exitCode !== 0) {
                respond(500, ['ok' => false, 'failed' => $command, 'output' => $output]);
            }
        }
    } catch (Throwable $e) {
        respond(500, ['ok' => false, 'error' => $e->getMessage(), 'output' => $output]);
    }

    respond(200, ['ok' => true, 'output' => $output]);
}

/**
 * Turn on maintenance mode through public/index.php's first check
 * (storage/framework/maintenance.php), which runs before Laravel loads, so it
 * works even while vendor/ is half old and half new. Returns false, and leaves
 * things alone, when the app is already down (php artisan down).
 */
function maintenanceOn(string $base): bool
{
    $file = $base.'/storage/framework/maintenance.php';
    if (is_file($file) || ! is_dir(dirname($file))) {
        return false;
    }

    $page = <<<'PHP'
<?php
// Written by public/deploy.php while a release is put in place, removed a few
// seconds later. If a deploy ever dies halfway, it stops itself after 5 minutes.
if (time() - (int) @filemtime(__FILE__) > 300) {
    @unlink(__FILE__);

    return;
}
http_response_code(503);
header('Retry-After: 5');
header('Cache-Control: no-store');
header('Content-Type: application/json');
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: '.$_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
}
echo '{"message":"The service is being updated. Please try again in a few seconds."}';
exit;
PHP;

    return file_put_contents($file, $page) !== false;
}

function maintenanceOff(string $base): void
{
    @unlink($base.'/storage/framework/maintenance.php');
}

function tokenIsValid(string $base): bool
{
    $env = @file_get_contents($base.'/.env');
    if ($env === false || ! preg_match('/^DEPLOY_TOKEN\s*=\s*["\']?([^"\'\s#]+)/m', $env, $m)) {
        return false;
    }
    $given = (string) ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '');

    return strlen($m[1]) >= 32 && hash_equals($m[1], $given);
}

/** Paths a release may never write or delete. */
function isProtected(string $relative): bool
{
    return $relative === '.env'
        || str_starts_with($relative, '.env.')
        || str_starts_with($relative, 'storage/')
        || str_starts_with($relative, '.deploy/')
        || str_starts_with($relative, '.well-known/')
        || str_starts_with($relative, 'public/storage/');
}

function removeTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit;
}
