<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('active_datasets', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('active_batch_id')->nullable()->constrained('import_batches')->restrictOnDelete();
            $table->timestamp('updated_at')->nullable();
        });
        DB::table('active_datasets')->insert(['id' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('active_datasets');
    }
};
