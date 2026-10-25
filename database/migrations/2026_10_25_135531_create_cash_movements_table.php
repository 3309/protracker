<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Standalone cash movements that are not part of a trade or dividend:
     * deposits, withdrawals, interest and other broker credits or charges.
     * The amount is in cents and always positive; the type decides whether
     * cash goes in or out.
     */
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->string('movement_type');
            $table->date('movement_date');
            $table->date('settlement_date');
            $table->unsignedBigInteger('amount');
            $table->text('reference_note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'movement_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
