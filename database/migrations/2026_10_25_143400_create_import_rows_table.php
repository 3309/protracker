<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row of an uploaded file: the original values exactly as read, the
     * values understood from them, what happened to the row and, once
     * imported, the record it created. Rows are never silently discarded.
     */
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->string('action')->nullable();
            $table->json('data');
            $table->string('status');
            $table->json('messages');
            $table->string('fingerprint', 64)->nullable()->index();
            $table->string('broker_reference')->nullable()->index();
            $table->foreignId('paired_row_id')->nullable()->constrained('import_rows')->nullOnDelete();
            $table->boolean('import_anyway')->default(false);
            $table->text('skip_reason')->nullable();
            $table->nullableMorphs('imported');
            $table->timestamps();

            $table->index(['import_batch_id', 'status']);
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
