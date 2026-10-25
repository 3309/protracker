<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Numbers are stored as whole numbers so they are always exact:
     * shares in millionths of a share, cost per share in ten-thousandths of a
     * dollar, and fees in cents.
     */
    public function up(): void
    {
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->string('ticker', 10);
            $table->string('origin');
            $table->date('acquired_on');
            $table->unsignedBigInteger('shares');
            $table->unsignedBigInteger('cost_per_share');
            $table->unsignedInteger('fees')->default(0);
            $table->string('source')->nullable();
            $table->text('reference_note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'ticker', 'acquired_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_lots');
    }
};
