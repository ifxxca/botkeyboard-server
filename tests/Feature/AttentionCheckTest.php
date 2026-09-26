<?php

namespace Tests\Feature;

use App\Domain\Attention\AttentionLookup;
use App\Models\AttentionItem;
use App\Models\Client;
use App\Models\ImportBatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AttentionCheckTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN = 'aim_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_valid_client_gets_all_active_reasons_for_exact_leading_zero_awb_without_lookup_writes(): void
    {
        $client = $this->client();
        $batch = ImportBatch::factory()->create();
        DB::table('active_datasets')->where('id', 1)->update(['active_batch_id' => $batch->id]);
        AttentionItem::factory()->create(['import_batch_id' => $batch->id, 'awb' => '0201234567890', 'reason_code' => 'VIP', 'severity' => 'warning', 'declared_value' => null]);
        AttentionItem::factory()->create(['import_batch_id' => $batch->id, 'awb' => '0201234567890']);
        AttentionItem::factory()->create(['import_batch_id' => $batch->id, 'awb' => '0201234567890', 'reason_code' => 'HOLD', 'active' => false]);

        $response = $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/v1/attention/check', ['awb' => ' 0201234567890 ']);

        $response->assertOk()->assertJsonPath('matched', true)
            ->assertJsonCount(2, 'alerts')
            ->assertJsonPath('alerts.0.reason', 'HIGH_VALUE')
            ->assertJsonPath('alerts.0.declared_value', 4250000)
            ->assertJsonPath('alerts.1.reason', 'VIP');
        $this->assertNull($client->fresh()->last_authenticated_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_unmatched_or_expired_attention_returns_only_matched_false(): void
    {
        $this->client();
        $batch = ImportBatch::factory()->create();
        DB::table('active_datasets')->where('id', 1)->update(['active_batch_id' => $batch->id]);
        AttentionItem::factory()->create(['import_batch_id' => $batch->id, 'valid_until' => now()->subMinute()]);

        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertOk()->assertExactJson(['matched' => false]);
    }

    public function test_missing_or_revoked_token_returns_401_and_inactive_client_returns_403(): void
    {
        $client = $this->client();

        $this->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->withHeader('Authorization', 'Bearer aim_'.str_repeat('b', 64))
            ->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $client->update(['active' => false]);
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertForbidden()->assertJsonPath('error.code', 'CLIENT_INACTIVE');
    }

    public function test_invalid_awb_and_malformed_body_have_stable_error_codes(): void
    {
        $this->client();

        foreach (['0201234', "02012345\n", 1234567890123] as $awb) {
            $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
                ->postJson('/api/v1/attention/check', ['awb' => $awb])
                ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_AWB');
        }

        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->call('POST', '/api/v1/attention/check', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ], '{bad')
            ->assertBadRequest()->assertJsonPath('error.code', 'INVALID_REQUEST');
    }

    public function test_per_client_rate_limit_returns_429_without_lookup_write(): void
    {
        $this->client();
        config(['attention.client_checks_per_minute' => 2]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
                ->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])->assertOk();
        }

        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_internal_lookup_error_does_not_expose_exception_details(): void
    {
        $this->client();
        $this->app->bind(AttentionLookup::class, fn () => new class extends AttentionLookup
        {
            public function check(string $awb): array
            {
                throw new \RuntimeException('secret internal database detail');
            }
        });

        $response = $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/v1/attention/check', ['awb' => '0201234567890']);

        $response->assertStatus(500)->assertExactJson([
            'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Internal server error.'],
        ]);
        $response->assertDontSee('secret internal database detail');
    }

    public function test_machine_api_does_not_grant_cross_origin_browser_access(): void
    {
        $this->client();

        $this->withHeader('Origin', 'https://untrusted.example')
            ->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_database_lookup_failure_returns_503_and_logs_no_awb_or_sql(): void
    {
        $this->client();
        Log::spy();
        $this->app->bind(AttentionLookup::class, fn () => new class extends AttentionLookup
        {
            public function check(string $awb): array
            {
                throw new QueryException('mysql', 'SELECT private_awb FROM attention_items', [$awb], new \PDOException('database internal detail'));
            }
        });

        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertStatus(503)->assertExactJson([
                'error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Service unavailable.'],
            ]);
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Attention lookup database failure'
                && ! str_contains(json_encode($context), '0201234567890')
                && ! str_contains(json_encode($context), 'SELECT');
        });
    }

    private function client(): Client
    {
        return Client::factory()->create(['token_hash' => hash('sha256', self::TOKEN)]);
    }
}
