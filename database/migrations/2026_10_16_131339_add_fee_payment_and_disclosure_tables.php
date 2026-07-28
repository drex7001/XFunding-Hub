<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->unsignedBigInteger('fee_amount_minor')->nullable();
            $table->string('fee_currency', 3)->nullable();
            $table->json('fee_policy')->nullable();
            $table->timestamp('fee_quoted_at')->nullable();
            $table->timestamp('fee_deadline')->nullable();
            $table->timestamp('unlocked_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('instructions_updated_at')->nullable();
            $table->index(['status', 'fee_deadline']);
        });
        Schema::create('fee_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('deal_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->string('provider');
            $table->string('provider_reference', 60)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status')->default('pending');
            $table->ulid('pending_slot')->nullable()->unique();
            $table->string('failure_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['deal_id', 'status']);
        });
        Schema::create('payment_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('fee_payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payment_reference', 60);
            $table->ulid('reported_deal_id');
            $table->string('provider');
            $table->string('charge_reference', 100);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status')->default('refund_review');
            $table->ulid('accepted_slot')->nullable()->unique();
            $table->string('reason')->nullable();
            $table->timestamp('received_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('refund_reference', 100)->nullable();
            $table->string('review_reason', 500)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'charge_reference']);
            $table->unique(['provider', 'refund_reference']);
            $table->index(['status', 'received_at']);
        });
        Schema::create('payment_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider');
            $table->uuid('event_reference');
            $table->string('payload_sha256', 64);
            $table->string('payment_reference', 60);
            $table->ulid('reported_deal_id');
            $table->foreignUlid('fee_payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('outcome');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_reference']);
        });
        Schema::create('deal_instruction_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('deal_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('settlement_instruction_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->ulid('pending_slot')->nullable()->unique();
            $table->string('status')->default('pending');
            $table->text('previous_snapshot')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::table('deals', function (Blueprint $table): void {
            $table->foreignUlid('verified_payment_id')->nullable()->constrained('fee_payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropForeign(['verified_payment_id']);
            $table->dropColumn('verified_payment_id');
        });
        foreach (['deal_instruction_changes', 'payment_notifications', 'payment_receipts', 'fee_payments'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex(['status', 'fee_deadline']);
            $table->dropColumn(['fee_amount_minor', 'fee_currency', 'fee_policy', 'fee_quoted_at', 'fee_deadline', 'unlocked_at', 'cancelled_at', 'instructions_updated_at']);
        });
    }
};
