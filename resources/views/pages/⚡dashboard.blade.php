<?php

use App\Enums\OpeningStatus;
use App\Models\Account;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function activeAccounts(): Collection
    {
        return Account::query()->active()->withOpeningProgress()->get();
    }

    #[Computed]
    public function completedOpeningCount(): int
    {
        return $this->activeAccounts->filter(fn (Account $account) => $account->openingStatus() === OpeningStatus::Complete)->count();
    }

    #[Computed]
    public function settings(): Setting
    {
        return Setting::current();
    }
};
?>

<div class="flex flex-col gap-6">
    <h1 class="text-2xl font-semibold">Dashboard</h1>

    <div class="card flex flex-col gap-2">
        <p>
            You have <strong>{{ $this->activeAccounts->count() }}</strong> active {{ Str::plural('account', $this->activeAccounts->count()) }}.
            Reporting starts on <strong>{{ $this->settings->reporting_start_date->format('F j, Y') }}</strong>.
        </p>

        @if ($this->activeAccounts->isEmpty())
            <p>
                Start by <a href="{{ route('accounts.create') }}" class="underline underline-offset-4">adding your first brokerage account</a>.
            </p>
        @endif
    </div>

    @if ($this->activeAccounts->isNotEmpty())
        <div class="card flex flex-col gap-2">
            <h2 class="text-lg font-semibold">Opening balances</h2>
            <p>
                <strong>{{ $this->completedOpeningCount }} of {{ $this->activeAccounts->count() }}</strong>
                {{ Str::plural('account', $this->activeAccounts->count()) }} complete.
                @if ($this->completedOpeningCount < $this->activeAccounts->count())
                    <a href="{{ route('opening-balances.index') }}" class="underline underline-offset-4" wire:navigate>Continue setting up opening balances</a>.
                @endif
            </p>
        </div>
    @endif

    <div class="card text-sm text-zinc-600 dark:text-zinc-400">
        Trades, options, dividends, cash and performance reports will appear here as later phases are built.
        This is a tracking tool, not tax software or investment advice.
    </div>
</div>
