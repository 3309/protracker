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
                $action === ImportAction::BuyToClose => $this->saveOptionTrade->buyToClose($this->openPosition($row, $account), [
                    'event_date' => $row->value('date'),
                    'settlement_date' => $this->settlementDate($row),
                    'settlement_date_overridden' => $row->value('settlement_date') !== '',
                    'contracts' => $this->contracts($row),
                    'price_per_share' => UnitPrice::fromDecimal($row->value('price')),
                    'fees' => $this->fees($row) ?? $account->option_fee_per_contract->times($this->contracts($row)),
                    'reference_note' => $this->note($row),
                ]),
                $action === ImportAction::Expired => $this->saveOptionTrade->expire($this->openPosition($row, $account), [
                    'event_date' => $row->value('date'),
                    'contracts' => $this->contracts($row),
                    'reference_note' => $this->note($row),
                ]),
                $action === ImportAction::Assigned => $this->saveOptionTrade->assign($this->openPosition($row, $account), [
                    'event_date' => $row->value('date'),
                    'contracts' => $this->contracts($row),
                    'reference_note' => $this->note($row),
                ], $this->fees($row) ?? $account->assignment_fee),
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
        }

        $dividend->save();
        $dividend->confirm($amount);

        return $dividend;
    }

    /**
     * The one open option in the account matching the row's contract with
     * enough open contracts.
     *
     * @throws ImportRowException
     */
    private function openPosition(ImportRow $row, Account $account): OptionPosition
    {
        $matches = OptionPosition::query()
            ->where('account_id', $account->id)
            ->where('underlying', $row->value('symbol'))
            ->where('option_type', $row->value('option_type'))
            ->where('strike', UnitPrice::fromDecimal($row->value('strike'))->units)
            ->where('expires_on', $row->value('expiration'))
            ->notVoided()
            ->open()
            ->get()
            ->filter(fn (OptionPosition $position) => $position->openContracts() >= $this->contracts($row));

        if ($matches->count() !== 1) {
            throw new ImportRowException($matches->isEmpty()
                ? 'No open option in this account matches this contract with enough open contracts.'
                : 'More than one open option matches this contract, so the row cannot be matched safely. Record it by hand.');
        }

        return $matches->first();
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
