<?php

namespace App\Services\News;

/**
 * Rule-based catalyst scoring for penny-stock headlines: positive setup
 * keywords (FDA, contracts, uplisting, momentum…) vs risk keywords
 * (dilution, bankruptcy, investigations…), weighted by recency.
 */
class HeadlineScorer
{
    /**
     * Positive catalyst families. Label = short tag shown in reasoning.
     *
     * @var array<string, string>
     */
    private const POSITIVE = [
        'FDA/clinical' => '/\bFDA\b|approv(ed|al)?\b|clearance|breakthrough therapy|fast track|\bBLA\b|\bNDA\b/i',
        'clinical data' => '/phase\s+[123IVX]+(\b|,)|topline|primary endpoint|clinical (data|trial|results)/i',
        'M&A/deal' => '/\b(acquir\w*|merger|buyout|takeover|tender offer|definitive agreement|partnership|joint venture)\b/i',
        'contract/orders' => '/\b(contract|purchase order|orders?|award(ed)?|multi-year|deployment)\b/i',
        'earnings/growth' => '/(earnings|revenue)\s+(beat|surge|jump|growth|jumped)|guidance rais|record (revenue|sales|orderbook)|profitab/i',
        'uplisting' => '/\buplist\w*|nasdaq (compliance|approval|listing)|nyse (listing|compliance)|inclusion into\b/i',
        'funding' => '/\b(financing|funding|grant|substantial equity|convertible note|buyback|insider (buy|purchas))\b/i',
        'momentum' => '/\b(soar\w*|surge\w*|rall\w*|jump\w*|spike[ds]?|breakout|short squeeze)\b/i',
        'expansion' => '/\b(expansion|milestone|patent|lucrative|pipeline|blockchain|weight-loss|obesity)\b/i',
    ];

    /**
     * Risk families — disqualify the "jump soon" thesis when dominant.
     *
     * @var array<string, string>
     */
    private const NEGATIVE = [
        'dilution' => '/\b(dilut\w*|registered direct|at-the-market|share (offering|issuance)|warrant offering|penny stock offering)\b/i',
        'distress' => '/\b(bankrupt\w*|chapter 11|going concern|delist\w*|insolven\w*)\b/i',
        'legal risk' => '/\b(investigation|probe|lawsuit|class action|fraud|sec (charges|investigat\w*)|doj)\b/i',
        'trading halt' => '/\b(halt(ed)?|trading pause|reverse split|rescind\w*)\b/i',
        'weak price action' => '/\b(sink\w*|plunge\w*|tumbl\w*|slump\w*|dives?|declin\w*)\b(?=.*\b(stock|shares?|price)\b)|(stock|shares?)\b.*\b(sink\w*|plunge\w*|tumbl\w*|slump\w*|dives?|drops?|falls?)\b/i',
    ];

    /**
     * @param  list<array{title: string, age_hours: float}>  $headlines
     * @return array{
     *   score: int,
     *   signals: list<string>,
     *   risks: list<string>,
     *   best_index: int|null
     * }
     */
    public static function score(array $headlines): array
    {
        $raw = 0.0;
        $signals = [];
        $risks = [];
        $values = [];

        foreach ($headlines as $i => $headline) {
            $title = $headline['title'];
            $weight = self::recencyWeight($headline['age_hours']);

            $pos = 0;
            foreach (self::POSITIVE as $label => $pattern) {
                if (preg_match($pattern, $title)) {
                    $pos++;
                    if (! in_array($label, $signals, true)) {
                        $signals[] = $label;
                    }
                }
            }

            $neg = 0;
            foreach (self::NEGATIVE as $label => $pattern) {
                if (preg_match($pattern, $title)) {
                    $neg++;
                    if (! in_array($label, $risks, true)) {
                        $risks[] = $label;
                    }
                }
            }

            $value = ($pos * 1.3 - $neg * 2.2) * $weight;
            $values[$i] = $value;
            $raw += $value;
        }

        // Keyword-less coverage still earns a small freshness baseline so
        // "in the news today" stocks are not displayed with a 0 news score.
        $baseline = min(20, count($headlines) * 8);
        $score = (int) max(0, min(100, round($raw * 22 + $baseline)));

        $bestIndex = null;
        if ($values !== []) {
            arsort($values);
            $bestIndex = (int) array_key_first($values);
        }

        return [
            'score' => $score,
            'signals' => $signals,
            'risks' => $risks,
            'best_index' => $bestIndex,
        ];
    }

    /** Classify one headline for display: positive catalyst / risk / neutral. */
    public static function classify(string $title): string
    {
        foreach (self::NEGATIVE as $pattern) {
            if (preg_match($pattern, $title)) {
                return 'negative';
            }
        }
        foreach (self::POSITIVE as $pattern) {
            if (preg_match($pattern, $title)) {
                return 'positive';
            }
        }

        return 'neutral';
    }

    /** Headline weight by age: fresher = stronger catalyst. */
    private static function recencyWeight(float $ageHours): float
    {
        return match (true) {
            $ageHours <= 12 => 1.6,
            $ageHours <= 24 => 1.4,
            $ageHours <= 48 => 1.2,
            default => 1.0,
        };
    }
}
