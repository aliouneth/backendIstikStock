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
 * Relevance is the hard part. Searching Yahoo by a bare ticker is a full-text
 * query, so a 3-letter symbol matches the English word: `q=CAN` (Canaan Inc.)
 * returns "Can Costco Absorb Rising Costs…" and "Retailers Can Get More Value
 * Back" — none of which are about the company. Three defences:
 *
 *  - search by the company name when we have one, falling back to the ticker;
 *  - require the headline to name the company — our symbol *in uppercase*
 *    ("Canaan (CAN)") or a distinctive word from its name. Case matters: the
 *    English verb is written "Can It Continue?", never "CAN";
 *  - let Yahoo's relatedTickers only veto, never admit, because that field
 *    fuzzy-matches names (Carrefour comes back tagged CAN, "Cosmos Health"
 *    tagged COSM).
 *
 * Google News RSS was rejected for the same reason: short tickers (CAN, COSM…)
 * match unrelated companies ("Cosmo Oil", "Carrefour").
 */
class NewsClient
{
    /**
     * Tokens dropped from a company name when deriving distinctive phrases.
     *
     * @var list<string>
     */
    private const COMPANY_SUFFIXES = [
        'inc', 'inc.', 'corp', 'corp.', 'corporation', 'ltd', 'ltd.', 'plc',
        'co', 'co.', 'company', 'holdings', 'holding', 'group', 'the', 'and',
        'of', 'llc', 'lp', 'sa', 'ag', 'nv', 'se', 'as', 'oy', 'ab', 'a.s.',
        's.a.', 'limited',
    ];

    public function __construct(private readonly YahooFinanceClient $yahoo) {}

