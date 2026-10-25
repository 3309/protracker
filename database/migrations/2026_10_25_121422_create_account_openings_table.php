<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per account holding its starting cash on the reporting start
     * date. Cash columns hold whole cents and may be negative (money owed to
     * the broker). A null unsettled amount means "not known".
     */
    public function up(): void
    {
        Schema::create('account_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained();
            $table->date('as_of_date');
            $table->bigInteger('cash_balance')->nullable();
            $table->bigInteger('unsettled_cash')->nullable();
            $table->string('cash_source')->nullable();
            $table->text('cash_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_openings');
    }
};
