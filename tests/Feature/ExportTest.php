<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private function sheet(string $url): Worksheet
    {
        $response = $this->getJson($url)->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    public function test_products_export_is_scoped_filtered_sorted_and_contains_safe_formatted_cells(): void
    {
        config(['excel.exports.chunk_size' => 1]);
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $user = User::factory()->create(['active_organization_id' => $org->id]);
        $user->organizations()->attach([$org->id, $other->id]);
        foreach ([$org, $other] as $tenant) {
            Product::factory()->create(['organization_id' => $tenant->id, 'name' => '=1+1', 'sku' => '000123', 'description' => '@SUM(A1:A2)', 'base_price_minor' => 12345, 'is_active' => true]);
            Product::factory()->create(['organization_id' => $tenant->id, 'name' => '=2+2', 'base_price_minor' => 99999, 'is_active' => true]);
            Product::factory()->create(['organization_id' => $tenant->id, 'name' => '=hidden', 'is_active' => false]);
        }
        $this->actingAs($user);
        $sheet = $this->sheet('/api/v1/products/export?search=%3D&is_active=1&sort=price_minor&direction=desc&per_page=1&page=2');
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertSame('Название', $sheet->getCell('B1')->getValue());
        $this->assertSame('=2+2', $sheet->getCell('B2')->getValue());
        $this->assertSame('=1+1', $sheet->getCell('B3')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B3')->getDataType());
        $this->assertSame('000123', $sheet->getCell('C3')->getValue());
        $this->assertSame('@SUM(A1:A2)', $sheet->getCell('D3')->getValue());
        $this->assertEquals(123.45, $sheet->getCell('E3')->getValue());
        $this->assertSame('#,##0.00', $sheet->getStyle('E3')->getNumberFormat()->getFormatCode());
        $this->assertSame('dd.mm.yyyy hh:mm', $sheet->getStyle('I3')->getNumberFormat()->getFormatCode());
        $this->assertIsNumeric($sheet->getCell('I3')->getValue());
    }

    public function test_stocks_export_uses_listing_filters_and_isolates_organizations(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $user = User::factory()->create();
        $user->organizations()->attach($org);
        $product = null;
        $account = null;
        foreach ([$org, $other] as $tenant) {
            $p = Product::factory()->create(['organization_id' => $tenant->id, 'name' => 'Тест']);
            $a = MarketplaceAccount::factory()->create(['organization_id' => $tenant->id, 'marketplace' => 'ozon', 'name' => '=account']);
            foreach ([0, 3, 4, 10] as $qty) {
                $a = MarketplaceAccount::factory()->create(['organization_id' => $tenant->id, 'marketplace' => 'ozon', 'name' => '=account']);
                MarketplaceListing::factory()->create(['organization_id' => $tenant->id, 'product_id' => $p->id, 'marketplace_account_id' => $a->id, 'external_id' => '=external'.$qty, 'stock_quantity' => $qty, 'price_minor' => 12345, 'status' => 'active']);
            }
            if ($tenant->id === $org->id) {
                $product = $p;
                $account = $a;
            }
        }
        $this->actingAs($user);
        $url = '/api/v1/stocks/export?search=Тест&marketplace=ozon&stock=low&status=active&sort=stock_quantity&direction=desc&per_page=1&product_id='.$product->id;
        $sheet = $this->sheet($url);
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertSame(4, $sheet->getCell('H2')->getValue());
        $this->assertSame(3, $sheet->getCell('H3')->getValue());
        $this->assertSame('=account', $sheet->getCell('E2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('F2')->getDataType());
        $this->assertEquals(123.45, $sheet->getCell('I2')->getValue());
        $this->assertSame('#,##0.00', $sheet->getStyle('I2')->getNumberFormat()->getFormatCode());
        $this->assertSame(2, $this->sheet('/api/v1/stocks/export?marketplace_account_id='.$account->id)->getHighestRow());
        $zero = $this->sheet('/api/v1/stocks/export?stock=zero');
        $this->assertSame(2, $zero->getHighestRow());
        $this->assertSame(0, $zero->getCell('H2')->getValue());
        $this->assertSame(1, $this->sheet('/api/v1/stocks/export?marketplace=wildberries')->getHighestRow());
    }

    public function test_exports_require_authentication_membership_and_policy_permission(): void
    {
        foreach (['products', 'stocks'] as $resource) {
            $this->getJson('/api/v1/'.$resource.'/export')->assertUnauthorized();
        }
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        foreach (['products', 'stocks'] as $resource) {
            $this->getJson('/api/v1/'.$resource.'/export')->assertForbidden();
        }
        $user->organizations()->attach(Organization::factory()->create());
        Gate::before(fn () => false);
        foreach (['products', 'stocks'] as $resource) {
            $this->getJson('/api/v1/'.$resource.'/export')->assertForbidden();
        }
    }

    public function test_exports_validate_filters_and_resource_sort_fields(): void
    {
        $user = User::factory()->create();
        $user->organizations()->attach(Organization::factory()->create());
        $this->actingAs($user);
        foreach (['products', 'stocks'] as $resource) {
            $this->getJson('/api/v1/'.$resource.'/export?direction=invalid')->assertUnprocessable();
        }
        $this->getJson('/api/v1/products/export?sort=stock_quantity')->assertUnprocessable();
        $this->getJson('/api/v1/stocks/export?sort=name')->assertUnprocessable();
        $this->assertSame(1, $this->sheet('/api/v1/products/export')->getHighestRow());
    }
}