    /**
     * Batch headlines for many tickers, fetched with a bounded
     * concurrency pool (≈8× faster than sequential).
     *
     * @param  list<string>  $tickers
     * @param  array<string, string>  $names  ticker => company name, used to search
     *                                        by name instead of by bare symbol
     * @return array<string, list<array{title: string, publisher: string, url: string, published_at: string, age_hours: float}>>
     */
    public function headlinesMany(array $tickers, int $lookbackDays, array $names = []): array
    {
        $tickers = array_values($tickers);
        $cutoff = time() - $lookbackDays * 86400;
        $maxHeadlines = (int) config('listikhar.news.max_headlines', 6);

        $phrasesByTicker = [];
        $searchTerms = [];
        foreach ($tickers as $ticker) {
            $phrases = $this->companyPhrases($names[$ticker] ?? null);
            $phrasesByTicker[$ticker] = $phrases;
            $searchTerms[$ticker] = $phrases !== [] ? $names[$ticker] : $ticker;
        }

        $raw = $this->fetchPool($searchTerms);

        $out = [];
        foreach ($tickers as $ticker) {
            $phrases = $phrasesByTicker[$ticker];

            $items = $this->parseSearch($raw[$ticker] ?? null, $ticker, $phrases, $cutoff);

            // The name search can come up empty for a quiet symbol; retry by
            // ticker before giving up (the same relevance gate still applies).
            if ($items === [] && $searchTerms[$ticker] !== $ticker) {
                $items = $this->parseSearch($this->fetchPool([$ticker => $ticker])[$ticker] ?? null, $ticker, $phrases, $cutoff);
            }

            if ($items === []) {
                $items = $this->fromRss($ticker, $phrases, $cutoff);
            }

            usort($items, fn ($a, $b) => $b['age_hours'] <=> $a['age_hours']);
            $out[$ticker] = array_slice($items, 0, $maxHeadlines);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $terms  ticker => search term
     * @return array<string, array<string, mixed>|null>
     */
    private function fetchPool(array $terms): array
    {
        $keys = array_keys($terms);

        $requests = function () use ($terms) {
            foreach ($terms as $ticker => $term) {
                // The extra query params unlock Yahoo's full news vertical;
                // with only newsCount set the same call returns far fewer (often
                // zero) stories, even for names with heavy coverage today.
                yield new Request('GET',
                    'https://query1.finance.yahoo.com/v1/finance/search?'.http_build_query([
                        'q' => $term,
                        'newsCount' => 25,
                        'quotesCount' => 0,
                        'listsCount' => 0,
                        'enableFuzzyQuery' => 'false',
                        'quotesQueryId' => 'tss_match_phrase_query',
                        'multiQuoteQueryId' => 'multi_quote_single_token_query',
                        'newsQueryId' => 'news_cie_vespa',
                        'enableCb' => 'true',
                        'enableNavLinks' => 'false',
                        'enableResearchReports' => 'true',
                        'enableCulturalAssets' => 'false',
                        'enableLogoUrl' => 'false',
                    ]));
            }
        };

        $raw = [];
        $pool = new Pool($this->yahoo->httpClient(), $requests(), [
            'concurrency' => 8,
            'fulfilled' => function ($response, $index) use (&$raw, $keys) {
                $raw[$keys[$index]] = json_decode((string) $response->getBody(), true);
            },
            'rejected' => function ($reason, $index) use (&$raw, $keys) {
                $raw[$keys[$index]] = null;
            },
        ]);
        $pool->promise()->wait();

        return $raw;
    }

    /**
     * @param  list<string>  $phrases  distinctive company-name phrases
     * @return list<array{title: string, publisher: string, url: string, published_at: string, age_hours: float}>
     */
    private function parseSearch(?array $payload, string $ticker, array $phrases, int $cutoff): array
    {
        $seen = [];
        $out = [];
        foreach ($payload['news'] ?? [] as $news) {
            $ts = (int) ($news['providerPublishTime'] ?? 0);
            if ($ts < $cutoff) {
                continue;
            }

            if (! $this->isRelevant($news, $ticker, $phrases)) {
                continue;
            }

            $title = trim((string) ($news['title'] ?? ''));
            $url = (string) ($news['link'] ?? $news['url'] ?? '');
            if ($title === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $out[] = [
                'title' => $title,
                'publisher' => (string) ($news['publisher'] ?? ''),
                'url' => $url,
                'published_at' => gmdate('c', $ts),
                'age_hours' => round((time() - $ts) / 3600, 1),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $news
     * @param  list<string>  $phrases
     */
    private function isRelevant(array $news, string $ticker, array $phrases): bool
    {
        $title = (string) ($news['title'] ?? '');

        // Yahoo's relatedTickers cannot carry the decision on their own: it
        // fuzzy-matches on company names, so Carrefour stories come back tagged
        // CAN (Canaan) and "Cosmos Health" comes back tagged COSM (Cosmo
        // Energy). So the headline itself must name the company…
        $named = preg_match('/(?<![\w.])'.preg_quote(strtoupper($ticker), '/').'(?![\w.])/', $title) === 1;

        if (! $named) {
            foreach ($phrases as $phrase) {
                // Word-bounded so one company cannot match a longer name that
                // merely starts the same way (Cosmo vs "Cosmos Health").
                if (preg_match('/(?<![\w])'.preg_quote($phrase, '/').'(?![\w])/i', $title)) {
                    $named = true;

                    break;
                }
            }
        }

        if (! $named) {
            return false;
        }

        // …and when Yahoo did name related symbols, a missing tag means the
        // story is about someone else, so drop it.
        $related = $news['relatedTickers'] ?? null;
        if (is_array($related) && $related !== []) {
            return in_array(strtoupper($ticker), array_map('strtoupper', $related), true);
        }

        return true;
    }

    /**
     * Distinctive phrases to look for in a headline, derived from the company
     * name with legal/corporate suffixes dropped. Single tokens must be at
     * least 4 characters so short generic names ("Lab") cannot match by chance.
     *
     * @return list<string>
     */
    private function companyPhrases(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '' || mb_strtolower($name) === 'unclassified') {
            return [];
        }

        // Drop a trailing exchange hint, e.g. "Canaan Inc (CAN)".
        $name = (string) preg_replace('/\s*\([^)]*\)\s*$/', '', $name);

        $tokens = preg_split('/[\s,\/&]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keep = [];
        foreach ($tokens as $token) {
            if (in_array(mb_strtolower($token), self::COMPANY_SUFFIXES, true)) {
                continue;
            }
            $keep[] = $token;
        }

        if ($keep === []) {
            return [];
        }

        $phrases = [];
        if (mb_strlen($keep[0]) >= 4) {
            $phrases[] = mb_strtolower($keep[0]);
        }
        if (count($keep) >= 2) {
            $pair = mb_strtolower($keep[0].' '.$keep[1]);
            if (mb_strlen($pair) >= 6) {
                $phrases[] = $pair;
            }
        }

        return array_values(array_unique($phrases));
    }

    /**
     * @param  list<string>  $phrases
     * @return list<array{title: string, publisher: string, url: string, published_at: string, age_hours: float}>
     */
    private function fromRss(string $ticker, array $phrases, int $cutoff): array
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

            $title = trim((string) $item->title);
            if (! $this->isRelevant(['title' => $title], $ticker, $phrases)) {
                continue;
            }

            $out[] = [
                'title' => $title,
                'publisher' => trim((string) ($item->source ?? '')),
                'url' => (string) $item->link,
                'published_at' => gmdate('c', $ts),
                'age_hours' => round((time() - $ts) / 3600, 1),
            ];
        }

        return $out;
    }
}
