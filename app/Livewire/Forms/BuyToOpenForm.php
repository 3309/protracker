<?php

namespace App\Livewire\Forms;

use App\Actions\Options\SaveOptionTrade;
use App\Models\Account;
use App\Models\OptionPosition;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use InvalidArgumentException;

/**
 * Enters a buy-to-open option trade (a long call or put). The fields and
 * checks are the same as for selling to open; corrections use the same
 * form as any other option.
 */
class BuyToOpenForm extends SellToOpenForm
{
    public function store(): OptionPosition
    {
        $this->validate();

        $account = Account::findOrFail((int) $this->account_id);

        return app(SaveOptionTrade::class)->buyToOpen([
            'account_id' => $account->id,
            ...$this->positionAttributes($account),
        ]);
    }

    /**
     * The cost of the trade as entered so far, for the live summary:
     * premium paid = contracts × premium per share × shares per contract,
     * plus the opening fee (the account default × contracts when blank).
     * Null while the numbers needed are missing or not valid.
     *
     * @return array{premium: Money, fees: Money|null, total: Money|null}|null
     */
    public function summary(): ?array
    {
        if (preg_match('/^\d{1,6}$/', $this->contracts) !== 1 || preg_match('/^\d{1,6}$/', $this->multiplier) !== 1
            || (int) $this->contracts === 0 || (int) $this->multiplier === 0) {
            return null;
        }

        try {
            $premium = UnitPrice::fromDecimal($this->premium_per_share)->totalFor(Quantity::fromInteger((int) $this->contracts * (int) $this->multiplier));
            $fees = trim($this->opening_fees) !== ''
                ? Money::fromDecimal($this->opening_fees)
                : Account::query()->find((int) $this->account_id)?->option_fee_per_contract->times((int) $this->contracts);
        } catch (InvalidArgumentException) {
            return null;
        }

        return ['premium' => $premium, 'fees' => $fees, 'total' => $fees !== null ? $premium->plus($fees) : null];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            ...parent::messages(),
            'opened_on.after_or_equal' => 'Options bought before the reporting start date cannot be entered.',
        ];
    }
}
