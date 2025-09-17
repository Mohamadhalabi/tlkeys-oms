<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = ['code','name'];

    public function users() { return $this->hasMany(User::class); }

    public function productStocks() { return $this->hasMany(ProductBranch::class); }
}
