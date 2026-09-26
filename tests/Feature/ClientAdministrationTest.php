<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ClientAdministrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_creates_client_with_one_time_token_and_hash_only_in_database(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post(route('admin.clients.store'), [
            'name' => 'PC-OUTBOUND-01', 'active' => false, 'token_hash' => 'attacker-controlled',
        ]);

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        preg_match('/aim_[a-f0-9]{64}/', $response->getContent(), $matches);
        $this->assertCount(1, $matches);
        $token = $matches[0];
        $client = Client::firstOrFail();
        $this->assertSame('PC-OUTBOUND-01', $client->name);
        $this->assertTrue($client->active);
        $this->assertSame(hash('sha256', $token), $client->token_hash);
        $this->assertNotSame($token, $client->token_hash);
        $this->get(route('admin.clients.show', $client))->assertDontSee($token);
        $this->assertDatabaseHas('audit_events', ['event' => 'CLIENT_CREATED', 'subject_id' => $client->id]);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])
            ->assertOk()->assertExactJson(['matched' => false]);
    }

    public function test_disable_enable_and_rotate_revoke_old_token_and_write_audit(): void
    {
        $this->actingAs(User::factory()->create());
        $oldToken = 'aim_'.str_repeat('c', 64);
        $client = Client::factory()->create(['token_hash' => hash('sha256', $oldToken)]);

        $this->post(route('admin.clients.disable', $client))->assertRedirect();
        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])->assertForbidden();

        $this->post(route('admin.clients.enable', $client))->assertRedirect();
        $response = $this->post(route('admin.clients.rotate', $client))->assertOk();
        preg_match('/aim_[a-f0-9]{64}/', $response->getContent(), $matches);
        $this->assertCount(1, $matches);
        $newToken = $matches[0];

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$newToken)
            ->postJson('/api/v1/attention/check', ['awb' => '0201234567890'])->assertOk();
        $this->assertSame(hash('sha256', $newToken), $client->fresh()->token_hash);
        $this->assertDatabaseHas('audit_events', ['event' => 'CLIENT_DISABLED', 'subject_id' => $client->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'CLIENT_ENABLED', 'subject_id' => $client->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'CLIENT_TOKEN_ROTATED', 'subject_id' => $client->id]);
    }

    public function test_client_name_is_escaped_on_admin_page(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['name' => '<script>alert(1)</script>']);

        $this->get(route('admin.clients.show', $client))
            ->assertOk()->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
