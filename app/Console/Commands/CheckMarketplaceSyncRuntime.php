<?php

namespace App\Console\Commands;

use App\Jobs\SyncMarketplace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class CheckMarketplaceSyncRuntime extends Command
{
    protected $signature = 'marketplaces:sync-runtime';

    protected $description = 'Validate durable queue timeout settings and report non-secret worker configuration';

    public function handle(): int
    {
        $connection = config('marketplaces.sync.connection', config('queue.default'));
        $settings = config('queue.connections.'.$connection, []);
        $timeout = (new SyncMarketplace('runtime-check'))->timeout;
        if (! in_array($settings['driver'] ?? null, ['redis', 'database'], true) || ($settings['retry_after'] ?? 0) <= $timeout || config('marketplaces.sync.execution_budget', 1500) >= $timeout) {
            $this->error('Для синхронизации нужна Redis/database очередь с retry_after больше timeout и execution_budget меньше timeout.');

            return self::FAILURE;
        }
        try {
            $queue = config('marketplaces.sync.queue', 'marketplaces');
            $pending = Queue::connection($connection)->size($queue);
        } catch (\Throwable) {
            $this->error('Очередь недоступна. Проверьте Redis или подключение к базе очереди.');

            return self::FAILURE;
        }
        $this->line(json_encode(['connection' => $connection, 'queue' => $queue, 'timeout' => $timeout, 'retry_after' => $settings['retry_after'], 'execution_budget' => config('marketplaces.sync.execution_budget', 1500), 'pending' => $pending], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
