<?php

namespace App\Services\Data;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal Yahoo Finance v8 chart client (no API key required).
 * Used for real OHLCV history and spot prices of the tradable universe,
 * plus index/VIX series for the macro environment.
 */
class YahooFinanceClient
{
    private Client $http;

    public function __construct()
    {
        $cfg = config('listikhar.yahoo', []);
        $this->http = new Client([
            'base_uri' => $cfg['base_url'] ?? 'https://query1.finance.yahoo.com',
            'timeout' => $cfg['timeout'] ?? 8,
            'verify' => $cfg['ca_bundle'] ?? true,
            'headers' => [
                'User-Agent' => $cfg['user_agent'] ?? 'Mozilla/5.0',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Underlying Guzzle client (for batch/async fetches that need to share
     * the same headers, CA bundle and timeout).
     */
    public function httpClient(): Client
    {
        return $this->http;
    }

    /**
     * Generic GET against an absolute URL (other keyless data APIs reuse
     * this client's headers/CA bundle).
     *
     * @param  array<string, mixed>  $options
     * @return ResponseInterface
     */
    public function rawGet(string $url, array $options = [])
    {
        return $this->http->get($url, $options);
    }

    /**
     * @return array{bars: list<array{date: string, open: float, high: float, low: float, close: float, volume: int}>, meta: array<string, mixed>, splits: list<array{date: string, ratio: string}>}|null
     */
    public function chart(string $symbol, string $range = '1y', string $interval = '1d', bool $withSplits = false): ?array
    {
        $query = ['range' => $range, 'interval' => $interval];
        if ($withSplits) {
            $query['events'] = 'split';
        }

        try {
            $response = $this->http->get('/v8/finance/chart/'.rawurlencode($symbol), [
                'query' => $query,
            ]);
            $payload = json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            Log::warning('Yahoo chart fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);

            return null;
        }

        $result = $payload['chart']['result'][0] ?? null;
        if ($result === null || ! isset($result['timestamp'])) {
            Log::warning('Yahoo chart empty response', ['symbol' => $symbol]);

            return null;
        }

        $meta = $result['meta'] ?? [];
        $timestamps = $result['timestamp'];
        $quote = $result['indicators']['quote'][0] ?? [];

        $bars = [];
        foreach ($timestamps as $i => $ts) {
            $close = $quote['close'][$i] ?? null;
            if ($close === null) {
                continue; // halted / missing session
            }
            $bars[] = [
                'date' => gmdate('Y-m-d', (int) $ts),
                'open' => (float) ($quote['open'][$i] ?? $close),
                'high' => (float) ($quote['high'][$i] ?? $close),
                'low' => (float) ($quote['low'][$i] ?? $close),
                'close' => (float) $close,
                'volume' => (int) ($quote['volume'][$i] ?? 0),
            ];
        }

        $splits = [];
        foreach (($result['events']['splits'] ?? []) as $split) {
            $splits[] = [
                'date' => gmdate('Y-m-d', (int) $split['date']),
                'ratio' => (string) ($split['splitRatio'] ?? ''),
            ];
        }

        return ['bars' => $bars, 'meta' => $meta, 'splits' => $splits];
    }

    /** Latest regular-market price, or null on failure. */
    public function price(string $symbol): ?float
    {
        $chart = $this->chart($symbol, '5d', '1d');
        if ($chart === null || $chart['bars'] === []) {
            return null;
        }

        return (float) $chart['bars'][count($chart['bars']) - 1]['close'];
    }
}
