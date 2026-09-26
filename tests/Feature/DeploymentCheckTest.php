<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeploymentCheckTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_local_configuration_fails_without_disclosing_secrets(): void
    {
        $this->artisan('app:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"status":"failed"')
            ->assertFailed();
    }

    public function test_complete_production_configuration_passes(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://awb.example.test',
            'session.secure' => true,
            'session.encrypt' => true,
        ]);
        User::factory()->create();
        Client::factory()->create(['active' => true]);
        $batch = ImportBatch::factory()->create(['status' => 'COMMITTED']);
        DB::table('active_datasets')->where('id', 1)->update([
            'active_batch_id' => $batch->id,
            'updated_at' => now(),
        ]);

        $this->artisan('app:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"status":"ready"')
            ->assertSuccessful();
    }
}
