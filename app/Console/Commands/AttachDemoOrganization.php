<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AttachDemoOrganization extends Command
{
    protected $signature = 'markethub:demo-attach {email : Email существующего администратора}';

    protected $description = 'Привязать демоорганизацию и сделать её активной для локального администратора';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Команда разрешена только в local.');

            return self::FAILURE;
        }
        $user = User::where('email', strtolower(trim($this->argument('email'))))->where('is_admin', true)->first();
        $organization = Organization::where('slug', 'markethub-demo')->first();
        if (! $user || ! $organization) {
            $this->error('Администратор или демоорганизация не найдены. Сначала запустите DemoDataSeeder.');

            return self::FAILURE;
        }
        if (! $this->confirm('Привязать демокомпанию к '.$user->email.' и сделать её активной?', false)) {
            return self::FAILURE;
        }
        DB::transaction(function () use ($user, $organization): void {
            $user->organizations()->syncWithoutDetaching([$organization->id]);
            $user->active_organization_id = $organization->id;
            $user->save();
        });
        $this->info('Демоорганизация привязана. Другие членства и данные сохранены.');

        return self::SUCCESS;
    }
}
