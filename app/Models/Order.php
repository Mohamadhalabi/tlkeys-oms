<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['branch_id','customer_id','seller_id','type','status','subtotal','discount','total'];

    public function items()
    { 
        return $this->hasMany(OrderItem::class); 
    }

    public function branch()
    { 
        return $this->belongsTo(Branch::class); 
    }

    public function customer()
    { 
        return $this->belongsTo(Customer::class); 
    }
    
    public function seller()
    { 
        return $this->belongsTo(User::class,'seller_id'); 
    }
}
