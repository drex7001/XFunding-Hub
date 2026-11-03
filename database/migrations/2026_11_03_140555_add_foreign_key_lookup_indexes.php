<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL does not index foreign key columns automatically.
     *
     * @var array<string, array<int, string>>
     */
    private array $indexes = [
        'deals' => [
            'borrower_bank_id',
            'lender_bank_id',
            'settlement_instruction_id',
            'borrower_settlement_instruction_id',
        ],
        'deal_disputes' => ['deal_id'],
        'deal_instruction_changes' => ['deal_id'],
        'selection_proposals' => ['funding_request_id', 'offer_id'],
        'funding_documents' => ['funding_request_id'],
        'bank_memberships' => ['bank_id'],
        'access_requests' => ['bank_id'],
        'bank_documents' => ['bank_id'],
        'payment_receipts' => ['fee_payment_id'],
        'offers' => ['settlement_instruction_id'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->dropIndex([$column]);
                }
            });
        }
    }
};
