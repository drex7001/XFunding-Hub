<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_instructions', function (Blueprint $table): void {
            $table->foreignId('retired_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('retired_at')->nullable();
        });
        Schema::table('selection_proposals', function (Blueprint $table): void {
            $table->foreignUlid('borrower_settlement_instruction_id')
                ->nullable()
                ->constrained('settlement_instructions')
                ->restrictOnDelete();
        });
        Schema::table('deals', function (Blueprint $table): void {
            $table->foreignUlid('borrower_settlement_instruction_id')
                ->nullable()
                ->constrained('settlement_instructions')
                ->restrictOnDelete();
            $table->text('borrower_instruction_snapshot')->nullable();
        });
        Schema::table('deal_instruction_changes', function (Blueprint $table): void {
            $table->string('side')->default('lender');
        });
    }

    public function down(): void
    {
        Schema::table('deal_instruction_changes', function (Blueprint $table): void {
            $table->dropColumn('side');
        });
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropForeign(['borrower_settlement_instruction_id']);
            $table->dropColumn(['borrower_settlement_instruction_id', 'borrower_instruction_snapshot']);
        });
        Schema::table('selection_proposals', function (Blueprint $table): void {
            $table->dropForeign(['borrower_settlement_instruction_id']);
            $table->dropColumn('borrower_settlement_instruction_id');
        });
        Schema::table('settlement_instructions', function (Blueprint $table): void {
            $table->dropForeign(['retired_by']);
            $table->dropColumn(['retired_by', 'retired_at']);
        });
    }
};
