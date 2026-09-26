<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_health_returns_only_status_when_database_is_available(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }
}
