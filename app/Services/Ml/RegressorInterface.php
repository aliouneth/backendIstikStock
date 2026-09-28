<?php

namespace App\Services\Ml;

/**
 * Contract for the regression engines used to predict forward penny-stock
 * returns. Implementations: GradientBoostingRegressor (pure PHP, default).
 *
 * To move to XGBoost/LightGBM later, add an adapter implementing this
 * interface (e.g. shell out to a Python sidecar) and swap it in
 * App\Services\Ml\ModelRepository::makeRegressor().
 */
interface RegressorInterface
{
    /**
     * Train on a column-oriented feature matrix.
     *
     * @param  array<string, list<float>>  $X  feature name => values (same order as samples)
     * @param  list<float>  $y  target values (forward log return)
     * @return array<string, float> training bookkeeping metrics
     */
    public function train(array $X, array $y, ?callable $onProgress = null): array;

    /** Predict a single sample given an assoc feature => value map. */
    public function predictRow(array $features): float;

    /** Predict many samples (column-oriented, same shape as train input). */
    public function predictMatrix(array $X): array;

    /**
     * Per-feature contribution breakdown for one sample (sums to predictRow
     * minus base value). Used for recommendation rationale.
     *
     * @return array<string, float>
     */
    public function explainRow(array $features): array;

    /** Ordered list of feature names the model was trained on. */
    public function featureNames(): array;

    /** @return array<string, float> normalized feature importance */
    public function importance(): array;

    public function version(): string;

    public function trainedAt(): string;

    public function toArray(): array;

    public static function fromArray(array $data): static;
}
