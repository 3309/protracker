<?php

namespace App\Support\MarketData;

/**
 * What one "Refresh Stock Prices" did, ticker by ticker: saved a new price,
 * found nothing newer than the price already saved, or could not get a
 * usable price (the last known price, if any, is kept).
 */
final readonly class StockPriceRefresh
{
    /**
     * @param  list<string>  $updated  tickers with a new price saved
     * @param  list<string>  $unchanged  tickers whose saved price is already as new as the quote
     * @param  array<string, string>  $failed  reason by ticker
     */
    public function __construct(
        public array $updated,
        public array $unchanged,
        public array $failed,
    ) {}

    /**
     * success, warning (some tickers failed) or critical (all failed), as
     * used by the alert component.
     */
    public function level(): string
    {
        return match (true) {
            $this->failed === [] => 'success',
            $this->updated === [] && $this->unchanged === [] => 'critical',
            default => 'warning',
        };
    }

    public function message(): string
    {
        if ($this->updated === [] && $this->unchanged === [] && $this->failed === []) {
            return 'You do not hold any stocks, so there were no prices to refresh.';
        }

        $parts = [count($this->updated).' '.str('stock price')->plural(count($this->updated)).' updated'];

        if ($this->unchanged !== []) {
            $parts[] = count($this->unchanged).' already up to date';
        }

        $parts[] = count($this->failed).' failed';

        $message = implode(', ', $parts).'.';

        if ($this->failed !== []) {
            $reasons = collect($this->failed)->map(fn (string $reason, string $ticker) => "{$ticker}: {$reason}")->implode('; ');
            $message .= " {$reasons}. The last known price is kept for these.";
        }

        return $message;
    }
}
