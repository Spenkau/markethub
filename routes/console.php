<?php

use App\Services\Marketplaces\SyncDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('shopify:dispatch-due', function (SyncDispatcher $dispatcher) {
    $this->info('Scheduled: '.$dispatcher->schedule());
});
Artisan::command('marketplaces:dispatch-due', function (SyncDispatcher $dispatcher) {
    $this->info('Scheduled: '.$dispatcher->schedule());
});
Artisan::command('marketplaces:recover-syncs', function (SyncDispatcher $dispatcher) {
    $this->info('Recovered: '.$dispatcher->recover());
});
Schedule::command('marketplaces:dispatch-due')->everyMinute()->withoutOverlapping();
