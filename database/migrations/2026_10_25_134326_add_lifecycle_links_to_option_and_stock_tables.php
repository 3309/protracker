<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Links for the option lifecycle:
     * - a stock trade created by an option assignment points to that event;
     * - an option sold as part of a roll points to the buy-back event it
     *   replaced, and every leg of a roll chain shares the chain ID (the ID of
     *   the chain's first position).
     */
    public function up(): void
    {
        Schema::table('stock_trades', function (Blueprint $table) {
            $table->foreignId('option_event_id')->nullable()->unique()->after('account_id')->constrained();
        });

        Schema::table('option_positions', function (Blueprint $table) {
            $table->foreignId('rolled_from_event_id')->nullable()->unique()->after('origin')->constrained('option_events');
            $table->unsignedBigInteger('roll_chain_id')->nullable()->index()->after('rolled_from_event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('option_positions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rolled_from_event_id');
            $table->dropIndex(['roll_chain_id']);
            $table->dropColumn('roll_chain_id');
        });

        Schema::table('stock_trades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('option_event_id');
        });
    }
};
