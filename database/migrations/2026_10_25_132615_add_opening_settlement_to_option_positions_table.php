<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The settlement date of a sell-to-open trade, when the premium cash
     * arrives. Opening-balance positions have none.
     */
    public function up(): void
    {
        Schema::table('option_positions', function (Blueprint $table) {
            $table->date('opening_settlement_date')->nullable()->after('opened_on');
            $table->boolean('opening_settlement_date_overridden')->default(false)->after('opening_settlement_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('option_positions', function (Blueprint $table) {
            $table->dropColumn(['opening_settlement_date', 'opening_settlement_date_overridden']);
        });
    }
};
