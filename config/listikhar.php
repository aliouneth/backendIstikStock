<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Penny universe filters
    |--------------------------------------------------------------------------
    | A tradable candidate must trade below the max price, above the min
    | price, show enough liquidity and have enough history for features.
    */
    'penny_max_price' => (float) env('LISTIKHAR_PENNY_MAX_PRICE', 5.0),
    'penny_min_price' => (float) env('LISTIKHAR_PENNY_MIN_PRICE', 0.05),
    'min_avg_volume' => (int) env('LISTIKHAR_MIN_AVG_VOLUME', 100000),
    'min_history_days' => (int) env('LISTIKHAR_MIN_HISTORY_DAYS', 70),
    'top_picks' => (int) env('LISTIKHAR_TOP_PICKS', 5),

    /*
    |--------------------------------------------------------------------------
    | Model hyper-parameters
    |--------------------------------------------------------------------------
    | Pure-PHP histogram gradient boosting (same family as LightGBM/XGBoost).
    | Swap ML\RegressorInterface implementation to move to another engine.
    */
    'model' => [
        'n_estimators' => (int) env('LISTIKHAR_MODEL_N_ESTIMATORS', 120),
        'learning_rate' => (float) env('LISTIKHAR_MODEL_LEARNING_RATE', 0.08),
        'max_depth' => (int) env('LISTIKHAR_MODEL_MAX_DEPTH', 3),
        'min_child_samples' => (int) env('LISTIKHAR_MODEL_MIN_CHILD_SAMPLES', 40),
        'subsample' => (float) env('LISTIKHAR_MODEL_SUBSAMPLE', 0.6),
        'l2_lambda' => (float) env('LISTIKHAR_MODEL_L2_LAMBDA', 1.0),
        'bins' => (int) env('LISTIKHAR_MODEL_BINS', 32),
        'max_samples' => (int) env('LISTIKHAR_MODEL_MAX_SAMPLES', 30000),
        'horizon_days' => (int) env('LISTIKHAR_MODEL_HORIZON_DAYS', 20),
        'retrain_days' => (int) env('LISTIKHAR_MODEL_RETRAIN_DAYS', 7),
        'random_seed' => (int) env('LISTIKHAR_MODEL_RANDOM_SEED', 42),
        'lookback_days' => 130,
        'path' => storage_path('app/model'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inference cache
    |--------------------------------------------------------------------------
    | Scoring the whole penny-stock universe takes a few seconds, so the
    | ranked result is reused for this long. It is keyed by trading date and
    | model version, so retraining or new data invalidates it automatically.
    */

    'score_cache_seconds' => (int) env('LISTIKHAR_SCORE_CACHE_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Data sources
    |--------------------------------------------------------------------------
    */
    'enable_live_refresh' => filter_var(env('LISTIKHAR_ENABLE_LIVE_REFRESH', true), FILTER_VALIDATE_BOOL),
    'fred_api_key' => env('LISTIKHAR_FRED_API_KEY'),

    'yahoo' => [
        'base_url' => 'https://query1.finance.yahoo.com',
        'timeout' => (int) env('LISTIKHAR_YAHOO_TIMEOUT', 8),
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Listikhar/1.0',
        // Wamp's PHP does not ship a CA bundle; we bundle the curl.se one.
        'ca_bundle' => is_file(base_path('certs/cacert.pem'))
            ? base_path('certs/cacert.pem')
            : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Real US penny universe (seed source)
    |--------------------------------------------------------------------------
    */
    'universe' => [
        'exchanges' => ['NASDAQ', 'NYSE', 'AMEX'],
        'seed_range' => env('LISTIKHAR_SEED_RANGE', '3y'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Synthetic historical dataset (demo/fallback — not tradable symbols)
    |--------------------------------------------------------------------------
    */
    'synthetic' => [
        'stocks' => (int) env('LISTIKHAR_SYNTHETIC_STOCKS', 1000),
        'seed' => (int) env('LISTIKHAR_SYNTHETIC_SEED', 20260926),
        'years' => (int) env('LISTIKHAR_SYNTHETIC_YEARS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | News surge scan (news-driven jump candidates)
    |--------------------------------------------------------------------------
    */
    'news' => [
        // Fresh headlines window (days) for a candidate to qualify.
        'lookback_days' => (int) env('LISTIKHAR_NEWS_LOOKBACK_DAYS', 3),
        // Model-top candidates fetched and checked for fresh news.
        'candidates' => (int) env('LISTIKHAR_NEWS_CANDIDATES', 60),
        // Results returned by the scan.
        'max_results' => (int) env('LISTIKHAR_NEWS_TOP', 10),
        // Target-price horizon (trading days) for "jump soon".
        'horizon_days' => (int) env('LISTIKHAR_NEWS_HORIZON_DAYS', 3),
        // Headlines kept per symbol.
        'max_headlines' => 6,
    ],

    'benchmark_symbol' => '^RUT',
];
