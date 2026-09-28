<?php

namespace App\Services\Ml;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Persistence for the trained model artifact + metadata.
 *
 * Files (storage/app/model/):
 *   gbm.json       full artifact (params, bin edges, trees, importances)
 *   gbm.meta.json  human-readable metadata (metrics, window, version)
 */
class ModelRepository
{
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('listikhar.model', []);
    }

    public static function make(): self
    {
        return new self(config('listikhar.model'));
    }

    public function directory(): string
    {
        return rtrim($this->config['path'], '/\\');
    }

    public function modelPath(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'gbm.json';
    }

    public function metaPath(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'gbm.meta.json';
    }

    public function exists(): bool
    {
        return File::exists($this->modelPath());
    }

    public function isStale(): bool
    {
        if (! $this->exists()) {
            return true;
        }

        $meta = $this->meta();
        if (! $meta || ! isset($meta['trained_at'])) {
            return true;
        }

        $trainedAt = new \DateTimeImmutable($meta['trained_at']);
        $maxAge = (int) ($this->config['retrain_days'] ?? 7);

        return $trainedAt < new \DateTimeImmutable("-{$maxAge} days");
    }

    public function load(): ?RegressorInterface
    {
        if (! $this->exists()) {
            return null;
        }

        $data = json_decode(File::get($this->modelPath()), true);
        if (! is_array($data)) {
            throw new RuntimeException('Model artifact is not valid JSON: '.$this->modelPath());
        }

        return GradientBoostingRegressor::fromArray($data);
    }

    public function loadOrFail(): RegressorInterface
    {
        $model = $this->load();
        if ($model === null) {
            throw new RuntimeException('No trained model found. Run: php artisan listikhar:train');
        }

        return $model;
    }

    /** @return array<string, mixed>|null */
    public function meta(): ?array
    {
        if (! File::exists($this->metaPath())) {
            return null;
        }

        $data = json_decode(File::get($this->metaPath()), true);

        return is_array($data) ? $data : null;
    }

    /** @param  array<string, mixed>  $meta */
    public function save(RegressorInterface $model, array $meta = []): void
    {
        File::ensureDirectoryExists($this->directory());

        File::put($this->modelPath(), json_encode($model->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR));

        File::put($this->metaPath(), json_encode(array_merge([
            'engine' => 'php_gbm_histogram_v1',
            'version' => $model->version(),
            'trained_at' => $model->trainedAt(),
            'features' => $model->featureNames(),
            'importance' => $model->importance(),
        ], $meta), JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    public function makeRegressor(): RegressorInterface
    {
        return new GradientBoostingRegressor($this->config);
    }
}
