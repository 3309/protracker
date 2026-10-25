<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fee columns hold whole cents (67 = $0.67) so money is never stored as a
     * floating-point number.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('broker');
            $table->string('nickname')->unique();
            $table->string('account_type');
            $table->string('account_number_last4', 4)->nullable();
            $table->unsignedInteger('option_fee_per_contract')->default(67);
            $table->unsignedInteger('stock_fee_per_trade')->default(0);
            $table->unsignedInteger('assignment_fee')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
