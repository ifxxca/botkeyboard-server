<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientHeartbeatTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN = 'aim_dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public function test_authenticated_agent_heartbeat_records_presence_and_version(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        $client = $this->client();

        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/v1/client/heartbeat', ['agent_version' => '0.4.0'])
            ->assertOk()->assertExactJson(['status' => 'ok']);

        $client->refresh();
        $this->assertTrue($client->last_seen_at->equalTo(now()));
        $this->assertTrue($client->last_authenticated_at->equalTo(now()));
        $this->assertSame('0.4.0', $client->agent_version);
        $this->assertSame('online', $client->connectionStatus());
    }

    public function test_missing_inactive_and_invalid_heartbeat_are_rejected(): void
    {
        $client = $this->client();

        $this->postJson('/api/v1/client/heartbeat', ['agent_version' => '0.4.0'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/v1/client/heartbeat', ['agent_version' => '<script>'])
            ->assertUnprocessable();

        $client->update(['active' => false]);
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/v1/client/heartbeat', ['agent_version' => '0.4.0'])
            ->assertForbidden()->assertJsonPath('error.code', 'CLIENT_INACTIVE');
    }

    public function test_connection_status_distinguishes_online_offline_never_and_disabled(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        config(['attention.client_online_seconds' => 90]);

        $this->assertSame('online', Client::factory()->make([
            'last_seen_at' => now()->subSeconds(90),
        ])->connectionStatus());
        $this->assertSame('offline', Client::factory()->make([
            'last_seen_at' => now()->subSeconds(91),
        ])->connectionStatus());
        $this->assertSame('never', Client::factory()->make([
            'last_seen_at' => null,
        ])->connectionStatus());
        $this->assertSame('disabled', Client::factory()->make([
            'active' => false,
            'last_seen_at' => now(),
        ])->connectionStatus());
    }

    public function test_client_admin_page_shows_connection_summary(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        $this->actingAs(User::factory()->create());
        Client::factory()->create(['name' => 'PC-ONLINE', 'last_seen_at' => now()]);
        Client::factory()->create(['name' => 'PC-OFFLINE', 'last_seen_at' => now()->subMinutes(5)]);
        Client::factory()->create(['name' => 'PC-NEVER', 'last_seen_at' => null]);

        $this->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('PC-ONLINE')
            ->assertSee('PC-OFFLINE')
            ->assertSee('PC-NEVER')
            ->assertSee('Online')
            ->assertSee('Offline')
            ->assertSee('Belum pernah terhubung');
    }

    private function client(): Client
    {
        return Client::factory()->create(['token_hash' => hash('sha256', self::TOKEN)]);
    }
}
