<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Services\Data\InsiderTradeService;
use App\Services\Data\YahooFinanceClient;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class InsiderTradeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_stores_daily_buy_and_sell_aggregates(): void
    {
        Stock::query()->create([
            'ticker' => 'AAA',
            'name' => 'AAA Corp',
            'exchange' => 'NASDAQ',
            'is_penny' => true,
        ]);

        $payload = [
            'data' => [
                'transactionTable' => [
                    'totalRecords' => '4',
                    'table' => [
                        'rows' => [
                            [
                                'insider' => 'Buyer',
                                'relation' => 'Officer',
                                'lastDate' => '10/02/2026',
                                'transactionType' => 'Buy',
                                'ownType' => 'Direct',
                                'sharesTraded' => '100',
                                'lastPrice' => '$3.00',
                            ],
                            [
                                'insider' => 'Seller',
                                'relation' => 'Director',
                                'lastDate' => '10/02/2026',
                                'transactionType' => 'Automatic Sell',
                                'ownType' => 'Direct',
                                'sharesTraded' => '40',
                                'lastPrice' => '$2.00',
                            ],
                            [
                                'insider' => 'Option Holder',
                                'relation' => 'Officer',
                                'lastDate' => '10/01/2026',
                                'transactionType' => 'Option Execute',
                                'ownType' => 'Direct',
                                'sharesTraded' => '50',
                                'lastPrice' => '',
                            ],
                            [
                                'insider' => 'Old Buyer',
                                'relation' => 'Officer',
                                'lastDate' => '12/31/2023',
                                'transactionType' => 'Buy',
                                'ownType' => 'Direct',
                                'sharesTraded' => '10',
                                'lastPrice' => '$1.00',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $http = $this->createMock(YahooFinanceClient::class);
        $http->method('rawGet')->willReturn(new Response(200, [], json_encode($payload)));
        $service = new InsiderTradeService($http, 10, 0);

        $stats = $service->sync(['AAA'], '2024-01-01');

        $this->assertSame(1, $stats['synced']);
        $this->assertSame([], $stats['failed']);
        $this->assertSame(2, $stats['events']);
        $this->assertDatabaseHas('corporate_events', [
            'event_type' => 'insider_buy',
            'meta->buy_value' => 300,
        ]);
        $this->assertDatabaseHas('corporate_events', [
            'event_type' => 'insider_sell',
            'meta->sell_value' => 80,
        ]);
    }

    public function test_sync_records_failed_tickers_without_stopping(): void
    {
        $http = $this->createMock(YahooFinanceClient::class);
        $http->method('rawGet')->willThrowException(new RuntimeException('network down'));
        $service = new InsiderTradeService($http, 10, 0);

        $stats = $service->sync(['AAA'], '2024-01-01');

        $this->assertSame(['AAA'], $stats['failed']);
    }
}
