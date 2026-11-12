<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Warehouse extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['name', 'code', 'type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function rules(?int $organizationId, ?int $id = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('warehouses')->where('organization_id', $organizationId)->ignore($id)],
            'type' => ['required', Rule::in(['own', 'marketplace'])],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function validateBusinessRules(): void
    {
        $this->name = trim($this->name);
        $this->code = strtoupper(trim($this->code));
        Validator::make($this->getAttributes(), self::rules((int) $this->organization_id, $this->id))->validate();
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
