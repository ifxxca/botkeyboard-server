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
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('awb', 30)->nullable()->collation('utf8mb4_bin');
            $table->string('reason_code', 32)->nullable();
            $table->string('severity', 16)->nullable();
            $table->string('title', 120)->nullable();
            $table->string('message', 500)->nullable();
            $table->unsignedBigInteger('declared_value')->nullable();
            $table->string('status', 16);
            $table->string('issue', 120)->nullable();
            $table->unique(['import_batch_id', 'row_number']);
            $table->index(['import_batch_id', 'status', 'id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
