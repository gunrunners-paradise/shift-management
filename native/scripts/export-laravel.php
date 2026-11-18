<?php

// Operator-only snapshot. Run in the fork with its existing Laravel environment.
// No HTTP route, no output of records or passwords, no changes to source tables.
declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

umask(0077);
if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: php native/scripts/export-laravel.php NEW_FILE [--testing]\n");
    exit(1);
}
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
if (($argv[2] ?? '') === '--testing') {
    $app->loadEnvironmentFrom('.env.testing');
}
$app->make(Kernel::class)->bootstrap();
$handle = fopen($argv[1], 'x');
if (! $handle) {
    exit(1);
}
try {
    $snapshot = DB::transaction(function () {
        $out = ['format' => 'shift-management-laravel-v1', 'created_at' => gmdate('c'), 'tables' => [], 'avatars' => []];
        foreach (['users', 'shifts', 'blocked_dates', 'desks', 'desk_reservations', 'calendar_events', 'monthly_receipts', 'signup_settings', 'pending_signup_requests'] as $table) {
            $out['tables'][$table] = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        }
        $storage = realpath(storage_path('app/private'));
        foreach ($out['tables']['users'] as $user) {
            if (empty($user['photo_path'])) {
                continue;
            }
            $path = realpath(storage_path('app/private/'.$user['photo_path']));
            if (! $storage || ! $path || ! str_starts_with($path, $storage.DIRECTORY_SEPARATOR) || ! is_file($path)) {
                throw new RuntimeException('Unavailable avatar');
            }
            $info = getimagesize($path);
            if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true) || filesize($path) > 2097152 || max($info[0], $info[1]) > 2048) {
                throw new RuntimeException('Invalid avatar');
            }
            $out['avatars'][] = ['user_id' => $user['id'], 'type' => $info['mime'], 'base64' => base64_encode(file_get_contents($path))];
        }

        return $out;
    });
    $data = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (fwrite($handle, $data) !== strlen($data)) {
        throw new RuntimeException('Incomplete write');
    }
    fclose($handle);
    fwrite(STDOUT, "Private snapshot written. Source database unchanged.\n");
} catch (Throwable $e) {
    fclose($handle);
    fwrite(STDERR, "Export failed; partial destination retained. Do not import it.\n");
    exit(1);
}
