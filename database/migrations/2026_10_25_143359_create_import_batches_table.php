<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One uploaded CSV file. The file itself is not kept: only its name, a
     * SHA-256 fingerprint of its contents and its rows (in import_rows).
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('format');
            $table->string('original_filename');
            $table->string('file_sha256', 64);
            $table->string('status');
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
