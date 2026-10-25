<?php

namespace App\Support\Imports;

use App\Enums\ImportAction;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The Fidelity activity layout built from the sample export: columns date,
 * symbol, instrumentType, quantity, activityType, unitPrice, currency, fee,
 * amount, subtype and account (ignored: the account is chosen on upload).
 *
 * - Options are identified by their OCC symbol (TSLA240712C00265000).
 * - Expiries are dated on the option's expiration, not the day the broker
 *   posted them, and an expiry with a matching stock trade at the strike
 *   is recorded as an assignment (see combine()).
 * - Income from the FDRXX cash sweep fund is interest on cash, not a stock
 *   dividend.
 * - Cash adjustments, bought options (BTO/STC), reinvested dividends,
 *   other currencies and anything unfamiliar are flagged, never guessed.
 */
final class FidelityFormat implements ImportFormat
{
    public const array REQUIRED_COLUMNS = ['date', 'symbol', 'instrumentType', 'quantity', 'activityType', 'unitPrice', 'currency', 'fee', 'amount', 'subtype'];

    /**
     * Money market funds Fidelity uses to hold uninvested cash.
     */
    public const array CASH_SWEEP_FUNDS = ['FDRXX'];

    private const string OPTION_SYMBOL = '/^([A-Z][A-Z0-9.]{0,5})(\d{2})(\d{2})(\d{2})([CP])(\d{8})$/';

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

        $instrument = strtoupper($values['instrumenttype'] ?? '');
        $activity = strtoupper($values['activitytype'] ?? '');
        $subtype = strtoupper($values['subtype'] ?? '');
        $symbol = strtoupper($values['symbol'] ?? '');
        $currency = strtoupper($values['currency'] ?? '');

        $data = [
            ...array_fill_keys(['option_type', 'strike', 'expiration', 'multiplier', 'settlement_date', 'ex_date', 'transaction_id', 'description'], ''),
            'date' => $values['date'] ?? '',
            'symbol' => $symbol,
            'quantity' => self::number($values['quantity'] ?? ''),
            'price' => self::number($values['unitprice'] ?? ''),
            'fees' => self::number($values['fee'] ?? ''),
            'amount' => self::number($values['amount'] ?? ''),
        ];

        if ($currency !== 'USD') {
            return self::unrecognized($data, "The currency is \"{$values['currency']}\". Only US dollars are supported.");
        }

        $described = trim("{$activity} {$subtype}");

