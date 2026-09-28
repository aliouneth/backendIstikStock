<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'observation_date',
    'fed_funds_rate',
    'cpi_yoy',
    'sp500_close',
    'nasdaq_close',
    'russell2000_close',
    'vix_close',
    'sector_sentiment',
    'source',
])]
class MacroEnvironment extends Model
{
    protected $table = 'macro_environment';

    protected function casts(): array
    {
        return [
            'observation_date' => 'date',
            'fed_funds_rate' => 'float',
            'cpi_yoy' => 'float',
            'sp500_close' => 'float',
            'nasdaq_close' => 'float',
            'russell2000_close' => 'float',
            'vix_close' => 'float',
            'sector_sentiment' => 'array',
        ];
    }
}
