<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Links a lot created by a stock purchase to that purchase. Opening
     * balance lots have no trade.
     */
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->foreignId('stock_trade_id')->nullable()->unique()->after('account_id')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_trade_id');
        });
    }
};
