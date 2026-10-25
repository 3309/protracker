<?php

namespace App\Actions\Imports;

use App\Actions\Options\SaveOptionTrade;
use App\Actions\Stocks\SaveStockTrade;
use App\Enums\ImportAction;
use App\Enums\ImportRowStatus;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Exceptions\ImportRowException;
use App\Exceptions\InsufficientSharesException;
use App\Exceptions\OptionPositionException;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\ImportRow;
use App\Models\OptionPosition;
use App\Models\StockTrade;
use App\Support\DividendRate;
use App\Support\MarketCalendar;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use BcMath\Number;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RoundingMode;

/**
 * Turns one checked import row into a record through the same engines as
 * manual entry (stock FIFO, options, assignments, dividends, cash), then
 * links the row to the record it created.
 *
 * Blank fees use the account defaults; a blank settlement date is the next
 * trading day (the same day for cash movements). On failure nothing is
 * saved and the exception explains why.
 */
class CommitImportRow
{
    public function __construct(
        private SaveStockTrade $saveStockTrade,
        private SaveOptionTrade $saveOptionTrade,
    ) {}

    /**
     * @throws ImportRowException
     * @throws InsufficientSharesException
     * @throws OptionPositionException
     */
    public function handle(ImportRow $row): Model
    {
        $action = $row->action ?? throw new ImportRowException('The row has no recognized action.');
        $account = $row->batch->account;

        return DB::transaction(function () use ($row, $action, $account): Model {
            $record = match (true) {
                $action->isStockTrade() => $this->stockTrade($row, $action, $account),
                $action === ImportAction::SellToOpen => $this->sellToOpen($row, $account),
                $action->closesOption() => $this->closeOption($row, $action, $account),
                $action === ImportAction::Dividend => $this->dividend($row, $account),
                default => CashMovement::create([
                    'account_id' => $account->id,
                    'movement_type' => $action->cashMovementType(),
                    'movement_date' => $row->value('date'),
                    'settlement_date' => $row->value('settlement_date') !== '' ? $row->value('settlement_date') : $row->value('date'),
                    'amount' => Money::fromCents(abs(Money::fromDecimal($row->value('amount'))->cents)),
                    'reference_note' => $this->note($row),
                ]),
            };

            $row->imported()->associate($record);
            $row->status = ImportRowStatus::Imported;
            $row->messages = [];
            $row->save();

            return $record;
        });
    }

    private function stockTrade(ImportRow $row, ImportAction $action, Account $account): Model
    {
        return $this->saveStockTrade->create([
            'account_id' => $account->id,
            'side' => $action === ImportAction::Buy ? TradeSide::Buy : TradeSide::Sell,
            'ticker' => $row->value('symbol'),
            'trade_date' => $row->value('date'),
            'settlement_date' => $row->value('settlement_date') !== ''
                ? $row->value('settlement_date')
                : MarketCalendar::stockSettlementDate(CarbonImmutable::parse($row->value('date')))->format('Y-m-d'),
            'settlement_date_overridden' => $row->value('settlement_date') !== '',
            'shares' => Quantity::fromDecimal(ltrim($row->value('quantity'), '-')),
            'price_per_share' => UnitPrice::fromDecimal($row->value('price')),
            'fees' => $this->fees($row) ?? $account->stock_fee_per_trade,
            'reference_note' => $this->note($row),
        ]);
    }

    private function sellToOpen(ImportRow $row, Account $account): Model
    {
        $contracts = $this->contracts($row);

        return $this->saveOptionTrade->open([
            'account_id' => $account->id,
            'underlying' => $row->value('symbol'),
            'option_type' => OptionType::from($row->value('option_type')),
            'strike' => UnitPrice::fromDecimal($row->value('strike')),
            'expires_on' => $row->value('expiration'),
            'opened_on' => $row->value('date'),
            'opening_settlement_date' => $this->settlementDate($row),
            'opening_settlement_date_overridden' => $row->value('settlement_date') !== '',
            'contracts' => $contracts,
            'multiplier' => (int) $row->value('multiplier') ?: 100,
            'premium_per_share' => UnitPrice::fromDecimal($row->value('price')),
            'opening_fees' => $this->fees($row) ?? $account->option_fee_per_contract->times($contracts),
            'reference_note' => $this->note($row),
        ]);
    }

    /**
     * Create the dividend and confirm it with the amount from the file. When
     * the file has no amount per share, it is worked out from the amount and
     * the shares held on the ex-date, to six decimal places.
     *
     * @throws ImportRowException
     */
    private function dividend(ImportRow $row, Account $account): Model
    {
        $exists = Dividend::query()->notVoided()
            ->where('account_id', $account->id)
            ->where('ticker', $row->value('symbol'))
            ->where('ex_date', $row->value('ex_date'))
            ->exists();

        if ($exists) {
            throw new ImportRowException("A {$row->value('symbol')} dividend with ex-date {$row->value('ex_date')} is already recorded for this account.");
        }

        $amount = Money::fromCents(abs(Money::fromDecimal($row->value('amount'))->cents));

        $dividend = new Dividend([
            'account_id' => $account->id,
            'ticker' => $row->value('symbol'),
            'ex_date' => $row->value('ex_date'),
            'pay_date' => $row->value('date'),
            'amount_per_share' => DividendRate::fromUnits(0),
            'reference_note' => $this->note($row),
        ]);

        if ($row->value('price') !== '') {
            $dividend->amount_per_share = DividendRate::fromDecimal($row->value('price'));
        } else {
            $eligible = $dividend->currentEligibleShares();

            if ($eligible->isZero()) {
                throw new ImportRowException('No shares were held on the ex-date, so the amount per share cannot be worked out. Enter it in the price field.');
            }

            $rate = (new Number($amount->cents))->div(100, 10)->div($eligible->toNumber(), 10)->round(6, RoundingMode::HalfAwayFromZero);
            $dividend->amount_per_share = DividendRate::fromDecimal($rate->value);
            $this->ensurePlausibleDividend($dividend, $account);
        }

        $dividend->save();
        $dividend->confirm($amount);

        return $dividend;
    }

