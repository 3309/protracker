<?php

namespace App\Livewire\Forms;

use App\Actions\Options\EnsureCallsCovered;
use App\Actions\Options\SaveOptionTrade;
use App\Enums\OptionType;
use App\Exceptions\InsufficientSharesException;
use App\Exceptions\OptionPositionException;
use App\Exceptions\UncoveredCallException;
use App\Livewire\Forms\Concerns\SharedFormInput;
use App\Models\Account;
use App\Models\OptionPosition;
use App\Models\Setting;
use App\Support\CoveredCallCapacity;
use App\Support\InputFormat;
use App\Support\MarketCalendar;
use App\Support\Money;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Enters or corrects a sell-to-open option trade.
 *
 * A blank fee uses the account's default option fee × contracts. A blank
 * settlement date is calculated as the next trading day.
 */
class SellToOpenForm extends Form
{
    use SharedFormInput;

    public ?OptionPosition $optionPosition = null;

    public string $account_id = '';

    public string $underlying = '';

    public string $option_type = '';

    public string $strike = '';

    public string $expires_on = '';

    public string $opened_on = '';

    public string $settlement_date = '';

    public string $contracts = '';

    public string $multiplier = '100';

    public string $premium_per_share = '';

    public string $opening_fees = '';

    public string $reference_note = '';

    public string $reason = '';

    public function setOptionPosition(OptionPosition $optionPosition): void
    {
        $this->optionPosition = $optionPosition;
        $this->account_id = (string) $optionPosition->account_id;
        $this->underlying = $optionPosition->underlying;
        $this->option_type = $optionPosition->option_type->value;
        $this->strike = $optionPosition->strike->toDecimal();
        $this->expires_on = $optionPosition->expires_on->format('Y-m-d');
        $this->opened_on = $optionPosition->opened_on->format('Y-m-d');
        $this->settlement_date = $optionPosition->opening_settlement_date_overridden
            ? ($optionPosition->opening_settlement_date?->format('Y-m-d') ?? '')
            : '';
        $this->contracts = (string) $optionPosition->contracts;
        $this->multiplier = (string) $optionPosition->multiplier;
        $this->premium_per_share = $optionPosition->premium_per_share->toDecimal();
        $this->opening_fees = $optionPosition->opening_fees->toDecimal();
        $this->reference_note = $optionPosition->reference_note ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $creating = $this->optionPosition === null;

        return [
            'account_id' => $creating ? ['required', Rule::exists('accounts', 'id')->whereNull('archived_at')] : [],
            'underlying' => ['required', 'regex:'.InputFormat::TICKER],
            'option_type' => ['required', Rule::enum(OptionType::class)],
            'strike' => ['required', 'bail', 'regex:'.InputFormat::PRICE, $this->greaterThanZero(UnitPrice::class, 'The strike must be more than zero.')],
            'opened_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->reportingStartDate()],
            'expires_on' => ['required', 'date_format:Y-m-d', $this->notBeforeOpenedOn('The expiration date cannot be before the trade date.')],
            'settlement_date' => ['nullable', 'date_format:Y-m-d', $this->notBeforeOpenedOn('The settlement date cannot be before the trade date.')],
            'contracts' => ['required', 'integer', 'between:1,100000'],
            'multiplier' => ['required', 'integer', 'between:1,100000'],
            'premium_per_share' => ['required', 'bail', 'regex:'.InputFormat::PRICE, $this->greaterThanZero(UnitPrice::class, 'The premium must be more than zero.')],
            'opening_fees' => ['nullable', 'regex:'.InputFormat::FEE],
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
            'underlying.regex' => 'Enter a ticker such as AAPL or BRK.B.',
            'strike.regex' => 'Enter a strike price such as 50 or 52.5, with no more than four decimal places.',
            'premium_per_share.regex' => 'Enter the premium per share such as 1.20, with no more than four decimal places.',
            'opening_fees.regex' => 'Enter a dollar amount such as 0.67, with no more than two decimal places.',
            'opened_on.after_or_equal' => 'Options sold before the reporting start date are entered as opening balances instead.',
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
            'underlying' => 'ticker',
            'option_type' => 'put or call',
            'expires_on' => 'expiration date',
            'opened_on' => 'trade date',
            'settlement_date' => 'settlement date',
            'premium_per_share' => 'premium per share',
            'opening_fees' => 'opening fee',
            'reference_note' => 'reference note',
        ];
    }

    /**
     * Record the sale. A call must be covered by shares in the account that
     * are not already covering another call; otherwise nothing is saved and
     * null is returned with an error on the contracts field.
     */
    public function store(): ?OptionPosition
    {
        $this->validate();

        $account = Account::findOrFail((int) $this->account_id);
        $attributes = ['account_id' => $account->id, ...$this->positionAttributes($account)];

        if ($attributes['option_type'] !== OptionType::Call) {
            return app(SaveOptionTrade::class)->open($attributes);
        }

        $openedOn = CarbonImmutable::parse($this->opened_on);
        $capacity = CoveredCallCapacity::for($account->id, $attributes['underlying'], $openedOn);
        $maxContracts = $capacity->maxContracts($attributes['multiplier']);

        if ($attributes['contracts'] > $maxContracts) {
            $this->addError('contracts', $this->notCoveredMessage($capacity, $account, $maxContracts));

            return null;
        }

        try {
            return app(EnsureCallsCovered::class)->afterChange(
                $account->id,
                $attributes['underlying'],
                $openedOn,
                CarbonImmutable::parse($this->expires_on),
                fn () => app(SaveOptionTrade::class)->open($attributes),
            );
        } catch (UncoveredCallException $exception) {
            $this->addError('contracts', $exception->getMessage());

            return null;
        }
    }

