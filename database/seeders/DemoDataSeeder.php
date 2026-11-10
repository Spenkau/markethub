<?php

namespace Database\Seeders;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Демосидер разрешён только в local/testing.');
        }

        Model::unguarded(fn () => DB::transaction(function (): void {
            $organization = Organization::firstOrCreate(['slug' => 'markethub-demo'], ['name' => 'MarketHub — демонстрационная компания']);
            $accounts = [];
            foreach (MarketplaceAccount::MARKETPLACES as $code => $label) {
                $accounts[$code] = MarketplaceAccount::withoutGlobalScopes()->firstOrCreate([
                    'organization_id' => $organization->id, 'marketplace' => $code, 'name' => $label.' — демо', 'is_demo' => true,
                ], ['status' => 'demo']);
            }
            $names = [
                'Беспроводная мышь', 'Механическая клавиатура', 'USB-C концентратор', 'Настольная LED-лампа', 'Наушники с микрофоном',
                'Портативная колонка', 'Внешний аккумулятор', 'Веб-камера', 'Подставка для ноутбука', 'Коврик для мыши',
                'Кабель USB-C', 'Зарядное устройство', 'Чехол для планшета', 'Держатель телефона', 'Сумка для ноутбука',
                'Органайзер проводов', 'Защитное стекло', 'Ремешок для часов', 'Кардхолдер', 'Дорожный органайзер',
                'Термокружка', 'Контейнер для продуктов', 'Кухонные весы', 'Набор полотенец', 'Корзина для хранения',
                'Настенные часы', 'Дозатор для мыла', 'Подставка для специй', 'Силиконовый коврик', 'Набор салфеток',
                'Умная розетка', 'Компактный светильник', 'Кабель HDMI', 'Автомобильный держатель', 'Переходник USB',
                'Косметичка', 'Обложка для документов', 'Чехол для наушников', 'Спортивная бутылка', 'Складная сумка',
                'Плед', 'Наволочка', 'Ваза', 'Разделочная доска', 'Набор кухонных лопаток',
                'Подставка под горячее', 'Мешки для стирки', 'Крючки для ванной', 'Щётка для уборки', 'Пакеты для хранения',
            ];
            foreach ($names as $index => $name) {
                $number = $index + 1;
                $price = 14900 + (($number * 17321) % 350000);
                $product = Product::withoutGlobalScopes()->firstOrCreate([
                    'organization_id' => $organization->id, 'sku' => sprintf('DEMO-MH-%03d', $number),
                ], [
                    'name' => $name.' (демо)', 'description' => 'Синтетический товар для знакомства с MarketHub. Не является реальным предложением.',
                    'base_price_minor' => $price, 'currency' => 'RUB', 'is_active' => $number % 9 !== 0,
                ]);
                foreach ($accounts as $code => $account) {
                    MarketplaceListing::withoutGlobalScopes()->firstOrCreate([
                        'organization_id' => $organization->id, 'product_id' => $product->id, 'marketplace_account_id' => $account->id,
                    ], [
                        'external_id' => 'SYNTHETIC-'.strtoupper($code).'-'.$number,
                        'external_sku' => 'DEMO-'.$code.'-'.$number,
                        'price_minor' => $price + ($code === 'ozon' ? 2300 : 0), 'currency' => 'RUB',
                        'stock_quantity' => $number % 7 === 0 ? 0 : ($number * ($code === 'ozon' ? 3 : 2)) % 45,
                        'status' => $number % 9 === 0 ? 'inactive' : 'active',
                    ]);
                }
            }
        }));
    }
}
