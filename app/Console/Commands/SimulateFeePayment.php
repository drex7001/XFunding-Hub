<?php

namespace App\Console\Commands;

use App\Actions\DemoPaymentProvider;
use App\Actions\ProcessFeePaymentNotification;
use App\Models\FeePayment;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SimulateFeePayment extends Command
{
    protected $signature = 'payments:simulate {payment : Fee payment ID or reference} {--outcome=paid} {--amount=} {--currency=} {--event=} {--charge=} {--deal=}';

    protected $description = 'Deliver a signed synthetic provider notification locally; never charges real money';

    public function handle(DemoPaymentProvider $provider, ProcessFeePaymentNotification $handler): int
    {
        if (! $provider->enabled()) {
            $this->error('Demo payments are disabled or this is not a local/test environment.');

            return self::FAILURE;
        }
        $reference = $this->argument('payment');
        $payment = FeePayment::query()->where('id', $reference)->orWhere('provider_reference', $reference)->firstOrFail();
        $outcome = $this->option('outcome');
        $payload = [
            'event_reference' => $this->option('event') ?: (string) Str::uuid(), 'payment_reference' => $payment->provider_reference,
            'deal_id' => $this->option('deal') ?: $payment->deal_id, 'outcome' => $outcome,
            'amount' => $this->option('amount') ?: $payment->amount, 'currency' => $this->option('currency') ?: $payment->currency,
            'charge_reference' => $outcome === 'paid' ? ($this->option('charge') ?: (string) Str::uuid()) : null,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();
        $request = Request::create('/api/payments/demo/notifications', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYMENT_TIMESTAMP' => $timestamp, 'HTTP_X_PAYMENT_SIGNATURE' => $provider->signature($body, $timestamp)], $body);
        $result = $handler->handle($request);
        $this->info('Synthetic notification result: '.$result.'.');

        return self::SUCCESS;
    }
}
