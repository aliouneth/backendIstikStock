<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ticker',
    'name',
    'sector',
    'industry',
    'exchange',
    'market_cap',
    'float_shares',
    'avg_volume',
    'last_price',
    'is_penny',
    'source',
    'meta',
])]
class Stock extends Model
{
    protected function casts(): array
    {
        return [
            'market_cap' => 'integer',
            'float_shares' => 'integer',
            'avg_volume' => 'integer',
            'last_price' => 'float',
            'is_penny' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(StockPrice::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CorporateEvent::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(DailyRecommendation::class);
    }
}
