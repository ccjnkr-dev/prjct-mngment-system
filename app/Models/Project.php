<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $guarded = ['id'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function getProgressAttribute(): int
    {
        $tasks = $this->tasks->where('milestone', false);

        return $tasks->count() ? (int) round(100 * $tasks->where('status', 'done')->count() / $tasks->count()) : 0;
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->role === 'admin' ? $q : $q->whereHas('members', fn ($m) => $m->where('users.id', $user->id));
    }
}
