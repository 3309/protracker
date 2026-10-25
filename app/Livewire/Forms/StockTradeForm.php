<?php

namespace App\Livewire\Forms;

use App\Actions\Stocks\SaveStockTrade;
use App\Enums\TradeSide;
use App\Exceptions\InsufficientSharesException;
use App\Livewire\Forms\Concerns\SharedFormInput;
use App\Models\Account;
use App\Models\StockTrade;
use App\Support\InputFormat;
use App\Support\MarketCalendar;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Enters or corrects a stock buy or sell.
 *
 * A blank fee uses the account's default stock fee per trade. A blank
 * settlement date is calculated as the next trading day.
 */
class StockTradeForm extends Form
{
    use SharedFormInput;

    public ?StockTrade $stockTrade = null;

    public string $account_id = '';

    public string $side = '';

    public string $ticker = '';

    public string $trade_date = '';

    public string $settlement_date = '';

    public string $shares = '';

    public string $price_per_share = '';

    public string $fees = '';

    public string $reference_note = '';

    public string $reason = '';

    public function setStockTrade(StockTrade $stockTrade): void
    {
        $this->stockTrade = $stockTrade;
        $this->account_id = (string) $stockTrade->account_id;
        $this->side = $stockTrade->side->value;
        $this->ticker = $stockTrade->ticker;
        $this->trade_date = $stockTrade->trade_date->format('Y-m-d');
        $this->settlement_date = $stockTrade->settlement_date_overridden ? $stockTrade->settlement_date->format('Y-m-d') : '';
        $this->shares = $stockTrade->shares->toDecimal();
        $this->price_per_share = $stockTrade->price_per_share->toDecimal();
        $this->fees = $stockTrade->fees->toDecimal();
        $this->reference_note = $stockTrade->reference_note ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $creating = $this->stockTrade === null;

        return [
            'account_id' => $creating ? ['required', Rule::exists('accounts', 'id')->whereNull('archived_at')] : [],
            'side' => $creating ? ['required', Rule::enum(TradeSide::class)] : [],
            'ticker' => ['required', 'regex:'.InputFormat::TICKER],
            'trade_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->reportingStartDate()],
            'settlement_date' => [
                'nullable',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->trade_date !== '' && (string) $value < $this->trade_date) {
                        $fail('The settlement date cannot be before the trade date.');
                    }
                },
            ],
            'shares' => ['required', 'bail', 'regex:'.InputFormat::SHARES, $this->greaterThanZero(Quantity::class, 'Enter more than zero shares.')],
            'price_per_share' => ['required', 'bail', 'regex:'.InputFormat::PRICE, $this->greaterThanZero(UnitPrice::class, 'The price must be more than zero.')],
            'fees' => ['nullable', 'regex:'.InputFormat::FEE],
            'reference_note' => ['nullable', 'string', 'max:1000'],
            'reason' => [$creating ? 'nullable' : 'required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'account_id.required' => 'Choose the account.',
            'account_id.exists' => 'Choose an active account.',
            'side.required' => 'Choose buy or sell.',
            'ticker.regex' => 'Enter a ticker such as AAPL or BRK.B.',
            'trade_date.after_or_equal' => 'Trades before the reporting start date are entered as opening balances instead.',
            'shares.regex' => 'Enter a number of shares such as 100 or 0.5, with no more than six decimal places.',
            'price_per_share.regex' => 'Enter a price per share such as 52 or 49.1234, with no more than four decimal places.',
            'fees.regex' => 'Enter a dollar amount such as 0.65, with no more than two decimal places.',
            'reason.required' => 'Please explain why you are making this change. It is saved in the audit history.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'account_id' => 'account',
            'trade_date' => 'trade date',
            'settlement_date' => 'settlement date',
            'price_per_share' => 'price per share',
            'reference_note' => 'reference note',
        ];
    }

    /**
     * Record the trade. Returns null, with an error on the shares field, when
     * a sale needs more shares than the account held.
     */
    public function store(): ?StockTrade
    {
        $this->validate();

        $account = Account::findOrFail((int) $this->account_id);

        try {
            return app(SaveStockTrade::class)->create([
                'account_id' => $account->id,
                'side' => TradeSide::from($this->side),
                ...$this->tradeAttributes($account),
            ]);
        } catch (InsufficientSharesException $exception) {
            $this->addError('shares', $exception->getMessage());

            return null;
        }
    }

    /**
     * Save a correction. Returns false, with an error on the shares field,
     * when it would leave a sale without enough shares.
     */
    public function update(): bool
    {
        $this->validate();

        $trade = $this->stockTrade;

        if ($trade === null) {
            return false;
        }

        try {
            app(SaveStockTrade::class)->update($trade, $this->tradeAttributes($trade->account), $this->reason);
        } catch (InsufficientSharesException $exception) {
            $trade->refresh();
            $this->addError('shares', $exception->getMessage());

            return false;
        }

        $this->settlement_date = $trade->settlement_date_overridden ? $trade->settlement_date->format('Y-m-d') : '';
        $this->fees = $trade->fees->toDecimal();
        $this->reset('reason');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function tradeAttributes(Account $account): array
    {
        $tradeDate = CarbonImmutable::parse($this->trade_date);
        $settlementDate = $this->nullIfBlank($this->settlement_date);

        return [
            'ticker' => $this->normalizedTicker($this->ticker),
            'trade_date' => $this->trade_date,
            'settlement_date' => $settlementDate ?? MarketCalendar::stockSettlementDate($tradeDate)->format('Y-m-d'),
            'settlement_date_overridden' => $settlementDate !== null,
            'shares' => Quantity::fromDecimal($this->shares),
            'price_per_share' => UnitPrice::fromDecimal($this->price_per_share),
            'fees' => $this->nullIfBlank($this->fees) !== null ? Money::fromDecimal($this->fees) : $account->stock_fee_per_trade,
            'reference_note' => $this->nullIfBlank($this->reference_note),
        ];
    }
}
