<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Translatable\HasTranslations;

class Product extends Model
{
    use HasTranslations;
    
    protected $fillable = ['sku', 'title', 'price', 'sale_price', 'weight', 'image'];

    public array $translatable = ['title'];
    /**
     * Many-to-many to branches with pivot (stock, stock_alert).
     * Matches how your InventoryService adjusts stock via $product->branches()->updateExistingPivot(...)
     */
    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'product_branch')
            ->withPivot(['stock', 'stock_alert'])
            ->withTimestamps();
    }

    /**
     * Optional convenience: direct hasMany to the pivot model if you use ProductBranch model elsewhere.
     */
    public function stocks()
    {
        return $this->hasMany(ProductBranch::class);
    }

    /** If you want a "inventories" alias for Filament repeater usage */
    public function inventories()
    {
        return $this->hasMany(ProductBranch::class, 'product_id');
    }

    public function stockFor(?int $branchId): ?ProductBranch
    {
        return $branchId ? $this->stocks()->where('branch_id', $branchId)->first() : null;
    }

    /** Products that are available (have a pivot row) for a branch */
    public function scopeForBranch(Builder $q, int $branchId): Builder
    {
        return $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
    }
}
