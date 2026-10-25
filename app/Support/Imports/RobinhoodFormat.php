<?php

namespace App\Support\Imports;

use App\Enums\ImportAction;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use BcMath\Number;
use InvalidArgumentException;
use RoundingMode;

/**
 * The Robinhood account activity report built from the sample export:
 * columns Activity Date, Process Date, Settle Date, Instrument, Description,
 * Trans Code, Quantity, Price and Amount.
 *
 * - The Amount already includes fees; there is no fee column. Stock buys
 *   take their price from the Amount (Robinhood rounds the price shown);
 *   sales and option trades show any shortfall from the Amount as the fee.
 *   Cash then matches Robinhood to the cent.
 * - Option contracts are read from the description ("SOXL 11/1/2024 Put
 *   $31.00").
 * - Cash dividends take the amount per share from the description and use
 *   the record date as the ex-date from the move to T+1 settlement
 *   (2024-05-28), when the two became the same day; earlier dividends wait
 *   for the ex-date.
 * - Stock lending income and IRA matches are other credits, not deposits.
 * - Any other transaction code is flagged, never guessed.
 */
final class RobinhoodFormat implements ImportFormat
{
    public const array REQUIRED_COLUMNS = ['Activity Date', 'Process Date', 'Settle Date', 'Instrument', 'Description', 'Trans Code', 'Quantity', 'Price', 'Amount'];

    /**
     * From this record date on, the ex-dividend date is the record date.
     */
    public const string SAME_DAY_EX_DATE_FROM = '2024-05-28';

    private const string OPTION_DESCRIPTION = '/^([A-Z][A-Z0-9.]{0,9}) (\d{1,2}\/\d{1,2}\/\d{4}) (Put|Call) \$([\d,]+(?:\.\d+)?)$/i';

    private const string DIVIDEND_DESCRIPTION = '/R\/D (\d{4}-\d{2}-\d{2}) P\/D \d{4}-\d{2}-\d{2} - [\d.,]+ shares at (\d+(?:\.\d+)?)/';

    public function missingColumns(array $header): array
    {
        $present = array_map(fn (string $column) => strtolower(trim($column)), $header);

        return array_values(array_filter(self::REQUIRED_COLUMNS, fn (string $column) => ! in_array(strtolower($column), $present, true)));
    }

    public function normalize(array $raw): array
    {
        $values = [];

        foreach ($raw as $column => $value) {
            $values[strtolower(trim($column))] = trim($value);
        }

        $code = strtoupper($values['trans code'] ?? '');
        $description = (string) preg_replace('/\s+/', ' ', $values['description'] ?? '');

        $data = [
            ...array_fill_keys(['fees', 'option_type', 'strike', 'expiration', 'multiplier', 'ex_date', 'transaction_id'], ''),
            'date' => self::date($values['activity date'] ?? ''),
            'settlement_date' => self::date($values['settle date'] ?? ''),
            'symbol' => strtoupper($values['instrument'] ?? ''),
            'quantity' => self::number($values['quantity'] ?? ''),
            'price' => self::number($values['price'] ?? ''),
            'amount' => self::number($values['amount'] ?? ''),
            'description' => '',
        ];

        return match ($code) {
            'BUY' => self::recognized(ImportAction::Buy, self::stockBuy($data)),
            'SELL' => self::recognized(ImportAction::Sell, self::stockSale($data)),
            'STO' => self::optionTrade(ImportAction::SellToOpen, $data, $description),
            'BTC' => self::optionTrade(ImportAction::BuyToClose, $data, $description),
            'OEXP' => self::expiry($data, $description),
            'CDIV' => self::recognized(ImportAction::Dividend, self::dividend($data, $description)),
            'CFIR' => self::recognized(ImportAction::Deposit, [...$data, 'symbol' => '', 'quantity' => '', 'price' => '', 'description' => $description]),
            'SLIP' => self::recognized(ImportAction::OtherCredit, [...$data, 'quantity' => '', 'price' => '', 'description' => "Stock lending income ({$data['symbol']})."]),
            'MTCH' => self::recognized(ImportAction::OtherCredit, [...$data, 'symbol' => '', 'quantity' => '', 'price' => '', 'description' => "IRA match: {$description}"]),
            'BTO', 'STC' => self::unrecognized($data, 'Buying an option to open (BTO) or selling it to close (STC): Protracker tracks only options you sell, so this row is not imported. Skip it with a reason.'),
            '' => self::unrecognized($data, 'The transaction code is blank.'),
            default => self::unrecognized($data, "The transaction code \"{$values['trans code']}\" ({$description}) is not one this format understands. It was not guessed."),
        };
    }

    /**
     * Robinhood rows each say what they are, so nothing changes.
     */
    public function combine(array $rows): array
    {
        return $rows;
    }

