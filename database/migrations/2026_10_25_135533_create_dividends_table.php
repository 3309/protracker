<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Dividends per account and ticker. The amount per share is stored in
     * millionths of a dollar. Eligible shares (millionths of a share) and the
     * expected amount (cents) are saved as they stood when the actual amount
     * was confirmed, so both expected and actual are kept.
     */
    public function up(): void
    {
        Schema::create('dividends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->string('ticker', 10);
            $table->date('ex_date');
            $table->date('pay_date');
            $table->unsignedBigInteger('amount_per_share');
            $table->unsignedBigInteger('eligible_shares')->nullable();
            $table->unsignedBigInteger('expected_amount')->nullable();
            $table->unsignedBigInteger('actual_amount')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('reference_note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'ticker', 'ex_date']);
            $table->index(['account_id', 'pay_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dividends');
    }
};
