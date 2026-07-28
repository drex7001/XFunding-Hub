<?php

namespace Database\Seeders;

use App\Actions\ManageFundingRequests;
use App\BankStatus;
use App\Models\Bank;
use App\Models\FundingRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

class DemoFundingRequestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Demo funding requests are only available locally or in tests.');
        }
        $bank = Bank::query()->where('registration_number', 'DEMO-borrower')->first();
        if (! $bank || $bank->status !== BankStatus::Verified || ! $bank->can_borrow) {
            return;
        }
        $maker = User::query()->where('email', 'borrower.maker@xfunding.example')->firstOrFail();
        $approver = User::query()->where('email', 'borrower.approver@xfunding.example')->firstOrFail();
        $action = app(ManageFundingRequests::class);
        foreach ([
            ['Working capital liquidity', '250000000.00', 'LKR', 'open'],
            ['Offer comparison walkthrough', '150000000.00', 'LKR', 'open'],
            ['Treasury reserve buffer', '7500000.00', 'USD', 'pending'],
            ['Draft funding request', '40000000.00', 'LKR', 'draft'],
        ] as [$title, $amount, $currency, $state]) {
            if (FundingRequest::query()->where('bank_id', $bank->id)->where('title', $title)->exists()) {
                continue;
            }
            $record = $action->create($maker, [
                'title' => $title, 'amount' => $amount, 'currency' => $currency, 'tenor_days' => 7,
                'settlement_date' => now()->addDays(4)->toDateString(), 'bidding_deadline' => now()->addDays(3),
                'day_count' => $currency === 'USD' ? 'ACT/360' : 'ACT/365F',
                'borrower_background' => 'Harbor Bank is a synthetic institution for the XFunding Hub demonstration. Its history and attached financial evidence are sample data, not real audited financial statements.',
            ]);
            if ($state !== 'draft') {
                $temporary = tempnam(sys_get_temp_dir(), 'xfunding-report-');
                try {
                    file_put_contents($temporary, $this->demoPdf());
                    $action->addDocument($maker, $record, new UploadedFile($temporary, 'synthetic-financial-report.pdf', 'application/pdf', UPLOAD_ERR_OK, true));
                } finally {
                    unlink($temporary);
                }
                $record = $action->submit($maker, $record);
                if ($state === 'open') {
                    $action->review($approver, $record, $record->approvals()->where('status', 'pending')->firstOrFail(), true, null);
                }
            }
        }
    }

    private function demoPdf(): string
    {
        $stream = 'BT /F1 16 Tf 50 730 Td (Synthetic financial report) Tj 0 -28 Td /F1 11 Tf (Harbor Bank - demo data only. Not real financial statements.) Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
