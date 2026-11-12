<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;

class Supplier extends Model
{
    use BelongsToOrganization;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'contact_name', 'phone', 'email', 'inn', 'comment', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email:rfc', 'max:255'], 'inn' => ['nullable', 'string', 'max:30'],
            'comment' => ['nullable', 'string', 'max:5000'], 'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function validateBusinessRules(): void
    {
        $this->name = trim($this->name);
        Validator::make($this->getAttributes(), self::rules())->validate();
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
