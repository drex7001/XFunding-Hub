<?php

use App\BankRole;
use Illuminate\Support\Facades\DB;

it('preserves notification identity and payload when upgrading existing text storage for JSON filtering', function () {
    $this->freezeTime();
    enableDemoPayments();
    $deal = unlockedDeal();
    $auditor = bankStaff(BankRole::Auditor, $deal->borrowerBank);
    confirmedInstitution($deal, $deal->borrowerBank);
    $notification = $auditor->notifications()->sole();
    $migration = require database_path('migrations/2026_10_16_150835_store_notification_data_as_json.php');
    $migration->down();
    $before = json_decode(DB::table('notifications')->where('id', $notification->id)->value('data'), true, flags: JSON_THROW_ON_ERROR);
    $migration->up();

    expect(json_decode(DB::table('notifications')->where('id', $notification->id)->value('data'), true, flags: JSON_THROW_ON_ERROR))->toBe($before);
    expect($auditor->notifications()->sole()->id)->toBe($notification->id);
    signInUser($auditor);
    $this->getJson(route('api.notifications.index'))->assertOk()->assertJsonCount(1, 'data');
});
