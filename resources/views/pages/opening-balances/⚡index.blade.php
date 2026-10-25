<?php

use App\Models\Account;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Opening balances')] class extends Component
{
    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()->active()->withOpeningProgress()->orderBy('nickname')->get();
    }

    #[Computed]
    public function reportingStartDate(): CarbonImmutable
    {
        return Setting::current()->reporting_start_date;
    }
};
?>

<div class="flex flex-col gap-6">
    <h1 class="text-2xl font-semibold">Opening balances</h1>

    <div class="card flex flex-col gap-2 text-sm">
        <p>
            Record what each account already held on <strong>{{ $this->reportingStartDate->format('F j, Y') }}</strong>:
            its cash, the stock lots you owned, and any options that were still open.
        </p>
        <p class="text-zinc-600 dark:text-zinc-400">
            Opening balances are a starting point. They are never counted as deposits, income, premiums or profits
            in your reports. Stock lots and options keep their original dates and costs, so later sales and closes
            are calculated correctly.
        </p>
    </div>

    @if ($this->accounts->isEmpty())
        <div class="card">
            There are no active accounts yet. <a href="{{ route('accounts.create') }}" class="underline underline-offset-4" wire:navigate>Add an account</a> first.
        </div>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">Starting cash</th>
                        <th class="px-4 py-3 text-right font-medium">Stock lots</th>
                        <th class="px-4 py-3 text-right font-medium">Open options</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->accounts as $account)
                        <tr wire:key="opening-{{ $account->id }}">
                            <td class="px-4 py-3 font-medium">{{ $account->displayName() }}</td>
                            <td class="px-4 py-3">{{ $account->openingStatus()->label() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $account->opening?->cash_balance?->format() ?? 'Not entered' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $account->opening_stock_lots_count }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $account->opening_option_positions_count }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('opening-balances.show', $account) }}" class="underline underline-offset-4" wire:navigate>Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
