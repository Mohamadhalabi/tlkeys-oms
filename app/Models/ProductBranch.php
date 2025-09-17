<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBranch extends Model
{
    protected $table = 'product_branch';

    protected $fillable = ['product_id','branch_id','stock','stock_alert'];

    public function product() { return $this->belongsTo(Product::class); }
    public function branch()  { return $this->belongsTo(Branch::class); }
}
