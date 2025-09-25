<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = ['code','name'];

    public function users() { return $this->hasMany(User::class); }

    public function productStocks() { return $this->hasMany(ProductBranch::class); }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_branch')
            ->withPivot(['stock','stock_alert'])
            ->withTimestamps();
    }

    public function inventories()
    {
        return $this->hasMany(ProductBranch::class, 'branch_id');
    }
}
