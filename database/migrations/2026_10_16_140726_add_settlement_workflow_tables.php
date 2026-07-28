<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('closed_unsettled_at')->nullable();
        });
        Schema::create('settlement_confirmations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('deal_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('settlement_instruction_id')->constrained()->restrictOnDelete();
            $table->timestamp('settlement_at');
            $table->text('reference')->nullable();
            $table->text('note')->nullable();
            $table->text('review_reason')->nullable();
            $table->string('status')->default('pending');
            $table->string('pending_slot')->nullable()->unique();
            $table->string('approved_slot')->nullable()->unique();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['deal_id', 'bank_id', 'status']);
        });
        Schema::create('deal_disputes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('deal_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->text('description');
            $table->string('status')->default('open');
            $table->ulid('open_slot')->nullable()->unique();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        Schema::create('deal_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('deal_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->string('side')->nullable();
            $table->timestamps();
            $table->index(['deal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_events');
        Schema::dropIfExists('deal_disputes');
        Schema::dropIfExists('settlement_confirmations');
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn(['settled_at', 'closed_unsettled_at']);
        });
    }
};
