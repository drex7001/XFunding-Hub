<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupDemoPayments extends Command
{
    protected $signature = 'payments:setup-demo';

    protected $description = 'Enable synthetic local payments and configure a private webhook signing key';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Demo payments may only be configured locally or in tests.');

            return self::FAILURE;
        }
        $path = app()->environmentFilePath();
        if (! is_file($path)) {
            $this->error('Create the local environment file first.');

            return self::FAILURE;
        }
        $content = file_get_contents($path);
        $key = config('payments.webhook_secret');
        $updates = ['DEMO_PAYMENTS_ENABLED' => 'true'];
        if (! is_string($key) || strlen($key) < 32) {
            $updates['DEMO_PAYMENT_WEBHOOK_SECRET'] = bin2hex(random_bytes(32));
        }
        foreach ($updates as $name => $value) {
            $pattern = '/^'.preg_quote($name, '/').'=.*$/m';
            $content = preg_match($pattern, $content) ? preg_replace_callback($pattern, fn (): string => $name.'='.$value, $content) : rtrim($content)."\n{$name}={$value}\n";
        }
        file_put_contents($path, $content, LOCK_EX);
        chmod($path, 0600);
        $this->callSilent('config:clear');
        $this->info('Demo payments enabled. A private signing key is configured; no real payment provider is connected.');

        return self::SUCCESS;
    }
}
