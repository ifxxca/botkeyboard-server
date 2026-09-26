<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

#[Signature('app:deployment-check {--json : Write machine-readable JSON}')]
#[Description('Verify production configuration, storage, database schema, and pilot prerequisites')]
class DeploymentCheck extends Command
{
    /** @var list<array{name: string, status: string, detail: string}> */
    private array $checks = [];

    public function handle(): int
    {
        $this->record('environment', config('app.env') === 'production',
            'APP_ENV must be production.', (string) config('app.env'));
        $this->record('debug_disabled', config('app.debug') === false,
            'APP_DEBUG must be false.', config('app.debug') ? 'enabled' : 'disabled');
        $key = (string) config('app.key');
        $this->record('application_key', $this->validApplicationKey($key),
            'APP_KEY must be generated.', $key === '' ? 'missing' : 'configured');
        $url = (string) config('app.url');
        $this->record('https_url', $this->validHttpsOrigin($url),
            'APP_URL must use HTTPS.', $url === '' ? 'missing' : (parse_url($url, PHP_URL_HOST) ?: 'invalid'));
        $this->record('secure_session_cookie', config('session.secure') === true,
            'SESSION_SECURE_COOKIE must be true.', config('session.secure') ? 'enabled' : 'disabled');
        $this->record('encrypted_session', config('session.encrypt') === true,
            'SESSION_ENCRYPT must be true.', config('session.encrypt') ? 'enabled' : 'disabled');
        $this->record('mysql_driver', config('database.default') === 'mysql',
            'DB_CONNECTION must be mysql.', (string) config('database.default'));

        $databaseReady = false;
        try {
            $pdo = DB::connection()->getPdo();
            $databaseReady = true;
            $this->checks[] = ['name' => 'database_connection', 'status' => 'PASS', 'detail' => 'connected'];
            $serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $isMySqlEight = ! str_contains(strtolower($serverVersion), 'mariadb') &&
                version_compare(preg_replace('/[^0-9.].*/', '', $serverVersion) ?: '0', '8.0', '>=');
            $this->record('mysql_version', $isMySqlEight,
                'MySQL 8 or newer is required.', $serverVersion);
        } catch (Throwable $exception) {
            $this->checks[] = ['name' => 'database_connection', 'status' => 'FAIL', 'detail' => $exception::class];
        }

        $requiredTables = ['users', 'clients', 'import_batches', 'active_datasets', 'attention_items', 'audit_events'];
        if ($databaseReady) {
            $missing = array_values(array_filter($requiredTables, fn (string $table): bool => ! Schema::hasTable($table)));
            $this->record('database_schema', $missing === [], 'Run migrations before deployment.',
                $missing === [] ? 'complete' : 'missing: '.implode(', ', $missing));

            if ($missing === []) {
                $hasActiveBatch = DB::table('active_datasets')->where('id', 1)->whereNotNull('active_batch_id')->exists();
                $this->record('active_dataset', $hasActiveBatch,
                    'Commit an import before pilot traffic.', $hasActiveBatch ? 'committed' : 'missing');
                $adminCount = User::query()->count();
                $this->record('admin_account', $adminCount > 0,
                    'Create an admin account before deployment.', (string) $adminCount);
                $activeClientCount = Client::query()->where('active', true)->count();
                $this->checks[] = [
                    'name' => 'active_clients',
                    'status' => $activeClientCount > 0 ? 'PASS' : 'WARN',
                    'detail' => (string) $activeClientCount,
                ];
            }
        }

        foreach ([storage_path('framework'), storage_path('logs')] as $path) {
            $this->record('writable_'.basename($path), is_dir($path) && is_writable($path),
                'Laravel runtime directory must be writable.', $path);
        }

        $failed = count(array_filter($this->checks, fn (array $check): bool => $check['status'] === 'FAIL'));
        $warnings = count(array_filter($this->checks, fn (array $check): bool => $check['status'] === 'WARN'));
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $failed === 0 ? 'ready' : 'failed',
                'summary' => ['passed' => count($this->checks) - $failed - $warnings, 'warnings' => $warnings, 'failed' => $failed],
                'checks' => $this->checks,
            ], JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Status', 'Detail'], array_map(
                fn (array $check): array => [$check['name'], $check['status'], $check['detail']],
                $this->checks,
            ));
            $failed === 0
                ? $this->info('Deployment preflight passed.')
                : $this->error("Deployment preflight failed with {$failed} blocking check(s).");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function record(string $name, bool $passes, string $failure, string $success): void
    {
        $this->checks[] = [
            'name' => $name,
            'status' => $passes ? 'PASS' : 'FAIL',
            'detail' => $passes ? $success : $failure,
        ];
    }

    private function validApplicationKey(string $key): bool
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return is_string($decoded) && strlen($decoded) === 32;
        }

        return strlen($key) === 32;
    }

    private function validHttpsOrigin(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' &&
            isset($parts['host']) && ! array_key_exists('user', $parts) && ! array_key_exists('pass', $parts) &&
            ! array_key_exists('query', $parts) && ! array_key_exists('fragment', $parts) &&
            in_array($parts['path'] ?? '', ['', '/'], true);
    }
}
