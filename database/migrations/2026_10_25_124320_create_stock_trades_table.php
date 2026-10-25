<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per stock buy or sell. Shares are stored in millionths of a
     * share, the price in ten-thousandths of a dollar and fees in cents.
     */
    public function up(): void
    {
        Schema::create('stock_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->string('ticker', 10);
            $table->string('side');
            $table->date('trade_date');
            $table->date('settlement_date');
            $table->boolean('settlement_date_overridden')->default(false);
            $table->unsignedBigInteger('shares');
            $table->unsignedBigInteger('price_per_share');
            $table->unsignedInteger('fees')->default(0);
            $table->text('reference_note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'ticker', 'trade_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_trades');
    }
};
