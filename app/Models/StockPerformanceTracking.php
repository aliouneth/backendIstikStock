<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'recommendation_id',
    'stock_id',
    'track_date',
    'close_price',
    'pct_change_since_recommendation',
    'benchmark_pct_change',
])]
class StockPerformanceTracking extends Model
{
    protected $table = 'stock_performance_tracking';

    protected function casts(): array
    {
        return [
            'track_date' => 'date',
            'close_price' => 'float',
            'pct_change_since_recommendation' => 'float',
            'benchmark_pct_change' => 'float',
        ];
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(DailyRecommendation::class, 'recommendation_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
