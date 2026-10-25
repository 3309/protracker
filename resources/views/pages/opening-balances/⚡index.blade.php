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
    <h1 class="page-title">Opening balances</h1>

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
        <x-empty-state>
            There are no active accounts yet. <a href="{{ route('accounts.create') }}" class="link" wire:navigate>Add an account</a> first.
        </x-empty-state>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Status</th>
                        <th class="text-right">Starting cash</th>
                        <th class="text-right">Stock lots</th>
                        <th class="text-right">Open options</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->accounts as $account)
                        <tr wire:key="opening-{{ $account->id }}">
                            <td class="font-medium">{{ $account->displayName() }}</td>
                            <td>
                                <x-badge :tone="match ($account->openingStatus()) { App\Enums\OpeningStatus::Complete => 'green', App\Enums\OpeningStatus::InProgress => 'amber', default => 'gray' }">{{ $account->openingStatus()->label() }}</x-badge>
                            </td>
                            <td class="num">{{ $account->opening?->cash_balance?->format() ?? 'Not entered' }}</td>
                            <td class="num">{{ $account->opening_stock_lots_count }}</td>
                            <td class="num">{{ $account->opening_option_positions_count }}</td>
                            <td class="text-right">
                                <a href="{{ route('opening-balances.show', $account) }}" class="link" wire:navigate>Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
