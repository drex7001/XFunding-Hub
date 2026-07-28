<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_instructions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('details');
            $table->json('bank_profile');
            $table->string('status')->default('pending');
            $table->string('reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['bank_id', 'version']);
        });
        Schema::create('offers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('borrower_token')->unique();
            $table->foreignUlid('funding_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('settlement_instruction_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('rate_units');
            $table->string('collateral');
            $table->string('interest_payment');
            $table->timestamp('valid_until');
            $table->string('status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->json('contributor_ids');
            $table->unsignedInteger('label_number')->nullable();
            $table->unsignedBigInteger('active_slot')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['funding_request_id', 'active_slot']);
            $table->unique(['funding_request_id', 'label_number']);
            $table->index(['bank_id', 'status']);
            $table->index(['status', 'valid_until']);
        });
        Schema::create('offer_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('expected_version');
            $table->string('status')->default('pending');
            $table->string('reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['offer_id', 'status']);
        });
        Schema::create('selection_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('funding_request_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('expected_offer_version');
            $table->ulid('pending_slot')->nullable()->unique();
            $table->string('status')->default('pending');
            $table->string('reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('deals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('funding_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('offer_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('borrower_bank_id')->constrained('banks')->restrictOnDelete();
            $table->foreignId('lender_bank_id')->constrained('banks')->restrictOnDelete();
            $table->foreignUlid('settlement_instruction_id')->constrained()->restrictOnDelete();
            $table->json('terms');
            $table->text('instruction_snapshot');
            $table->timestamp('offer_valid_until');
            $table->string('status')->default('awaiting_fee');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['deals', 'selection_proposals', 'offer_approvals', 'offers', 'settlement_instructions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
