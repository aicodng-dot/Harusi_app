<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = ['name'];

    public function members(): BelongsToMany
    {
        // This relation exposes membership data. Authorization must separately
        // check the account, active membership and permitted action.
        return $this->belongsToMany(User::class, 'workspace_users')
            ->withPivot(['id', 'role', 'is_active'])->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
