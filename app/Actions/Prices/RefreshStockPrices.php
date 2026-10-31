<?php

namespace App\Actions\Prices;

use App\Enums\PriceInstrument;
use App\Exceptions\MarketDataException;
use App\Models\Price;
use App\Support\InputFormat;
use App\Support\MarketData\StockPriceRefresh;
use App\Support\MarketData\StockQuoteProvider;
use App\Support\OpenPositionsReport;
use App\Support\PriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fetches the latest price of every stock currently held, in any account,
 * and saves each new one to the price history, exactly like a price typed in
 * on the Prices page.
 *
 * Only prices are written. Trades, lots, sale matching, cash, options and
 * realized profit are never touched, and no buy or sell is created. A ticker
 * without a usable quote keeps its last known price; nothing is ever saved
 * as zero.
 *
 * Each ticker is asked for once, even when several accounts hold it. A
 * refresh cannot run twice at the same time, and after a successful one the
 * next must wait COOLDOWN_SECONDS, to stay within the provider's limits.
 */
class RefreshStockPrices
{
    public const int COOLDOWN_SECONDS = 120;

    private const string LOCK_KEY = 'stock-price-refresh';

    private const string COOLDOWN_KEY = 'stock-price-refresh:recent';

    public function __construct(private StockQuoteProvider $provider) {}

    /**
     * @param  CarbonImmutable  $today  today's calendar date, for the shares held
     *
     * @throws MarketDataException when no quotes could be fetched at all
     */
    public function handle(CarbonImmutable $today): StockPriceRefresh
    {
        if (Cache::has(self::COOLDOWN_KEY)) {
            throw MarketDataException::tooSoon(self::COOLDOWN_SECONDS);
        }

        $lock = Cache::lock(self::LOCK_KEY, 60);

        if (! $lock->get()) {
            throw MarketDataException::alreadyRunning();
        }

        try {
            return $this->refresh($today);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws MarketDataException
     */
    private function refresh(CarbonImmutable $today): StockPriceRefresh
    {
        $prices = PriceBook::load($today);

        $heldTickers = OpenPositionsReport::build(null, null, $prices, $today)->stocks
            ->pluck('ticker')
            ->unique()
            ->sort()
            ->values();

        $failed = [];
        $validTickers = [];

        foreach ($heldTickers as $ticker) {
            if (preg_match(InputFormat::TICKER, $ticker) === 1) {
                $validTickers[] = $ticker;
            } else {
                $failed[$ticker] = 'not a valid ticker, so it was not looked up';
            }
        }

        if ($validTickers === []) {
            return new StockPriceRefresh([], [], $failed);
        }

        $quotes = $this->provider->quotes($validTickers);

        Cache::put(self::COOLDOWN_KEY, true, self::COOLDOWN_SECONDS);

        $updated = [];
        $unchanged = [];
        $retrievedAt = now();

        DB::transaction(function () use ($validTickers, $quotes, $prices, $retrievedAt, &$updated, &$unchanged, &$failed): void {
            foreach ($validTickers as $ticker) {
                $quote = $quotes[$ticker] ?? null;

                if ($quote === null) {
                    $failed[$ticker] = 'no price was returned';

                    continue;
                }

                $latest = $prices->forStock($ticker);

                if ($latest !== null && $latest->as_of->greaterThanOrEqualTo($quote->quotedAt)) {
                    $unchanged[] = $ticker;

                    continue;
                }

                Price::create([
                    'instrument' => PriceInstrument::Stock,
                    'ticker' => $ticker,
                    'price' => $quote->price,
                    'source' => $quote->source,
                    'as_of' => $quote->quotedAt->setTimezone(config('app.timezone')),
                    'retrieved_at' => $retrievedAt,
                ]);

                $updated[] = $ticker;
            }
        });

        ksort($failed);

        return new StockPriceRefresh($updated, $unchanged, $failed);
    }
}
