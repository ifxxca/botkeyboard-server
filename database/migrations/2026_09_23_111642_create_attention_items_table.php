<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attention_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $table->string('awb', 30)->collation('utf8mb4_bin');
            $table->string('reason_code', 32);
            $table->string('severity', 16);
            $table->string('title', 120);
            $table->string('message', 500);
            $table->unsignedBigInteger('declared_value')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->unique(['import_batch_id', 'awb', 'reason_code']);
            $table->index(['import_batch_id', 'awb', 'active']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attention_items');
    }
};
