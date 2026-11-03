<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The retired loan sample is gone, so its unused table goes with it.
     */
    public function up(): void
    {
        Schema::dropIfExists('loan_applications');
    }

    public function down(): void
    {
        Schema::create('loan_applications', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id', 100);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->unsignedInteger('term_months');
            $table->string('status')->default('SUBMITTED');
            $table->timestamps();
        });
    }
};
