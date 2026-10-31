<?php

namespace App\Support\MarketData;

use App\Enums\PriceSource;
use App\Exceptions\MarketDataException;
use App\Support\UnitPrice;
use BcMath\Number;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RoundingMode;
use Throwable;

/**
 * Fetches stock prices from Tiingo's IEX endpoint, many tickers per request.
 *
 * The price is "tngoLast": the last trade on the IEX exchange during market
 * hours, or the official close once Tiingo has it. It is not the
 * consolidated price of all exchanges, so it can differ slightly from what a
 * broker shows. The API key is sent in a header, never in the address, so it
 * cannot appear in logged URLs or error messages.
 */
class TiingoStockQuoteProvider implements StockQuoteProvider
{
    public const string ENDPOINT = 'https://api.tiingo.com/iex';

    /** Tickers per request, to keep each request address short. */
    private const int TICKERS_PER_REQUEST = 50;

    public function __construct(
        private string $apiKey,
        private int $timeoutSeconds = 10,
    ) {}

    public function source(): PriceSource
    {
        return PriceSource::Tiingo;
    }

    public function quotes(array $tickers): array
    {
        $quotes = [];

        foreach (array_chunk($tickers, self::TICKERS_PER_REQUEST) as $chunk) {
            $quotes = [...$quotes, ...$this->fetch($chunk)];
        }

        return $quotes;
    }

    /**
     * @param  list<string>  $tickers
     * @return array<string, StockQuote>
     *
     * @throws MarketDataException
     */
    private function fetch(array $tickers): array
    {
        // Tiingo writes share classes with a dash (BRK-B) where brokers use a dot (BRK.B).
        $byTiingoSymbol = [];

        foreach ($tickers as $ticker) {
            $byTiingoSymbol[strtolower(str_replace('.', '-', $ticker))] = $ticker;
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Token '.$this->apiKey])
                ->acceptJson()
                ->connectTimeout(min(5, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
                ->get(self::ENDPOINT, ['tickers' => implode(',', array_keys($byTiingoSymbol))]);
        } catch (ConnectionException) {
            throw MarketDataException::unreachable();
        }

        $this->ensureSuccessful($response);

        $rows = $response->json();

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw MarketDataException::unreadable();
        }

        $quotes = [];

        foreach ($rows as $row) {
            $quote = is_array($row) ? $this->quoteFrom($row, $byTiingoSymbol) : null;

            if ($quote !== null) {
                $quotes[$quote->ticker] = $quote;
            }
        }

        return $quotes;
    }

    /**
     * @throws MarketDataException
     */
    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw match (true) {
            in_array($response->status(), [401, 403], true) => MarketDataException::keyRejected(),
            $response->status() === 429 => MarketDataException::rateLimited(),
            default => MarketDataException::unavailable(),
        };
    }

    /**
     * One usable quote, or null when the row has no positive price or no
     * readable quote time, or is for a ticker that was not asked for.
     *
     * @param  array<mixed>  $row
     * @param  array<string, string>  $byTiingoSymbol
     */
    private function quoteFrom(array $row, array $byTiingoSymbol): ?StockQuote
    {
        $ticker = is_string($row['ticker'] ?? null) ? ($byTiingoSymbol[strtolower($row['ticker'])] ?? null) : null;
        $price = $this->price($row['tngoLast'] ?? $row['last'] ?? null);
        $quotedAt = $this->timestamp($row['timestamp'] ?? null);

        if ($ticker === null || $price === null || $quotedAt === null) {
            return null;
        }

        return new StockQuote($ticker, $price, $quotedAt, PriceSource::Tiingo);
    }

    /**
     * The provider's number as an exact price, rounded to 4 decimal places.
     * JSON numbers arrive as floats; they are turned into exact decimal text
     * here, once, and never used for arithmetic.
     */
    private function price(mixed $value): ?UnitPrice
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $text = is_float($value) ? sprintf('%.6F', $value) : (string) $value;

        // Plain positive decimals only: no exponents, infinity or negative numbers.
        if (preg_match('/^\d{1,7}(\.\d+)?$/', $text) !== 1) {
            return null;
        }

        $price = UnitPrice::fromDecimal((new Number($text))->round(4, RoundingMode::HalfAwayFromZero)->value);

        return $price->isZero() ? null : $price;
    }

    /**
     * Tiingo times carry nanoseconds; PHP reads at most microseconds.
     */
    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) preg_replace('/(\.\d{6})\d+/', '$1', $value));
        } catch (Throwable) {
            return null;
        }
    }
}
