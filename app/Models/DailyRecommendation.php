<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'recommendation_date',
    'stock_id',
    'rank',
    'model_score',
    'price_at_recommendation',
    'rationale',
    'features',
    'model_version',
])]
class DailyRecommendation extends Model
{
    protected function casts(): array
    {
        return [
            'recommendation_date' => 'date',
            'rank' => 'integer',
            'model_score' => 'float',
            'price_at_recommendation' => 'float',
            'features' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function performance(): HasMany
    {
        return $this->hasMany(StockPerformanceTracking::class, 'recommendation_id');
    }
}
