<?php

namespace App\Support;

class Features
{
    public const DAILY_ANALYSIS = 'daily_analysis';

    public const NEWS_SURGE = 'news_surge';

    public const TODAY_PICKS = 'today_picks';

    public const HISTORY = 'history';

    public const HISTORY_GRAPHS = 'history_graphs';

    public const MODEL_METRICS = 'model_metrics';

    public const FULL_RATIONALE = 'full_rationale';

    public const STOCK_LOOKUP = 'stock_lookup';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::DAILY_ANALYSIS => 'Run Daily Analysis',
            self::NEWS_SURGE => 'News Surge Scan',
            self::TODAY_PICKS => "Today's picks",
            self::HISTORY => 'Recommendation history',
            self::HISTORY_GRAPHS => 'Performance graphs',
            self::MODEL_METRICS => 'Model metrics panel',
            self::FULL_RATIONALE => 'Full rationale & key drivers',
            self::STOCK_LOOKUP => 'Single stock model lookup',
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::labels());
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * Normalise an arbitrary payload into a deduplicated list of known keys.
     *
     * @return list<string>
     */
    public static function sanitize(mixed $features): array
    {
        if (is_string($features)) {
            $decoded = json_decode($features, true);
            $features = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($features)) {
            return [];
        }

        $values = array_is_list($features) ? $features : array_values($features);

        $keys = [];
        foreach ($values as $value) {
            if (is_string($value) && self::isValid($value) && ! in_array($value, $keys, true)) {
                $keys[] = $value;
            }
        }

        return $keys;
    }
}
