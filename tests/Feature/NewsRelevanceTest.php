<?php

namespace Tests\Feature;

use App\Services\Data\YahooFinanceClient;
use App\Services\News\NewsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * Yahoo's search endpoint is a full-text query, so a bare "CAN" matches the
 * English verb. These lock in the relevance gate that keeps those stories out.
 */
class NewsRelevanceTest extends TestCase
{
    private function client(array $responses): NewsClient
    {
        $mock = new MockHandler(array_map(
            fn (array $news): Response => new Response(200, [], json_encode(['news' => $news])),
            $responses
        ));

        $yahoo = $this->createMock(YahooFinanceClient::class);
        $yahoo->method('httpClient')->willReturn(new Client(['handler' => HandlerStack::create($mock)]));
        $yahoo->method('rawGet')->willThrowException(new \RuntimeException('no rss'));

        return new NewsClient($yahoo);
    }

    private function story(string $title, array $related = [], int $ageHours = 2): array
    {
        return [
            'title' => $title,
            'link' => 'https://example.com/'.md5($title),
            'publisher' => 'Wire',
            'providerPublishTime' => time() - $ageHours * 3600,
            'relatedTickers' => $related,
        ];
    }

    public function test_the_verb_can_is_not_mistaken_for_the_ticker_can(): void
    {
        $client = $this->client([[
            $this->story('SJ Fall Summit: Retailers Can Get More Value Back', ['ON']),
            $this->story('A Retired Couple With $60,000 of Pension Income Can Still Convert', []),
            $this->story('Can Costco Absorb Rising Costs On A 3.9% Margin?', ['COST', 'KR']),
            $this->story('Five Below (FIVE) Down 7.8% Since Last Earnings Report: Can It Rebound?', ['FIVE']),
        ]]);

        $headlines = $client->headlinesMany(['CAN'], 3, ['CAN' => 'Canaan Inc.']);

        $this->assertSame([], $headlines['CAN']);
    }

    public function test_a_real_story_named_only_in_the_title_is_kept(): void
    {
        // No relatedTickers at all — the headline names the company, so it stays.
        $client = $this->client([[
            $this->story('Canaan: Q2 Earnings Snapshot'),
            $this->story('B. Riley Cuts Price Target on Canaan to $1.50, Keeps Buy Rating'),
        ]]);

        $headlines = $client->headlinesMany(['CAN'], 3, ['CAN' => 'Canaan Inc.']);

        $this->assertCount(2, $headlines['CAN']);
        $this->assertStringStartsWith('Canaan:', $headlines['CAN'][0]['title']);
    }

    public function test_a_story_carrying_the_symbol_in_caps_is_kept(): void
    {
        $client = $this->client([[
            $this->story('Canaan (CAN) Sold Crypto to Buy Back Stock', ['BTC-USD', 'CAN']),
        ]]);

        $headlines = $client->headlinesMany(['CAN'], 3, ['CAN' => 'Canaan Inc.']);

        $this->assertCount(1, $headlines['CAN']);
    }

    public function test_yahoo_mis_tagging_cannot_admit_another_company(): void
    {
        // Yahoo tags Carrefour stories CAN and "Cosmos Health" as COSM; a
        // headline that never names our company must be dropped regardless.
        // Separate clients so each call gets its own response queue.
        $carrefourOnly = $this->client([
            [$this->story('Carrefour re-enters India with flagship store', ['CAN'])],
            // Retry of the same symbol; still nothing about Canaan.
            [$this->story('Carrefour re-enters India with flagship store', ['CAN'])],
        ]);
        $cosmosOnly = $this->client([
            [$this->story("Cosmos Health's Brands Reach More Than 40 Countries", ['COSM'])],
            [$this->story('Cosmo Energy Signs New Supply Agreement', ['COSM'])],
        ]);

        $carrefour = $carrefourOnly->headlinesMany(['CAN'], 3, ['CAN' => 'Canaan Inc.']);
        $cosmo = $cosmosOnly->headlinesMany(['COSM'], 3, ['COSM' => 'Cosmo Energy']);

        $this->assertSame([], $carrefour['CAN']);
        $this->assertCount(1, $cosmo['COSM']);
        $this->assertStringStartsWith('Cosmo Energy Signs', $cosmo['COSM'][0]['title']);
    }

    public function test_a_tag_for_other_symbols_vetoes_an_otherwise_matching_title(): void
    {
        $client = $this->client([[
            $this->story('CAN and Sons To Close Plant', ['GM', 'F']),
        ]]);

        $headlines = $client->headlinesMany(['CAN'], 3, ['CAN' => 'Canaan Inc.']);

        $this->assertSame([], $headlines['CAN']);
    }

    public function test_company_phrases_ignore_legal_suffixes_and_short_tokens(): void
    {
        $client = $this->client([
            // Answer to the name query (found) — the ticker query is not called.
            [$this->story('ATA Creativity Global Announces Receipt of Bid Deficiency Notice', ['AACG'])],
        ]);

        $headlines = $client->headlinesMany(['AACG'], 3, ['AACG' => 'ATA Creativity Global Inc.']);

        $this->assertCount(1, $headlines['AACG']);
    }

    public function test_stale_headlines_are_dropped(): void
    {
        $client = $this->client([[
            $this->story('Canaan Announces Something Great', ['CAN'], 24 * 30),
        ]]);

        $headlines = $client->headlinesMany(['CAN'], 3, ['CAN' => 'Canaan Inc.']);

        $this->assertSame([], $headlines['CAN']);
    }

    public function test_duplicate_urls_are_collapsed(): void
    {
        $dup = $this->story('Canaan Announces Something Great', ['CAN']);
        $client = $this->client([[$dup, array_merge($dup, ['title' => 'Canaan (CAN) Same story'])]]);

        $headlines = $client->headlinesMany(['CAN'], 3, ['CAN' => 'Canaan Inc.']);

        $this->assertCount(1, $headlines['CAN']);
    }
}
