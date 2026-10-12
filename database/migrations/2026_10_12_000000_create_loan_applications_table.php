<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('loan_applications');
    }
};
