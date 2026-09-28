<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['run_type', 'status', 'started_at', 'finished_at', 'metrics', 'notes'])]
class ModelRun extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metrics' => 'array',
        ];
    }

    public function succeed(array $metrics = [], ?string $notes = null): void
    {
        $this->fill([
            'status' => 'success',
            'finished_at' => now(),
            'metrics' => $metrics,
            'notes' => $notes,
        ])->save();
    }

    public function fail(string $notes): void
    {
        $this->fill([
            'status' => 'failed',
            'finished_at' => now(),
            'notes' => $notes,
        ])->save();
    }
}
