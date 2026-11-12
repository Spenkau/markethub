<?php

namespace App\Services\Marketplaces;

class SyncException extends \RuntimeException
{
    public readonly string $reason;

    public function __construct(string $reason, public readonly bool $retryable = false)
    {
        $this->reason = in_array($reason, ['permissions', 'token', 'throttled', 'invalid_data', 'worker_lost', 'budget', 'source_changed', 'stale_attempt', 'connection', 'disabled', 'dispatch', 'provider_error', 'attempts_exhausted'], true) ? $reason : 'provider_error';
        parent::__construct(self::safeMessage($this->reason));
    }

    public static function safeMessage(string $reason): string
    {
        return match ($reason) {
            'permissions' => 'Недостаточно прав API торговой площадки.',
            'token' => 'Не удалось получить или обновить токен.',
            'throttled' => 'Превышены лимиты API торговой площадки.',
            'invalid_data' => 'Получены некорректные внешние данные.',
            'worker_lost' => 'Worker остановлен; запуск восстановлен по heartbeat.',
            'source_changed' => 'Источник изменился во время обхода; требуется безопасная повторная проверка.',
            'budget' => 'Достигнут лимит времени попытки; требуется безопасный повтор обхода.',
            'dispatch' => 'Очередь временно недоступна. Публикация будет повторена.',
            'disabled' => 'Подключение отключено или операция больше не поддерживается.',
            default => 'Не удалось выполнить синхронизацию. Проверьте состояние подключения.',
        };
    }
}