        return match ($instrument) {
            'CASH' => self::cash($data, $activity, $described),
            'EQUITY' => self::equity($data, $activity, $subtype, $described),
            'OPTION' => self::option($data, $activity, $subtype, $described),
            default => self::unrecognized($data, "The activity \"{$described}\" for instrument type \"{$values['instrumenttype']}\" is not one this format understands. It was not guessed."),
        };
    }

    /**
     * Records an expiry as an assignment when the file also has the stock
     * trade the assignment caused: same stock, contracts × 100 shares at the
     * strike, bought for a put or sold for a call, within three days. The
     * stock row's fee becomes the assignment fee; the stock row itself is
     * then paired with the assignment and not imported separately.
     */
    public function combine(array $rows): array
    {
        $used = [];

        foreach ($rows as $number => $row) {
            if ($row['action'] !== ImportAction::Expired) {
                continue;
            }

            $data = $row['data'];
            $stockAction = $data['option_type'] === 'put' ? ImportAction::Buy : ImportAction::Sell;
            $shares = Quantity::fromInteger((int) $data['quantity'] * 100);

            foreach ($rows as $stockNumber => $stockRow) {
                if (in_array($stockNumber, $used, true)
                    || $stockRow['action'] !== $stockAction
                    || $stockRow['data']['symbol'] !== $data['symbol']
                    || ! self::sameValue(fn () => Quantity::fromDecimal($stockRow['data']['quantity'])->equals($shares))
                    || ! self::sameValue(fn () => UnitPrice::fromDecimal($stockRow['data']['price'])->equals(UnitPrice::fromDecimal($data['strike'])))
                    || self::daysApart($data['date'], $stockRow['data']['date']) > 3) {
                    continue;
                }

                $used[] = $stockNumber;
                $rows[$number]['action'] = ImportAction::Assigned;
                $rows[$number]['data']['fees'] = $stockRow['data']['fees'];
                $rows[$number]['data']['description'] = trim("Listed by the broker as an expiry; recorded as an assignment because line {$stockNumber} "
                    .($stockAction === ImportAction::Buy ? 'buys' : 'sells')." {$stockRow['data']['quantity']} {$data['symbol']} at the strike. {$data['description']}");

                break;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $data
     * @return array{action: ImportAction|null, data: array<string, string>, messages: list<string>}
     */
    private static function cash(array $data, string $activity, string $described): array
    {
        $data = [...$data, 'symbol' => '', 'quantity' => '', 'price' => ''];

        return match ($activity) {
            'DEPOSIT' => ['action' => ImportAction::Deposit, 'data' => $data, 'messages' => []],
            'WITHDRAWAL' => ['action' => ImportAction::Withdrawal, 'data' => $data, 'messages' => []],
            'ADJUSTMENT' => self::unrecognized($data, 'A cash adjustment: the file does not say what it is, so it was not guessed. Correct it to interest or another credit or charge, or skip it.'),
            default => self::unrecognized($data, "The cash activity \"{$described}\" is not one this format understands. It was not guessed."),
        };
    }

    /**
     * @param  array<string, string>  $data
     * @return array{action: ImportAction|null, data: array<string, string>, messages: list<string>}
     */
    private static function equity(array $data, string $activity, string $subtype, string $described): array
    {
        $sweepFund = in_array($data['symbol'], self::CASH_SWEEP_FUNDS, true);

        return match (true) {
            $activity === 'BUY' && $subtype === '' => ['action' => ImportAction::Buy, 'data' => $data, 'messages' => []],
            $activity === 'SELL' && $subtype === '' => ['action' => ImportAction::Sell, 'data' => $data, 'messages' => []],
            $activity === 'DIVIDEND' && $sweepFund => ['action' => ImportAction::Interest, 'data' => [
                ...$data,
                'symbol' => '',
                'quantity' => '',
                'price' => '',
                'description' => "{$data['symbol']} cash sweep income, imported as interest on cash.",
            ], 'messages' => []],
            $activity === 'DIVIDEND' && $subtype === '' => ['action' => ImportAction::Dividend, 'data' => [...$data, 'quantity' => '', 'price' => ''], 'messages' => []],
            $activity === 'DIVIDEND' => self::unrecognized($data, "A reinvested dividend ({$subtype}) of {$data['symbol']}: buying shares with a dividend is not handled, so it was not guessed. Record the dividend and the purchase by hand, or skip it."),
            default => self::unrecognized($data, "The stock activity \"{$described}\" is not one this format understands. It was not guessed."),
        };
    }

    /**
     * @param  array<string, string>  $data
     * @return array{action: ImportAction|null, data: array<string, string>, messages: list<string>}
     */
    private static function option(array $data, string $activity, string $subtype, string $described): array
    {
        $contract = self::optionContract($data['symbol']);

        if ($contract === null) {
            return self::unrecognized($data, "The option symbol \"{$data['symbol']}\" is not in the expected form (for example TSLA240712C00265000).");
        }

        $postedOn = $data['date'];
        $data = [...$data, ...$contract];

        return match (true) {
            $activity === 'SELL' && $subtype === 'STO' => ['action' => ImportAction::SellToOpen, 'data' => $data, 'messages' => []],
            $activity === 'BUY' && $subtype === 'BTC' => ['action' => ImportAction::BuyToClose, 'data' => $data, 'messages' => []],
            $activity === 'ADJUSTMENT' && $subtype === 'OPTION_EXPIRY' => ['action' => ImportAction::Expired, 'data' => [
                ...$data,
                'price' => '',
                'fees' => '',
                'date' => $postedOn !== '' && $postedOn < $contract['expiration'] ? $postedOn : $contract['expiration'],
                'description' => $postedOn > $contract['expiration'] ? "Posted by the broker on {$postedOn}." : '',
            ], 'messages' => []],
            $subtype === 'BTO', $subtype === 'STC' => self::unrecognized($data, 'Buying an option to open (BTO) or selling it to close (STC): Protracker tracks only options you sell, so this row is not imported. Skip it with a reason.'),
            default => self::unrecognized($data, "The option activity \"{$described}\" is not one this format understands. It was not guessed."),
        };
    }

    /**
     * "TSLA240712C00265000" → TSLA call, strike 265, expiring 2024-07-12.
     *
     * @return array{symbol: string, option_type: string, strike: string, expiration: string}|null
     */
    private static function optionContract(string $symbol): ?array
    {
        if (preg_match(self::OPTION_SYMBOL, $symbol, $parts) !== 1 || ! checkdate((int) $parts[3], (int) $parts[4], 2000 + (int) $parts[2])) {
            return null;
        }

        $thousandths = (int) $parts[6];
        $fraction = rtrim(str_pad((string) ($thousandths % 1000), 3, '0', STR_PAD_LEFT), '0');

        return [
            'symbol' => $parts[1],
            'option_type' => $parts[5] === 'P' ? 'put' : 'call',
            'strike' => intdiv($thousandths, 1000).($fraction !== '' ? ".{$fraction}" : ''),
            'expiration' => "20{$parts[2]}-{$parts[3]}-{$parts[4]}",
        ];
    }

    /**
     * @param  array<string, string>  $data
     * @return array{action: null, data: array<string, string>, messages: list<string>}
     */
    private static function unrecognized(array $data, string $message): array
    {
        return ['action' => null, 'data' => $data, 'messages' => [$message]];
    }

    private static function number(string $value): string
    {
        return str_replace(['$', ',', ' '], '', trim($value));
    }

    /**
     * @param  callable(): bool  $compare
     */
    private static function sameValue(callable $compare): bool
    {
        try {
            return $compare();
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function daysApart(string $first, string $second): int
    {
        if (! CarbonImmutable::canBeCreatedFromFormat($first, 'Y-m-d') || ! CarbonImmutable::canBeCreatedFromFormat($second, 'Y-m-d')) {
            return PHP_INT_MAX;
        }

        return (int) abs(CarbonImmutable::parse($first)->diffInDays(CarbonImmutable::parse($second)));
    }
}
