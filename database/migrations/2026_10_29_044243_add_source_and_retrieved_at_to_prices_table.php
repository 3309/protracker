<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where each price came from: typed in by hand ("manual", every existing
     * price) or fetched from a market-data provider. retrieved_at is when the
     * app fetched a quote; as_of stays the moment the price applies to.
     */
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('price');
            $table->timestamp('retrieved_at')->nullable()->after('as_of');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropColumn(['source', 'retrieved_at']);
        });
    }
};
