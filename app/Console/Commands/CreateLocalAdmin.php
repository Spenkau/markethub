<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateLocalAdmin extends Command
{
    protected $signature = 'markethub:admin {--name= : Имя администратора} {--email= : Email администратора}';

    protected $description = 'Создать локального администратора MarketHub с безопасным вводом пароля';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Команда доступна только в локальном окружении.');

            return self::FAILURE;
        }

        $data = [
            'name' => trim((string) ($this->option('name') ?: $this->ask('Имя администратора'))),
            'email' => strtolower(trim((string) ($this->option('email') ?: $this->ask('Email администратора')))),
            'password' => $this->secret('Пароль (не менее 12 символов)', fallback: false),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string',
                //                Password::min(12)->mixedCase()->numbers()->symbols()
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_admin' => true,
        ]);

        $this->info('Пользователь создан. API входа: '.url('/api/v1/auth/login'));

        return self::SUCCESS;
    }
}
