<?php

namespace Tests\Feature;

use App\Models\AttentionItem;
use App\Models\Client;
use App\Models\ImportBatch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttentionAcknowledgementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_A = 'aim_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const TOKEN_B = 'aim_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_two_clients_see_unacknowledged_awb_then_one_ack_suppresses_all_reasons_globally(): void
    {
        $clientA = $this->client(self::TOKEN_A);
        $clientB = $this->client(self::TOKEN_B);
        $batch = $this->activateBatch();
        $highValue = $this->attention($batch, '0201234567890', 'HIGH_VALUE');
        $fragile = $this->attention($batch, '0201234567890', 'FRAGILE', ['severity' => 'warning']);

        $this->withClientToken(self::TOKEN_A)->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertOk()->assertJsonPath('matched', true)->assertJsonCount(2, 'alerts')
            ->assertJsonFragment(['id' => $highValue->public_id])
            ->assertJsonFragment(['id' => $fragile->public_id]);
        $this->withClientToken(self::TOKEN_B)->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertOk()->assertJsonPath('matched', true);

        $this->withClientToken(self::TOKEN_A)->postJson($this->ackUrl($highValue), [
            'method' => 'SCAN', 'client_id' => $clientB->id,
        ])->assertOk()->assertExactJson(['acknowledged' => true, 'already_acknowledged' => false]);

        $this->assertDatabaseCount('attention_acknowledgements', 2);
        $this->assertDatabaseHas('attention_acknowledgements', [
            'attention_item_id' => $highValue->id, 'client_id' => $clientA->id, 'method' => 'SCAN',
        ]);
        $this->assertDatabaseHas('attention_acknowledgements', [
            'attention_item_id' => $fragile->id, 'client_id' => $clientA->id, 'method' => 'SCAN',
        ]);
        $this->assertDatabaseMissing('attention_acknowledgements', ['client_id' => $clientB->id]);

        $this->withClientToken(self::TOKEN_B)->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertOk()->assertExactJson(['matched' => false]);
        $this->withClientToken(self::TOKEN_B)->postJson($this->ackUrl($highValue), ['method' => 'MANUAL_CLOSE'])
            ->assertOk()->assertExactJson(['acknowledged' => true, 'already_acknowledged' => true]);

        $this->assertDatabaseCount('attention_acknowledgements', 2);
        $this->assertDatabaseCount('audit_events', 1);
        $audit = DB::table('audit_events')->where('event', 'ATTENTION_ACKNOWLEDGED')->first();
        $this->assertNotNull($audit);
        $this->assertSame('attention_item', $audit->subject_type);
        $this->assertSame((string) $highValue->id, (string) $audit->subject_id);
        $this->assertStringNotContainsString('0201234567890', (string) $audit->metadata);
    }

    public function test_manual_close_method_is_stored_for_authenticated_client(): void
    {
        $client = $this->client(self::TOKEN_A);
        $batch = $this->activateBatch();
        $item = $this->attention($batch);

        $this->withClientToken(self::TOKEN_A)->postJson($this->ackUrl($item), ['method' => 'MANUAL_CLOSE'])
            ->assertOk()->assertExactJson(['acknowledged' => true, 'already_acknowledged' => false]);

        $this->assertDatabaseHas('attention_acknowledgements', [
            'attention_item_id' => $item->id, 'client_id' => $client->id, 'method' => 'MANUAL_CLOSE',
        ]);
    }

    public function test_inactive_client_cannot_acknowledge_attention(): void
    {
        $client = $this->client(self::TOKEN_A, ['active' => false]);
        $batch = $this->activateBatch();
        $item = $this->attention($batch);

        $this->withClientToken(self::TOKEN_A)->postJson($this->ackUrl($item), ['method' => 'SCAN'])
            ->assertForbidden()->assertJsonPath('error.code', 'CLIENT_INACTIVE');

        $this->assertDatabaseCount('attention_acknowledgements', 0);
        $this->assertDatabaseMissing('audit_events', ['event' => 'ATTENTION_ACKNOWLEDGED']);
        $this->assertFalse($client->fresh()->active);
    }

    public function test_missing_bearer_identity_cannot_acknowledge_attention(): void
    {
        $this->client(self::TOKEN_A);
        $batch = $this->activateBatch();
        $item = $this->attention($batch);

        $this->postJson($this->ackUrl($item), ['method' => 'SCAN'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertDatabaseCount('attention_acknowledgements', 0);
        $this->assertDatabaseMissing('audit_events', ['event' => 'ATTENTION_ACKNOWLEDGED']);
    }

    public function test_invalid_public_id_and_attention_outside_active_snapshot_return_404(): void
    {
        $this->client(self::TOKEN_A);
        $oldBatch = $this->activateBatch();
        $oldItem = $this->attention($oldBatch);
        $activeBatch = $this->activateBatch();
        $this->attention($activeBatch);

        $this->withClientToken(self::TOKEN_A)->postJson('/api/v1/attention/not-a-public-id/acknowledge', ['method' => 'SCAN'])
            ->assertNotFound()->assertJsonPath('error.code', 'ATTENTION_NOT_FOUND');
        $this->withClientToken(self::TOKEN_A)->postJson($this->ackUrl($oldItem), ['method' => 'SCAN'])
            ->assertNotFound()->assertJsonPath('error.code', 'ATTENTION_NOT_FOUND');

        $this->assertDatabaseCount('attention_acknowledgements', 0);
    }

    public function test_new_snapshot_does_not_inherit_old_attention_acknowledgement(): void
    {
        $this->client(self::TOKEN_A);
        $oldBatch = $this->activateBatch();
        $oldItem = $this->attention($oldBatch, '0201234567890');
        $this->withClientToken(self::TOKEN_A)->postJson($this->ackUrl($oldItem), ['method' => 'SCAN'])->assertOk();

        $newBatch = $this->activateBatch();
        $newItem = $this->attention($newBatch, '0201234567890');

        $this->withClientToken(self::TOKEN_A)->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertOk()->assertJsonPath('matched', true)->assertJsonFragment(['id' => $newItem->public_id]);
        $this->withClientToken(self::TOKEN_A)->postJson($this->ackUrl($oldItem), ['method' => 'SCAN'])
            ->assertNotFound()->assertJsonPath('error.code', 'ATTENTION_NOT_FOUND');

        $this->assertDatabaseCount('attention_acknowledgements', 1);
        $this->assertDatabaseMissing('attention_acknowledgements', ['attention_item_id' => $newItem->id]);
    }

    public function test_acknowledgement_rejects_unknown_method_without_writing(): void
    {
        $this->client(self::TOKEN_A);
        $batch = $this->activateBatch();
        $item = $this->attention($batch);

        $this->withClientToken(self::TOKEN_A)->postJson($this->ackUrl($item), ['method' => 'AUTO'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_METHOD');

        $this->assertDatabaseCount('attention_acknowledgements', 0);
        $this->assertDatabaseMissing('audit_events', ['event' => 'ATTENTION_ACKNOWLEDGED']);
    }

    private function client(string $token, array $attributes = []): Client
    {
        return Client::factory()->create([
            'token_hash' => hash('sha256', $token),
            ...$attributes,
        ]);
    }

    private function activateBatch(): ImportBatch
    {
        $batch = ImportBatch::factory()->create();
        DB::table('active_datasets')->where('id', 1)->update(['active_batch_id' => $batch->id]);

        return $batch;
    }

    /** @param array<string, mixed> $attributes */
    private function attention(ImportBatch $batch, string $awb = '0201234567890',
        string $reason = 'HIGH_VALUE', array $attributes = []): AttentionItem
    {
        return AttentionItem::factory()->create([
            'import_batch_id' => $batch->id,
            'awb' => $awb,
            'reason_code' => $reason,
            ...$attributes,
        ]);
    }

    private function withClientToken(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function ackUrl(AttentionItem $item): string
    {
        return '/api/v1/attention/'.$item->public_id.'/acknowledge';
    }
}
