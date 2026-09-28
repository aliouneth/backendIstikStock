<?php

namespace App\Services\Ml;

use InvalidArgumentException;
use RuntimeException;

/**
 * Histogram-based gradient boosted regression trees (squared-error objective),
 * implemented in pure PHP following the same algorithm family as LightGBM /
 * XGBoost: features are binned into quantile buckets once, each tree is grown
 * by scanning gradient/hessian histograms per node, trees are added with a
 * learning rate, with row subsampling and L2 leaf regularization.
 *
 * In-sample bookkeeping: predictions are updated in place; honest metrics are
 * produced by the caller via evaluate() on a chronological hold-out set.
 */
final class GradientBoostingRegressor implements RegressorInterface
{
    /** @var array<string, mixed> */
    private array $p;

    /** @var list<string> */
    private array $featureNames = [];

    /** @var array<string, list<float>> quantile edges per feature (bins+1 values) */
    private array $edges = [];

    /** @var list<array<string, mixed>> trained trees (nested nodes) */
    private array $trees = [];

    /** @var array<string, float> */
    private array $importance = [];

    private float $baseScore = 0.0;

    private string $version = '';

    private string $trainedAt = '';

    /** @param array<string, mixed> $params */
    public function __construct(array $params = [])
    {
        $defaults = config('listikhar.model', []);
        $this->p = array_merge([
            'n_estimators' => $defaults['n_estimators'] ?? 120,
            'learning_rate' => $defaults['learning_rate'] ?? 0.08,
            'max_depth' => $defaults['max_depth'] ?? 3,
            'min_child_samples' => $defaults['min_child_samples'] ?? 40,
            'subsample' => $defaults['subsample'] ?? 0.6,
            'l2_lambda' => $defaults['l2_lambda'] ?? 1.0,
            'bins' => $defaults['bins'] ?? 32,
            'random_seed' => $defaults['random_seed'] ?? 42,
        ], $params);
    }

    public function train(array $X, array $y, ?callable $onProgress = null): array
    {
        $n = count($y);
        if ($n < 100) {
            throw new InvalidArgumentException('Need at least 100 training samples, got '.$n);
        }
        foreach ($X as $name => $col) {
            if (count($col) !== $n) {
                throw new InvalidArgumentException("Feature {$name} length mismatch");
            }
        }

        $this->featureNames = array_keys($X);
        $this->edges = [];
        $this->trees = [];
        $this->importance = array_fill_keys($this->featureNames, 0.0);

        // 1) Quantile-bin every feature once (feature matrix -> uint8-style bins).
        $bins = [];
        foreach ($this->featureNames as $fi => $name) {
            [$edges, $b] = $this->binColumn($X[$name], $this->p['bins']);
            $this->edges[$name] = $edges;
            $bins[$fi] = $b;
        }

        // 2) Initialize with the mean target.
        $this->baseScore = array_sum($y) / $n;
        $pred = array_fill(0, $n, $this->baseScore);

        $lr = (float) $this->p['learning_rate'];
        $sub = (float) $this->p['subsample'];
        $seed = (int) $this->p['random_seed'];
        $m = (int) $this->p['n_estimators'];

        $started = microtime(true);

        // 3) Boosting rounds.
        for ($t = 0; $t < $m; $t++) {
            $sample = $this->subsample($n, $sub, $seed + $t);

            $grad = [];
            foreach ($sample as $i) {
                $grad[$i] = $pred[$i] - $y[$i];
            }

            $tree = $this->growNode($bins, $grad, $sample, 0);
            $this->trees[] = $tree;

            // Apply the fitted tree to every row (stochastic boosting uses the
            // subsample only for split *finding*).
            $this->applyTree($tree, $bins, $pred, $lr, $n);

            if ($onProgress !== null) {
                $onProgress($t + 1, $m);
            }
        }

        $totalGain = array_sum($this->importance);
        if ($totalGain > 0) {
            foreach ($this->importance as $f => $g) {
                $this->importance[$f] = $g / $totalGain;
            }
        }

        $this->trainedAt = now()->toIso8601String();
        $this->version = substr(md5(json_encode($this->p).'|'.implode(',', $this->featureNames).'|'.$this->trainedAt), 0, 12);

        return [
            'samples' => $n,
            'features' => count($this->featureNames),
            'trees' => count($this->trees),
            'fit_seconds' => round(microtime(true) - $started, 2),
            'base_score' => round($this->baseScore, 6),
        ];
    }

