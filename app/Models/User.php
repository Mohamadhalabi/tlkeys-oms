<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'branch_id',
        'is_active',
        'can_see_cost',
        'can_sell_below_cost',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'   => 'datetime',
        'password'            => 'hashed',
        'is_active'           => 'bool',
        'can_see_cost'        => 'bool',
        'can_sell_below_cost' => 'bool',
    ];

    public function branch()
    {
        return $this->belongsTo(\App\Models\Branch::class);
    }
    
    public function canAccessPanel(Panel $panel): bool
    {
    // current user should have Role to acces for exemple.
    return true;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }
}
