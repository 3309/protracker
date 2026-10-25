<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which stock lots each sale used (oldest first), with the realized
     * profit of each piece. These rows are calculated from the trades and
     * lots and rebuilt whenever a trade or lot changes, so they are never
     * edited directly. Amounts are in cents.
     */
    public function up(): void
    {
        Schema::create('stock_sale_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->string('ticker', 10);
            $table->foreignId('stock_trade_id')->constrained();
            $table->foreignId('stock_lot_id')->constrained();
            $table->date('sold_on');
            $table->unsignedBigInteger('shares');
            $table->bigInteger('proceeds');
            $table->bigInteger('cost');
            $table->unsignedInteger('sale_fees');
            $table->unsignedInteger('purchase_fees');
            $table->timestamps();

            $table->index(['account_id', 'sold_on']);
            $table->index(['account_id', 'ticker']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_sale_allocations');
    }
};
