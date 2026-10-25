<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Manually entered prices, kept as a history. A stock price is shared by
     * all accounts. An option price belongs to one contract: ticker, put or
     * call, strike and expiration. Prices and strikes are in ten-thousandths
     * of a dollar; as_of is the moment the price applies to.
     */
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->string('instrument');
            $table->string('ticker', 10);
            $table->string('option_type')->nullable();
            $table->unsignedBigInteger('strike')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('price');
            $table->timestamp('as_of');
            $table->text('reference_note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['instrument', 'ticker', 'as_of']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
