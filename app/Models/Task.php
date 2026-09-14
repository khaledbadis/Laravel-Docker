<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Ownership foundation. Persistence and task fields are implemented in Phase 5.
class Task extends Model
{
    protected function casts(): array
    {
        return ['user_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