    /**
     * Honest metrics against a hold-out set.
     *
     * @param  array<string, list<float>>  $X
     * @param  list<float>  $y
     * @return array<string, float>
     */
    public function evaluate(array $X, array $y): array
    {
        $pred = $this->predictMatrix($X);

        $n = count($y);
        if ($n === 0) {
            return [];
        }

        $sumErr = 0.0;
        $sumSqErr = 0.0;
        $sumAbs = 0.0;
        $meanY = array_sum($y) / $n;

        for ($i = 0; $i < $n; $i++) {
            $e = $pred[$i] - $y[$i];
            $sumErr += $e;
            $sumSqErr += $e * $e;
            $sumAbs += abs($e);
        }

        $ssTot = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $d = $y[$i] - $meanY;
            $ssTot += $d * $d;
        }

        $rmse = sqrt($sumSqErr / $n);

        return [
            'n' => $n,
            'rmse' => round($rmse, 6),
            'mae' => round($sumAbs / $n, 6),
            'r2' => round($ssTot > 0 ? 1 - ($sumSqErr / $ssTot) : 0.0, 6),
            'pearson' => round(self::pearson($y, $pred), 6),
            'spearman' => round(self::spearman($y, $pred), 6),
            'mean_pred' => round(array_sum($pred) / $n, 6),
            'mean_actual' => round($meanY, 6),
        ];
    }

    public function predictRow(array $features): float
    {
        $value = $this->baseScore;
        $lr = (float) $this->p['learning_rate'];

        foreach ($this->trees as $tree) {
            $node = $tree;
            while (isset($node['f'])) {
                $f = $node['f'];
                $name = $this->featureNames[$f];
                $x = $features[$name] ?? 0.0;
                $bin = self::binIndex($x, $this->edges[$name]);
                $node = $bin <= $node['b'] ? $node['l'] : $node['r'];
            }
            $value += $lr * $node['v'];
        }

        return $value;
    }

    public function predictMatrix(array $X): array
    {
        $names = $this->featureNames;
        $count = count($X[$names[0]] ?? []);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $row = [];
            foreach ($names as $name) {
                $row[$name] = $X[$name][$i];
            }
            $out[] = $this->predictRow($row);
        }

        return $out;
    }

    public function explainRow(array $features): array
    {
        $contrib = array_fill_keys($this->featureNames, 0.0);
        $lr = (float) $this->p['learning_rate'];

        foreach ($this->trees as $tree) {
            $node = $tree;
            while (isset($node['f'])) {
                $f = $node['f'];
                $name = $this->featureNames[$f];
                $x = $features[$name] ?? 0.0;
                $bin = self::binIndex($x, $this->edges[$name]);
                $child = $bin <= $node['b'] ? $node['l'] : $node['r'];
                $contrib[$name] += ($child['v'] - $node['v']) * $lr;
                $node = $child;
            }
        }

        return $contrib;
    }

    public function featureNames(): array
    {
        return $this->featureNames;
    }

    public function importance(): array
    {
        return $this->importance;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function trainedAt(): string
    {
        return $this->trainedAt;
    }

    public function baseScore(): float
    {
        return $this->baseScore;
    }

    public function toArray(): array
    {
        return [
            'engine' => 'php_gbm_histogram_v1',
            'params' => $this->p,
            'features' => $this->featureNames,
            'edges' => $this->edges,
            'trees' => $this->trees,
            'importance' => $this->importance,
            'base_score' => $this->baseScore,
            'version' => $this->version,
            'trained_at' => $this->trainedAt,
        ];
    }

    public static function fromArray(array $data): static
    {
        $model = new self($data['params'] ?? []);
        $model->featureNames = array_values($data['features'] ?? []);
        $model->edges = $data['edges'] ?? [];
        $model->trees = $data['trees'] ?? [];
        $model->importance = $data['importance'] ?? [];
        $model->baseScore = (float) ($data['base_score'] ?? 0.0);
        $model->version = (string) ($data['version'] ?? '');
        $model->trainedAt = (string) ($data['trained_at'] ?? '');

        if ($model->featureNames === [] || $model->trees === []) {
            throw new RuntimeException('Model artifact is empty or corrupt.');
        }

        return $model;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param  list<float>  $values
     * @return array{0: list<float>, 1: list<int>} edges and per-row bin indices
     */
    private function binColumn(array $values, int $nbins): array
    {
        $sorted = $values;
        sort($sorted, SORT_NUMERIC);
        $n = count($sorted);

        $edges = [];
        for ($b = 0; $b <= $nbins; $b++) {
            $pos = (int) floor(($b / $nbins) * ($n - 1));
            $edges[] = (float) $sorted[$pos];
        }
        // Make edges strictly increasing.
        for ($b = 1; $b <= $nbins; $b++) {
            if ($edges[$b] <= $edges[$b - 1]) {
                $edges[$b] = $edges[$b - 1] + 1e-9;
            }
        }

        $binned = [];
        foreach ($values as $v) {
            $binned[] = self::binIndex($v, $edges);
        }

        return [$edges, $binned];
    }

    /** @param  list<float>  $edges */
    private static function binIndex(float $x, array $edges): int
    {
        $hi = count($edges) - 1; // last edge index == bins
        if ($x <= $edges[0]) {
            return 0;
        }
        if ($x >= $edges[$hi]) {
            return $hi - 1;
        }

        $lo = 0;
        while ($lo < $hi - 1) {
            $mid = intdiv($lo + $hi, 2);
            if ($x <= $edges[$mid]) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        return $lo;
    }

    /** @return list<int> */
    private function subsample(int $n, float $ratio, int $seed): array
    {
        $count = (int) max(2, floor($n * $ratio));
        if ($count >= $n) {
            return range(0, $n - 1);
        }

        $idx = range(0, $n - 1);
        mt_srand($seed);
        // Partial Fisher-Yates: only the first $count positions matter.
        for ($i = 0; $i < $count; $i++) {
            $j = mt_rand($i, $n - 1);
            $tmp = $idx[$i];
            $idx[$i] = $idx[$j];
            $idx[$j] = $tmp;
        }

        return array_slice($idx, 0, $count);
    }

    /**
     * @param  array<int, array<int, list<int>>>  $bins  feature => per-row bin indices
     * @param  array<int, float>  $grad  row => gradient (only sampled rows present)
     * @param  list<int>  $idx
     * @return array<string, mixed>
     */
    private function growNode(array $bins, array $grad, array $idx, int $depth): array
    {
        $h = count($idx);
        $g = 0.0;
        foreach ($idx as $i) {
            $g += $grad[$i];
        }

        $lambda = (float) $this->p['l2_lambda'];
        $nodeValue = -$g / ($h + $lambda);

        $maxDepth = (int) $this->p['max_depth'];
        $minChild = (int) $this->p['min_child_samples'];

        if ($depth >= $maxDepth || $h < 2 * $minChild) {
            return ['v' => $nodeValue];
        }

        $nbins = (int) $this->p['bins'];
        $bestGain = 1e-8;
        $bestFeature = -1;
        $bestBin = -1;

        foreach ($this->featureNames as $f => $name) {
            $colBins = $bins[$f];

            $gSum = array_fill(0, $nbins, 0.0);
            $hSum = array_fill(0, $nbins, 0);

            foreach ($idx as $i) {
                $b = $colBins[$i];
                $gSum[$b] += $grad[$i];
                $hSum[$b]++;
            }

            $gl = 0.0;
            $hl = 0;
            for ($b = 0; $b < $nbins - 1; $b++) {
                $gl += $gSum[$b];
                $hl += $hSum[$b];
                $hr = $h - $hl;
                if ($hl < $minChild || $hr < $minChild) {
                    continue;
                }
                $gr = $g - $gl;
                $gain = 0.5 * (($gl * $gl) / ($hl + $lambda) + ($gr * $gr) / ($hr + $lambda) - ($g * $g) / ($h + $lambda));
                if ($gain > $bestGain) {
                    $bestGain = $gain;
                    $bestFeature = $f;
                    $bestBin = $b;
                }
            }
        }

        if ($bestFeature < 0) {
            return ['v' => $nodeValue];
        }

        $this->importance[$this->featureNames[$bestFeature]] =
            ($this->importance[$this->featureNames[$bestFeature]] ?? 0.0) + $bestGain;

        $leftIdx = [];
        $rightIdx = [];
        $colBins = $bins[$bestFeature];
        foreach ($idx as $i) {
            if ($colBins[$i] <= $bestBin) {
                $leftIdx[] = $i;
            } else {
                $rightIdx[] = $i;
            }
        }

        if (count($leftIdx) < $minChild || count($rightIdx) < $minChild) {
            return ['v' => $nodeValue];
        }

        return [
            'f' => $bestFeature,
            'b' => $bestBin,
            'v' => $nodeValue,
            'l' => $this->growNode($bins, $grad, $leftIdx, $depth + 1),
            'r' => $this->growNode($bins, $grad, $rightIdx, $depth + 1),
        ];
    }

    /**
     * @param  array<int, array<int, list<int>>>  $bins
     * @param  list<float>  $pred
     */
    private function applyTree(array $tree, array $bins, array &$pred, float $lr, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $node = $tree;
            while (isset($node['f'])) {
                $node = $bins[$node['f']][$i] <= $node['b'] ? $node['l'] : $node['r'];
            }
            $pred[$i] += $lr * $node['v'];
        }
    }

    /** @param  list<float>  $a  @param  list<float>  $b */
    private static function pearson(array $a, array $b): float
    {
        $n = count($a);
        if ($n < 2) {
            return 0.0;
        }
        $ma = array_sum($a) / $n;
        $mb = array_sum($b) / $n;
        $num = 0.0;
        $da = 0.0;
        $db = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $xa = $a[$i] - $ma;
            $xb = $b[$i] - $mb;
            $num += $xa * $xb;
            $da += $xa * $xa;
            $db += $xb * $xb;
        }
        $den = sqrt($da * $db);

        return $den > 0 ? $num / $den : 0.0;
    }

    /** @param  list<float>  $a  @param  list<float>  $b */
    private static function spearman(array $a, array $b): float
    {
        return self::pearson(self::ranks($a), self::ranks($b));
    }

    /**
     * Average ranks (1-based) with tie handling.
     *
     * @param  list<float>  $values
     * @return list<float>
     */
    private static function ranks(array $values): array
    {
        $n = count($values);
        $order = range(0, $n - 1);
        usort($order, fn ($i, $j) => $values[$i] <=> $values[$j]);

        $ranks = array_fill(0, $n, 0.0);
        $i = 0;
        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && $values[$order[$j + 1]] == $values[$order[$i]]) {
                $j++;
            }
            $avg = ($i + $j) / 2 + 1;
            for ($k = $i; $k <= $j; $k++) {
                $ranks[$order[$k]] = $avg;
            }
            $i = $j + 1;
        }

        return $ranks;
    }
}
