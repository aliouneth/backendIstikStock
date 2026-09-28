<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_id', 'event_date', 'event_type', 'title', 'description', 'meta'])]
class CorporateEvent extends Model
{
    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'meta' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
