<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Cash balances reported by the broker at the end of a date, entered to
     * reconcile against the calculated cash ledger. Amounts are in cents and
     * may be negative. A null settled amount means the broker's settled
     * figure was not entered.
     */
    public function up(): void
    {
        Schema::create('broker_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->date('as_of_date');
            $table->bigInteger('total_cash');
            $table->bigInteger('settled_cash')->nullable();
            $table->text('reference_note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'as_of_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broker_balances');
    }
};
