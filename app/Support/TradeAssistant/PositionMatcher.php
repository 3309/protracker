<?php

namespace App\Support\TradeAssistant;

use App\Enums\OptionSide;
use App\Models\Account;
use App\Models\OptionPosition;
use App\Support\UnitPrice;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Finds the open option positions a proposed close, expiry, assignment or
 * roll could apply to. The tracker does the matching from its own records;
 * the AI only supplies the ticker, put or call, strike, expiration and
 * account it read. Details it did not find are not used to narrow the
 * search, so several matches are possible and the owner chooses one.
 */
class PositionMatcher
{
    /**
     * Open, not voided positions in active accounts that match the proposal
     * and can take the action: a buy to close, assignment or roll needs a
     * sold option, a sell to close a bought one, and an expiry either.
     * Empty when the proposal has no ticker.
     *
     * @return Collection<int, OptionPosition>
     */
    public static function candidates(TradeProposal $proposal): Collection
    {
        $ticker = $proposal->value('symbol');

        try {
            $strike = $proposal->value('strike') !== '' ? UnitPrice::fromDecimal($proposal->value('strike')) : null;
        } catch (InvalidArgumentException) {
            return new Collection;
        }

        if ($ticker === '') {
            return new Collection;
        }

        $sides = match ($proposal->action) {
            'sell_to_close' => [OptionSide::Long],
            'expire' => [OptionSide::Short, OptionSide::Long],
            default => [OptionSide::Short],
        };

        return OptionPosition::query()
            ->open()
            ->notVoided()
            ->with('account')
            ->whereIn('side', $sides)
            ->whereIn('account_id', Account::query()->active()->select('id'))
            ->where('underlying', $ticker)
            ->when($proposal->value('account') !== '', fn ($query) => $query->where('account_id', (int) $proposal->value('account')))
            ->when($proposal->value('option_type') !== '', fn ($query) => $query->where('option_type', $proposal->value('option_type')))
            ->when($strike !== null, fn ($query) => $query->where('strike', $strike?->units))
            ->when($proposal->value('expiration') !== '', fn ($query) => $query->where('expires_on', $proposal->value('expiration')))
            ->orderBy('expires_on')
            ->orderBy('id')
            ->get();
    }
}
