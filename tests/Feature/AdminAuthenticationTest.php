<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_anonymous_admin_import_and_client_operations_redirect_to_login(): void
    {
        $this->get(route('admin.imports.index'))->assertRedirect(route('login'));
        $this->post(route('admin.imports.store'))->assertRedirect(route('login'));
        $this->get(route('admin.items.index'))->assertRedirect(route('login'));
        $this->post(route('admin.clients.store'), ['name' => 'PC-01'])->assertRedirect(route('login'));
    }

    public function test_admin_can_sign_in_access_imports_and_sign_out(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.test', 'password' => 'secure-password-123']);

        $this->post(route('admin.login.store'), [
            'email' => 'admin@example.test', 'password' => 'secure-password-123',
        ])->assertRedirect(route('admin.imports.index'));
        $this->assertAuthenticatedAs($admin);
        $this->get(route('admin.imports.index'))->assertOk()->assertSee('Import complete attention snapshot');
        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_repeated_bad_admin_passwords_are_rate_limited(): void
    {
        User::factory()->create(['email' => 'admin@example.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('admin.login.store'), [
                'email' => 'admin@example.test', 'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('admin.login.store'), [
            'email' => 'admin@example.test', 'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
