<?php

namespace App\Services\News;

use App\Services\Data\YahooFinanceClient;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;

/**
 * Fresh per-symbol headlines from keyless Yahoo sources:
 *  1. Yahoo Finance search API (symbol-scoped news, timestamps, links)
 *  2. Yahoo Finance symbol RSS feed (fallback)
 *
 * Google News RSS was rejected: short tickers (COSM, CAN…) match unrelated
 * companies ("Cosmo Oil", "Cosmos Yatirim") and poison the scan.
 */
class NewsClient
{
    public function __construct(private readonly YahooFinanceClient $yahoo) {}

    /**
     * Batch headlines for many tickers, fetched with a bounded
     * concurrency pool (≈8× faster than sequential).
     *
     * @param  list<string>  $tickers
     * @return array<string, list<array{title: string, publisher: string, url: string, published_at: string, age_hours: float}>>
     */
    public function headlinesMany(array $tickers, int $lookbackDays): array
    {
        $tickers = array_values($tickers);
        $cutoff = time() - $lookbackDays * 86400;
        $maxHeadlines = (int) config('listikhar.news.max_headlines', 6);

        $requests = function () use ($tickers) {
            foreach ($tickers as $ticker) {
                yield new Request('GET',
                    'https://query1.finance.yahoo.com/v1/finance/search?'.http_build_query([
                        'q' => $ticker,
                        'newsCount' => 12,
                        'quotesCount' => 0,
                        'listsCount' => 0,
                    ]));
            }
        };

        $raw = [];
        $pool = new Pool($this->yahoo->httpClient(), $requests(), [
            'concurrency' => 8,
            'fulfilled' => function ($response, $index) use (&$raw, $tickers) {
                $raw[$tickers[$index]] = json_decode((string) $response->getBody(), true);
            },
            'rejected' => function ($reason, $index) use (&$raw, $tickers) {
                $raw[$tickers[$index]] = null;
            },
        ]);
        $pool->promise()->wait();

        $out = [];
        foreach ($tickers as $ticker) {
            $items = $this->parseSearch($raw[$ticker] ?? null, $ticker, $cutoff);
            if ($items === []) {
                $items = $this->fromRss($ticker, $cutoff);
            }
            usort($items, fn ($a, $b) => $b['age_hours'] <=> $a['age_hours']);
            $out[$ticker] = array_slice($items, 0, $maxHeadlines);
        }

        return $out;
    }

    /**
     * @return list<array{title: string, publisher: string, url: string, published_at: string, age_hours: float}>
     */
    private function parseSearch(?array $payload, string $ticker, int $cutoff): array
    {
        $out = [];
        foreach ($payload['news'] ?? [] as $news) {
            $ts = (int) ($news['providerPublishTime'] ?? 0);
            if ($ts < $cutoff) {
                continue;
            }

            // When Yahoo names the related symbols, require ours to be one.
            $related = $news['relatedTickers'] ?? null;
            if (is_array($related) && $related !== []
                && ! in_array(strtoupper($ticker), array_map('strtoupper', $related), true)) {
                continue;
            }

            $title = trim((string) ($news['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $out[] = [
                'title' => $title,
                'publisher' => (string) ($news['publisher'] ?? ''),
                'url' => (string) ($news['link'] ?? $news['url'] ?? ''),
                'published_at' => gmdate('c', $ts),
                'age_hours' => round((time() - $ts) / 3600, 1),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{title: string, publisher: string, url: string, published_at: string, age_hours: float}>
     */
    private function fromRss(string $ticker, int $cutoff): array
    {
        $url = 'https://feeds.finance.yahoo.com/rss/2.0/headline?'
            .http_build_query(['s' => $ticker, 'region' => 'US', 'lang' => 'en-US']);

        try {
            $response = $this->yahoo->rawGet($url);
            $xml = simplexml_load_string((string) $response->getBody());
        } catch (\Throwable $e) {
            Log::debug('Yahoo RSS news failed', ['ticker' => $ticker, 'error' => $e->getMessage()]);

            return [];
        }

        if ($xml === false) {
            return [];
        }

        $out = [];
        foreach ($xml->channel->item ?? [] as $item) {
            $ts = strtotime((string) $item->pubDate);
            if ($ts === false || $ts < $cutoff) {
                continue;
            }

            $out[] = [
                'title' => trim((string) $item->title),
                'publisher' => trim((string) ($item->source ?? '')),
                'url' => (string) $item->link,
                'published_at' => gmdate('c', $ts),
                'age_hours' => round((time() - $ts) / 3600, 1),
            ];
        }

        return $out;
    }
}
