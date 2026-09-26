<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->timestamp('last_seen_at')->nullable()->after('last_authenticated_at')->index();
            $table->string('agent_version', 32)->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn(['last_seen_at', 'agent_version']);
        });
    }
};
