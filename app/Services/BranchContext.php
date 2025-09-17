<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

class BranchContext
{
    /** Returns current user's branch id, or null for admins/global users */
    public static function id(): ?int
    {
        $u = Auth::user();
        if (!$u) return null;

        // If user has a branch_id, we scope to it. If null => treat as admin/global.
        return $u->branch_id ?: null;
    }

    public static function isAdmin(): bool
    {
        $u = Auth::user();
        // If you use spatie/permission, replace with $u?->hasRole('admin')
        return $u && $u->branch_id === null;
    }
}
