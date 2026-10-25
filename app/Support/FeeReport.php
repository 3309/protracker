<?php

namespace App\Support;

use App\Enums\OptionEventType;
use App\Enums\PositionOrigin;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\StockTrade;
use Illuminate\Support\Collection;

/**
 * Fees paid in a period, on the date each was charged: stock trade fees
 * (including stock trades caused by assignments), option opening fees of
 * options sold or bought to open and option closing fees (buy-backs and
 * sales to close). Fees of opening-balance
 * options were paid before the reporting start. Voided records are left out.
 */
final readonly class FeeReport
{
    public const string STOCK = 'Stock trades';

    public const string OPTION_OPENING = 'Option opening';

    public const string OPTION_CLOSING = 'Option closing';

    /**
     * @param  Collection<int, array{date: string, accountName: string, ticker: string, type: string, amount: Money}>  $items
     */
    private function __construct(public Collection $items) {}

    /**
     * @param  list<int>|null  $accountIds  null for all accounts
     */
    public static function for(ReportingPeriod $period, ?array $accountIds, ?string $ticker): self
    {
        $items = collect();

        $trades = StockTrade::query()->with('account')->notVoided()
            ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds))
            ->when($ticker !== null, fn ($query) => $query->where('ticker', $ticker));
        $period->applyTo($trades, 'trade_date');

        foreach ($trades->get() as $trade) {
            $items->push(['date' => $trade->trade_date->format('Y-m-d'), 'accountName' => $trade->account->displayName(), 'ticker' => $trade->ticker, 'type' => self::STOCK, 'amount' => $trade->fees]);
        }

        $openings = OptionPosition::query()->with('account')->notVoided()->where('origin', '!=', PositionOrigin::OpeningBalance)
            ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds))
            ->when($ticker !== null, fn ($query) => $query->where('underlying', $ticker));
        $period->applyTo($openings, 'opened_on');

        foreach ($openings->get() as $position) {
            $items->push(['date' => $position->opened_on->format('Y-m-d'), 'accountName' => $position->account->displayName(), 'ticker' => $position->underlying, 'type' => self::OPTION_OPENING, 'amount' => $position->opening_fees]);
        }

        $closings = OptionEvent::query()->with(['account', 'position'])->notVoided()
            ->whereIn('event_type', [OptionEventType::BuyToClose, OptionEventType::Rolled, OptionEventType::SellToClose])
            ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds))
            ->when($ticker !== null, fn ($query) => $query->whereHas('position', fn ($position) => $position->where('underlying', $ticker)));
        $period->applyTo($closings, 'event_date');

        foreach ($closings->get() as $event) {
            $items->push(['date' => $event->event_date->format('Y-m-d'), 'accountName' => $event->account->displayName(), 'ticker' => $event->position->underlying, 'type' => self::OPTION_CLOSING, 'amount' => $event->fees]);
        }

        return new self($items->filter(fn (array $item) => ! $item['amount']->isZero())->values());
    }

    public function total(): Money
    {
        return $this->sum($this->items);
    }

    /**
     * @return Collection<string, Money> totals keyed by "YYYY-MM"
     */
    public function byMonth(): Collection
    {
        return $this->groupedBy(fn (array $item) => substr($item['date'], 0, 7));
    }

    /**
     * @return Collection<string, Money>
     */
    public function byTicker(): Collection
    {
        return $this->groupedBy(fn (array $item) => $item['ticker']);
    }

    /**
     * @return Collection<string, Money>
     */
    public function byAccount(): Collection
    {
        return $this->groupedBy(fn (array $item) => $item['accountName']);
    }

    /**
     * @return Collection<string, Money>
     */
    public function byType(): Collection
    {
        return $this->groupedBy(fn (array $item) => $item['type']);
    }

    /**
     * @param  callable(array{date: string, accountName: string, ticker: string, type: string, amount: Money}): string  $key
     * @return Collection<string, Money>
     */
    private function groupedBy(callable $key): Collection
    {
        return $this->items->groupBy($key)->map(fn (Collection $group) => $this->sum($group))->sortKeys();
    }

    /**
     * @param  Collection<int, array{date: string, accountName: string, ticker: string, type: string, amount: Money}>  $items
     */
    private function sum(Collection $items): Money
    {
        return $items->reduce(fn (Money $total, array $item) => $total->plus($item['amount']), Money::zero());
    }
}