    /**
     * A worked-out dividend of more than 10% of the last price paid for the
     * shares in one payment is far outside normal dividends: it is more
     * likely a stock split or another corporate action listed as a dividend,
     * so it is not imported. Entering the amount per share skips this check.
     *
     * @throws ImportRowException
     */
    private function ensurePlausibleDividend(Dividend $dividend, Account $account): void
    {
        $lastPurchase = StockTrade::query()
            ->notVoided()
            ->where('account_id', $account->id)
            ->where('ticker', $dividend->ticker)
            ->where('side', TradeSide::Buy)
            ->where('trade_date', '<=', $dividend->ex_date->format('Y-m-d'))
            ->orderByDesc('trade_date')
            ->orderByDesc('id')
            ->first();

        if ($lastPurchase === null || $dividend->amount_per_share->toNumber()->compare($lastPurchase->price_per_share->toNumber()->mul('0.1')) <= 0) {
            return;
        }

        throw new ImportRowException("This works out to {$dividend->amount_per_share->format()} per share, more than 10% of the last price paid for {$dividend->ticker} ({$lastPurchase->price_per_share->format()}). It may be a stock split or another corporate action listed as a dividend, which Protracker does not handle. Check it: skip it, or if it really is a dividend, enter the amount per share in the price field.");
    }

    /**
     * Buy to close, expire or assign the row's contracts. When several open
     * options in the account have the same contract, the oldest is closed
     * first, as brokers do, and a row closing more contracts than it holds
     * continues into the next; the fees are shared by contracts. The row is
     * linked to the first event.
     *
     * @throws ImportRowException
     * @throws InsufficientSharesException
     * @throws OptionPositionException
     */
    private function closeOption(ImportRow $row, ImportAction $action, Account $account): Model
    {
        $contracts = $this->contracts($row);
        $parts = $this->positionsToClose($row, $account, $contracts);
        $fees = $this->fees($row) ?? match ($action) {
            ImportAction::BuyToClose => $account->option_fee_per_contract->times($contracts),
            default => $account->assignment_fee,
        };
        $feesLeft = $fees;
        $events = [];

        foreach ($parts as $index => $part) {
            $partFees = $index === array_key_last($parts) ? $feesLeft : $fees->share($part['contracts'], $contracts);
            $feesLeft = $feesLeft->minus($partFees);
            $attributes = [
                'event_date' => $row->value('date'),
                'contracts' => $part['contracts'],
                'reference_note' => $this->note($row).(count($parts) > 1 ? ' (part '.($index + 1).' of '.count($parts).')' : ''),
            ];

            $events[] = match ($action) {
                ImportAction::BuyToClose => $this->saveOptionTrade->buyToClose($part['position'], [
                    ...$attributes,
                    'settlement_date' => $this->settlementDate($row),
                    'settlement_date_overridden' => $row->value('settlement_date') !== '',
                    'price_per_share' => UnitPrice::fromDecimal($row->value('price')),
                    'fees' => $partFees,
                ]),
                ImportAction::Expired => $this->saveOptionTrade->expire($part['position'], $attributes),
                default => $this->saveOptionTrade->assign($part['position'], $attributes, $partFees),
            };
        }

        return $events[0];
    }

    /**
     * The open options in the account matching the row's contract, oldest
     * first, and how many of the row's contracts each one closes.
     *
     * @return non-empty-list<array{position: OptionPosition, contracts: int}>
     *
     * @throws ImportRowException
     */
    private function positionsToClose(ImportRow $row, Account $account, int $contracts): array
    {
        $positions = OptionPosition::query()
            ->short()
            ->where('account_id', $account->id)
            ->where('underlying', $row->value('symbol'))
            ->where('option_type', $row->value('option_type'))
            ->where('strike', UnitPrice::fromDecimal($row->value('strike'))->units)
            ->where('expires_on', $row->value('expiration'))
            ->notVoided()
            ->open()
            ->orderBy('opened_on')
            ->orderBy('id')
            ->get();

        $parts = [];
        $remaining = $contracts;

        foreach ($positions as $position) {
            $closing = min($remaining, $position->openContracts());

            if ($closing > 0) {
                $parts[] = ['position' => $position, 'contracts' => $closing];
                $remaining -= $closing;
            }

            if ($remaining === 0) {
                break;
            }
        }

        if ($parts === [] || $remaining > 0) {
            throw new ImportRowException('The open options in this account matching this contract do not have enough open contracts.');
        }

        return $parts;
    }

    private function contracts(ImportRow $row): int
    {
        return (int) ltrim($row->value('quantity'), '-');
    }

    private function fees(ImportRow $row): ?Money
    {
        return $row->value('fees') !== '' ? Money::fromCents(abs(Money::fromDecimal($row->value('fees'))->cents)) : null;
    }

    private function settlementDate(ImportRow $row): string
    {
        return $row->value('settlement_date') !== ''
            ? $row->value('settlement_date')
            : MarketCalendar::optionSettlementDate(CarbonImmutable::parse($row->value('date')))->format('Y-m-d');
    }

    private function note(ImportRow $row): string
    {
        return trim("Imported from {$row->batch->original_filename}, row {$row->row_number}. ".$row->value('description'));
    }
}
