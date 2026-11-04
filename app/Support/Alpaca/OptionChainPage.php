<?php

namespace App\Support\Alpaca;

use App\Support\UnitPrice;
use Carbon\CarbonImmutable;

/**
 * One answer's worth of an option chain, ready to show: grouped by expiration,
 * one row per strike in number order, with the call and put side by side.
 *
 * Alpaca sends expirations nearest first but contracts within a date in no
 * particular order, and a long chain is cut off partway through a date. So the
 * contracts are sorted here, after any pages have been joined. When an answer
 * was cut short the last date is held back rather than shown with strikes
 * missing; asking again from nextFrom shows it whole.
 *
 * It never claims more than it knows: isComplete is true only when the service
 * says nothing was left out.
 *
 * @phpstan-type ChainRow array{strike: UnitPrice|null, call: array<mixed>|null, put: array<mixed>|null}
 * @phpstan-type ChainGroup array{expiration: string|null, contractCount: int, statuses: list<string>, rows: list<ChainRow>}
 */
final readonly class OptionChainPage
{
    /**
     * @param  list<ChainGroup>  $groups
     */
    private function __construct(
        public array $groups,
        public int $contractCount,
        public ?string $firstExpiration,
        public ?string $lastExpiration,
        public ?string $nextFrom,
        public bool $isPartial,
        public bool $isComplete,
        public int $unclassifiedCount,
    ) {}

    /**
     * @param  bool  $canContinue  whether the answer may be followed by later
     *                             expirations; false when one date was asked for
     */
    public static function from(AlpacaResult $result, bool $canContinue = true): self
    {
        $contracts = array_values(array_filter($result->data, is_array(...)));
        $contracts = array_map(self::withKnownDetails(...), $contracts);
        $truncated = $result->meta['truncated'] ?? null;
        $dates = self::expirationDates($contracts);
        $nextFrom = null;
        $isPartial = false;

        if ($truncated === true && $canContinue && count($dates) > 1) {
            $nextFrom = $dates[count($dates) - 1];
            $contracts = array_values(array_filter($contracts, fn (array $contract): bool => $contract['expiration'] !== $nextFrom));
            $dates = self::expirationDates($contracts);
        } elseif ($truncated === true) {
            $isPartial = true;
            $nextFrom = $canContinue && $dates !== [] ? CarbonImmutable::parse($dates[0])->addDay()->format('Y-m-d') : null;
        }

        $classified = array_values(array_filter($contracts, fn (array $contract): bool => in_array($contract['type'], ['call', 'put'], true)));

        return new self(
            self::groupsFrom($classified),
            count($classified),
            $dates[0] ?? null,
            $dates === [] ? null : $dates[count($dates) - 1],
            $nextFrom,
            $isPartial,
            $truncated === false,
            count($contracts) - count($classified),
        );
    }

    /**
     * The contract's own fields, with the option symbol filling in any that
     * are missing, and expiration, type and strike in a form that can be
     * compared.
     *
     * @param  array<mixed>  $contract
     * @return array<mixed>
     */
    private static function withKnownDetails(array $contract): array
    {
        $fromSymbol = is_string($contract['symbol'] ?? null) ? AlpacaFormat::optionSymbol($contract['symbol']) : null;

        $expiration = $contract['expiration'] ?? ($fromSymbol === null ? null : $fromSymbol['expiration']->format('Y-m-d'));
        $type = $contract['type'] ?? ($fromSymbol === null ? null : $fromSymbol['type']);

        return [
            ...$contract,
            'expiration' => is_string($expiration) && self::isDate($expiration) ? $expiration : null,
            'type' => is_string($type) ? strtolower($type) : null,
            'strike_price' => AlpacaFormat::price($contract['strike'] ?? null) ?? $fromSymbol['strike'] ?? null,
        ];
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /**
     * @param  list<array<mixed>>  $contracts
     * @return list<string>
     */
    private static function expirationDates(array $contracts): array
    {
        $dates = array_values(array_unique(array_filter(array_column($contracts, 'expiration'), is_string(...))));
        sort($dates);

        return $dates;
    }

    /**
     * @param  list<array<mixed>>  $contracts
     * @return list<ChainGroup>
     */
    private static function groupsFrom(array $contracts): array
    {
        $byExpiration = [];

        foreach ($contracts as $contract) {
            $byExpiration[$contract['expiration'] ?? ''][] = $contract;
        }

        // Dates sort as text; contracts with no date ('') go last.
        uksort($byExpiration, fn (string|int $a, string|int $b): int => [(string) $a === '', (string) $a] <=> [(string) $b === '', (string) $b]);

        $groups = [];

        foreach ($byExpiration as $expiration => $grouped) {
            $statuses = array_values(array_unique(array_filter(array_column($grouped, 'dataStatus'), is_string(...))));
            sort($statuses);

            $groups[] = [
                'expiration' => (string) $expiration === '' ? null : (string) $expiration,
                'contractCount' => count($grouped),
                'statuses' => $statuses,
                'rows' => self::rowsFrom($grouped),
            ];
        }

        return $groups;
    }

    /**
     * Lowest strike first, with a call and a put at the same strike on one row.
     * A second contract for the same side and strike gets its own row, and
     * contracts with no strike go last.
     *
     * @param  list<array<mixed>>  $contracts
     * @return list<ChainRow>
     */
    private static function rowsFrom(array $contracts): array
    {
        usort($contracts, fn (array $a, array $b): int => [self::strikeOrder($a), $a['type'], $a['symbol'] ?? ''] <=> [self::strikeOrder($b), $b['type'], $b['symbol'] ?? '']);

        $rows = [];

        foreach ($contracts as $contract) {
            $strike = $contract['strike_price'] instanceof UnitPrice ? $contract['strike_price'] : null;
            $side = $contract['type'] === 'call' ? 'call' : 'put';
            $placed = false;

            foreach ($rows as $index => $row) {
                if ($strike !== null && $row['strike']?->equals($strike) && $row[$side] === null) {
                    $rows[$index][$side] = $contract;
                    $placed = true;
                    break;
                }
            }

            if (! $placed) {
                $rows[] = ['strike' => $strike, 'call' => $side === 'call' ? $contract : null, 'put' => $side === 'put' ? $contract : null];
            }
        }

        return $rows;
    }

    /**
     * @param  array<mixed>  $contract
     * @return array{0: bool, 1: int}
     */
    private static function strikeOrder(array $contract): array
    {
        $strike = $contract['strike_price'];

        return $strike instanceof UnitPrice ? [false, $strike->units] : [true, 0];
    }
}
