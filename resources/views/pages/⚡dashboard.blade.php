<?php

use App\Models\Account;
use App\Models\Setting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    #[Computed]
    public function activeAccountCount(): int
    {
        return Account::query()->active()->count();
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
            You have <strong>{{ $this->activeAccountCount }}</strong> active {{ Str::plural('account', $this->activeAccountCount) }}.
            Reporting starts on <strong>{{ $this->settings->reporting_start_date->format('F j, Y') }}</strong>.
        </p>

        @if ($this->activeAccountCount === 0)
            <p>
                Start by <a href="{{ route('accounts.create') }}" class="underline underline-offset-4">adding your first brokerage account</a>.
            </p>
        @endif
    </div>

    <div class="card text-sm text-zinc-600 dark:text-zinc-400">
        Trades, options, dividends, cash and performance reports will appear here as later phases are built.
        This is a tracking tool, not tax software or investment advice.
    </div>
</div>
