<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Strike and premium per share are stored in ten-thousandths of a dollar
     * and fees in cents, so they are always exact. The side column only
     * allows "short" for now; it exists so long options can be added later.
     */
    public function up(): void
    {
        Schema::create('option_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->string('underlying', 10);
            $table->string('option_type');
            $table->string('side')->default('short');
            $table->unsignedBigInteger('strike');
            $table->date('expires_on');
            $table->date('opened_on');
            $table->unsignedInteger('contracts');
            $table->unsignedInteger('multiplier')->default(100);
            $table->unsignedBigInteger('premium_per_share');
            $table->unsignedInteger('opening_fees')->default(0);
            $table->string('origin');
            $table->string('source')->nullable();
            $table->text('reference_note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'underlying', 'expires_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('option_positions');
    }
};