    /**
     * Save a correction. Returns false, with an error on the matching field,
     * when it would conflict with closes already recorded, or when it would
     * make a sold call need more shares than are free to cover it.
     */
    public function update(): bool
    {
        $this->validate();

        $position = $this->optionPosition;

        if ($position === null) {
            return false;
        }

        $attributes = $this->positionAttributes($position->account);
        $save = fn () => app(SaveOptionTrade::class)->updatePosition($position, $attributes, $this->reason);

        try {
            if ($this->coversMoreShares($position, $attributes)) {
                app(EnsureCallsCovered::class)->afterChange(
                    $position->account_id,
                    $attributes['underlying'],
                    CarbonImmutable::parse($attributes['opened_on']),
                    CarbonImmutable::parse($attributes['expires_on']),
                    $save,
                );
            } else {
                $save();
            }
        } catch (OptionPositionException|InsufficientSharesException $exception) {
            $position->refresh();
            $this->addError($this->positionErrorField($exception), $exception->getMessage());

            return false;
        } catch (UncoveredCallException $exception) {
            $position->refresh();
            $this->addError('contracts', $exception->getMessage());

            return false;
        }

        $this->setOptionPosition($position);
        $this->reset('reason');

        return true;
    }

    /**
     * For a new sold call: the tickers in the chosen account with enough
     * free shares on the trade date (today while it is blank) for at least
     * one contract, and the figures for the chosen ticker. Null for a put,
     * when correcting a trade, or before an account is chosen. Only a guide
     * for the form; store() checks again.
     *
     * @return array{date: CarbonImmutable, multiplier: int, choices: list<CoveredCallCapacity>, selected: CoveredCallCapacity|null}|null
     */
    public function coveredCallAvailability(): ?array
    {
        if ($this->optionPosition !== null || $this->option_type !== OptionType::Call->value || preg_match('/^\d+$/', $this->account_id) !== 1) {
            return null;
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->opened_on) === 1 && checkdate((int) substr($this->opened_on, 5, 2), (int) substr($this->opened_on, 8, 2), (int) substr($this->opened_on, 0, 4))
            ? CarbonImmutable::parse($this->opened_on)
            : CarbonImmutable::parse(CarbonImmutable::now(Setting::current()->display_timezone)->format('Y-m-d'));
        $multiplier = preg_match('/^\d{1,6}$/', $this->multiplier) === 1 && (int) $this->multiplier > 0 ? (int) $this->multiplier : 100;
        $accountId = (int) $this->account_id;
        $ticker = $this->normalizedTicker($this->underlying);

        return [
            'date' => $date,
            'multiplier' => $multiplier,
            'choices' => CoveredCallCapacity::eligible($accountId, $date, $multiplier),
            'selected' => $ticker !== '' ? CoveredCallCapacity::for($accountId, $ticker, $date) : null,
        ];
    }

    /**
     * Whether a correction makes a sold call reserve shares it did not
     * reserve before: a put changed to a call, another ticker, more shares,
     * or an earlier trade date. Other corrections (premium, fees, notes) are
     * saved without checking, so calls imported before shares were entered
     * can still be corrected.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function coversMoreShares(OptionPosition $position, array $attributes): bool
    {
        if ($position->isLong() || $attributes['option_type'] !== OptionType::Call) {
            return false;
        }

        return $position->option_type !== OptionType::Call
            || $attributes['underlying'] !== $position->underlying
            || $position->contracts * $position->multiplier < $attributes['contracts'] * $attributes['multiplier']
            || $attributes['opened_on'] < $position->opened_on->format('Y-m-d');
    }

    private function notCoveredMessage(CoveredCallCapacity $capacity, Account $account, int $maxContracts): string
    {
        $where = "{$account->displayName()} on {$capacity->date->format('M j, Y')}";

        if ($maxContracts === 0) {
            return sprintf(
                'No %s shares are free to cover a call in %s: %s held, %s already covering open calls. Each contract needs %s shares.',
                $capacity->ticker,
                $where,
                $capacity->held->format(),
                $capacity->committed()->format(),
                $this->multiplier,
            );
        }

        return sprintf(
            'Only %s %s shares are free in %s, enough for %d more %s. You asked for %s.',
            $capacity->available()->format(),
            $capacity->ticker,
            $where,
            $maxContracts,
            $maxContracts === 1 ? 'contract' : 'contracts',
            $this->contracts,
        );
    }

    private function notBeforeOpenedOn(string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if ($this->opened_on !== '' && (string) $value < $this->opened_on) {
                $fail($message);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function positionAttributes(Account $account): array
    {
        $contracts = (int) $this->contracts;
        $settlementDate = $this->nullIfBlank($this->settlement_date);

        return [
            'underlying' => $this->normalizedTicker($this->underlying),
            'option_type' => OptionType::from($this->option_type),
            'strike' => UnitPrice::fromDecimal($this->strike),
            'expires_on' => $this->expires_on,
            'opened_on' => $this->opened_on,
            'opening_settlement_date' => $settlementDate ?? MarketCalendar::optionSettlementDate(CarbonImmutable::parse($this->opened_on))->format('Y-m-d'),
            'opening_settlement_date_overridden' => $settlementDate !== null,
            'contracts' => $contracts,
            'multiplier' => (int) $this->multiplier,
            'premium_per_share' => UnitPrice::fromDecimal($this->premium_per_share),
            'opening_fees' => $this->nullIfBlank($this->opening_fees) !== null
                ? Money::fromDecimal($this->opening_fees)
                : $account->option_fee_per_contract->times($contracts),
            'reference_note' => $this->nullIfBlank($this->reference_note),
        ];
    }
}
