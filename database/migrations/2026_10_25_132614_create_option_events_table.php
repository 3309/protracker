<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Lifecycle events of an option position, such as buying contracts back
     * to close them. Prices are in ten-thousandths of a dollar and amounts in
     * cents.
     *
     * allocated_premium and allocated_opening_fees are the part of the
     * position's opening premium and fees belonging to the contracts this
     * event closed. They are recalculated from the position and its events
     * whenever either changes, so they are never edited directly.
     */
    public function up(): void
    {
        Schema::create('option_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_position_id')->constrained();
            $table->foreignId('account_id')->constrained();
            $table->string('event_type');
            $table->date('event_date');
            $table->date('settlement_date')->nullable();
            $table->boolean('settlement_date_overridden')->default(false);
            $table->unsignedInteger('contracts');
            $table->unsignedBigInteger('price_per_share')->nullable();
            $table->unsignedInteger('fees')->default(0);
            $table->bigInteger('allocated_premium')->default(0);
            $table->unsignedInteger('allocated_opening_fees')->default(0);
            $table->text('reference_note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'event_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('option_events');
    }
};
