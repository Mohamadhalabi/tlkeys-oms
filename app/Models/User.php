<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
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
        return $this->belongsTo(Branch::class);
    }

    /**
     * Grant access to Filament panels based on role or permission.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->hasRole('admin'),
            'seller' => $this->hasRole('seller'),
            default => false,
        };
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }
}
