<?php

namespace App\Console\Commands;

use App\Actions\ManagePlatformAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email : Work email address} {name : Full name}';

    protected $description = 'Provision a platform operations account and email its password setup link';

    public function handle(ManagePlatformAccounts $action): int
    {
        try {
            $data = Validator::make($this->arguments(), [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:120'],
            ])->validate();
            $action->create(null, $data['name'], $data['email'], 'Provisioned from the server console.');
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first());

            return self::FAILURE;
        }
        $this->info('Platform account created. A password setup link has been sent.');

        return self::SUCCESS;
    }
}
