<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 180);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedInteger('tenor_days');
            $table->date('settlement_date');
            $table->timestamp('bidding_deadline');
            $table->string('day_count', 12);
            $table->string('rate_basis')->default('annual_simple');
            $table->text('borrower_background');
            $table->json('borrower_profile')->nullable();
            $table->json('contributor_ids');
            $table->unsignedInteger('version')->default(0);
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['bank_id', 'status']);
            $table->index(['status', 'bidding_deadline']);
        });
        Schema::create('funding_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('funding_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->timestamps();
        });
        Schema::create('funding_request_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('funding_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('expected_version');
            $table->string('kind');
            $table->json('bank_profile')->nullable();
            $table->string('status')->default('pending');
            $table->string('proposal_reason', 500)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['funding_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_request_approvals');
        Schema::dropIfExists('funding_documents');
        Schema::dropIfExists('funding_requests');
    }
};
