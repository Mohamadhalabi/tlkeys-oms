<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Translatable\HasTranslations;

class Product extends Model
{
    use HasTranslations;

    protected $fillable = ['sku', 'title', 'price', 'sale_price', 'weight', 'image','cost_price'];
    public array $translatable = ['title'];

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'product_branch')
            ->withPivot(['stock', 'stock_alert'])
            ->withTimestamps();
    }

    public function stocks()
    {
        return $this->hasMany(ProductBranch::class);
    }

    public function inventories()
    {
        return $this->hasMany(ProductBranch::class, 'product_id');
    }

    public function stockFor(?int $branchId): ?ProductBranch
    {
        return $branchId ? $this->stocks()->where('branch_id', $branchId)->first() : null;
    }

    public function scopeForBranch(Builder $q, int $branchId): Builder
    {
        return $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
    }

    /** A reliable image source for mPDF (local paths preferred). */
    public function pdfImageSrc(): ?string
    {
        // placeholder (optional)
        $placeholder = public_path('images/placeholder-120.png');
        $asFileUrl = fn(string $p) => (str_starts_with($p, 'file://') ? $p : 'file://' . $p);

        if (!$this->image) {
            return is_file($placeholder) ? $asFileUrl($placeholder) : null;
        }

        // Relative path in storage/app/public/...
        if (!preg_match('~^https?://~i', $this->image)) {
            $local = public_path('storage/' . ltrim($this->image, '/'));
            if (is_file($local)) {
                return $asFileUrl($local);
            }
            return is_file($placeholder) ? $asFileUrl($placeholder) : null;
        }

        // Remote URL (only if you enabled enable_remote=true). Otherwise try placeholder.
        return $this->image;
    }
}