    /**
     * The price is the Amount paid ÷ shares, to four decimal places rounded
     * down; any cents left over from that rounding are the fee.
     *
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    private static function stockBuy(array $data): array
    {
        return self::attempt($data, function () use ($data): array {
            $shares = Quantity::fromDecimal($data['quantity']);
            $paid = Money::fromCents(abs(Money::fromDecimal($data['amount'])->cents));
            $price = UnitPrice::fromDecimal(self::money($paid)->div($shares->toNumber(), 10)->round(4, RoundingMode::TowardsZero)->value);

            return [...$data, 'price' => $price->toDecimal(), 'fees' => $paid->minus($price->totalFor($shares))->toDecimal()];
        });
    }

    /**
     * The price shown, with the shortfall of the Amount received as the fee.
     * When more was received than the price shown gives, the price is the
     * Amount ÷ shares, to four decimal places rounded up.
     *
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    private static function stockSale(array $data): array
    {
        return self::attempt($data, function () use ($data): array {
            $shares = Quantity::fromDecimal($data['quantity']);
            $received = Money::fromCents(abs(Money::fromDecimal($data['amount'])->cents));
            $price = UnitPrice::fromDecimal($data['price']);

            if ($price->totalFor($shares)->cents < $received->cents) {
                $price = UnitPrice::fromDecimal(self::money($received)->div($shares->toNumber(), 10)->round(4, RoundingMode::AwayFromZero)->value);
            }

            return [...$data, 'price' => $price->toDecimal(), 'fees' => $price->totalFor($shares)->minus($received)->toDecimal()];
        });
    }

    /**
     * @param  array<string, string>  $data
     * @return array{action: ImportAction|null, data: array<string, string>, messages: list<string>}
     */
    private static function optionTrade(ImportAction $action, array $data, string $description): array
    {
        $contract = self::optionContract($description, $data['symbol']);

        if ($contract === null) {
            return self::unrecognized($data, "The option \"{$description}\" is not in the expected form (for example SOXL 11/1/2024 Put \$31.00).");
        }

        $data = [...$data, ...$contract];

        return self::recognized($action, self::attempt($data, function () use ($action, $data): array {
            $gross = UnitPrice::fromDecimal($data['price'])->totalFor(Quantity::fromInteger((int) $data['quantity'] * 100));
            $amount = Money::fromCents(abs(Money::fromDecimal($data['amount'])->cents));
            $fees = $action === ImportAction::SellToOpen ? $gross->minus($amount) : $amount->minus($gross);

            return [...$data, 'fees' => $fees->isNegative() ? '0' : $fees->toDecimal()];
        }));
    }

    /**
     * @param  array<string, string>  $data
     * @return array{action: ImportAction|null, data: array<string, string>, messages: list<string>}
     */
    private static function expiry(array $data, string $description): array
    {
        $contract = self::optionContract((string) preg_replace('/^Option Expiration for /i', '', $description), $data['symbol']);

        if ($contract === null) {
            return self::unrecognized($data, "The option \"{$description}\" is not in the expected form (for example Option Expiration for SOXL 10/25/2024 Put \$32.00).");
        }

        return self::recognized(ImportAction::Expired, [...$data, ...$contract, 'price' => '', 'amount' => '', 'settlement_date' => '']);
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    private static function dividend(array $data, string $description): array
    {
        $data = [...$data, 'quantity' => '', 'price' => '', 'description' => $description];

        if (preg_match(self::DIVIDEND_DESCRIPTION, $description, $parts) !== 1) {
            return $data;
        }

        return [
            ...$data,
            'price' => $parts[2],
            'ex_date' => $parts[1] >= self::SAME_DAY_EX_DATE_FROM ? $parts[1] : '',
        ];
    }

    /**
     * "SOXL 11/1/2024 Put $31.00" → SOXL put, strike 31.00, expiring
     * 2024-11-01. The ticker must match the Instrument column.
     *
     * @return array{option_type: string, strike: string, expiration: string}|null
     */
    private static function optionContract(string $description, string $instrument): ?array
    {
        if (preg_match(self::OPTION_DESCRIPTION, $description, $parts) !== 1 || strtoupper($parts[1]) !== $instrument) {
            return null;
        }

        $expiration = self::date($parts[2]);

        if ($expiration === $parts[2]) {
            return null;
        }

        return [
            'option_type' => strtolower($parts[3]),
            'strike' => str_replace(',', '', $parts[4]),
            'expiration' => $expiration,
        ];
    }

    /**
     * Work out derived values, leaving the row as it is when its numbers
     * cannot be read, so the row checker reports them.
     *
     * @param  array<string, string>  $data
     * @param  callable(): array<string, string>  $derive
     * @return array<string, string>
     */
    private static function attempt(array $data, callable $derive): array
    {
        try {
            return $derive();
        } catch (InvalidArgumentException) {
            return $data;
        }
    }

    private static function money(Money $money): Number
    {
        return (new Number($money->cents))->div(100, 2);
    }

    /**
     * @param  array<string, string>  $data
     * @return array{action: ImportAction, data: array<string, string>, messages: list<string>}
     */
    private static function recognized(ImportAction $action, array $data): array
    {
        return ['action' => $action, 'data' => $data, 'messages' => []];
    }

    /**
     * @param  array<string, string>  $data
     * @return array{action: null, data: array<string, string>, messages: list<string>}
     */
    private static function unrecognized(array $data, string $message): array
    {
        return ['action' => null, 'data' => $data, 'messages' => [$message]];
    }

    /**
     * "$1,234.50" → "1234.50", "($654.93)" → "-654.93".
     */
    private static function number(string $value): string
    {
        $value = str_replace(['$', ',', ' '], '', trim($value));

        if (preg_match('/^\((.+)\)$/', $value, $matches) === 1) {
            return '-'.$matches[1];
        }

        return $value;
    }

    /**
     * "10/30/2024" → "2024-10-30". Anything else is left as it is so the
     * row checker can report it.
     */
    private static function date(string $value): string
    {
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $parts) !== 1 || ! checkdate((int) $parts[1], (int) $parts[2], (int) $parts[3])) {
            return $value;
        }

        return sprintf('%04d-%02d-%02d', $parts[3], $parts[1], $parts[2]);
    }
}
