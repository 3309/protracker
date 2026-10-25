<?php

use App\Models\Account;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Accounts')] class extends Component
{
    /**
     * Active accounts first, then archived ones, each sorted by nickname.
     *
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()
            ->orderByRaw('archived_at is not null')
            ->orderBy('nickname')
            ->get();
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">Accounts</h1>
        <a href="{{ route('accounts.create') }}" class="btn-primary" wire:navigate>Add account</a>
    </div>

    @if ($this->accounts->isEmpty())
        <div class="card">No accounts yet. Add your Fidelity and Robinhood accounts to get started.</div>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Type</th>
                        <th class="px-4 py-3 font-medium">Last 4</th>
                        <th class="px-4 py-3 text-right font-medium">Option fee / contract</th>
                        <th class="px-4 py-3 text-right font-medium">Stock fee / trade</th>
                        <th class="px-4 py-3 text-right font-medium">Assignment fee</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->accounts as $account)
                        <tr wire:key="account-{{ $account->id }}" @class(['text-zinc-400 dark:text-zinc-500' => $account->isArchived()])>
                            <td class="px-4 py-3 font-medium">{{ $account->displayName() }}</td>
                            <td class="px-4 py-3">{{ $account->account_type->label() }}</td>
                            <td class="px-4 py-3">{{ $account->account_number_last4 ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $account->option_fee_per_contract->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $account->stock_fee_per_trade->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $account->assignment_fee->format() }}</td>
                            <td class="px-4 py-3">{{ $account->isArchived() ? 'Archived' : 'Active' }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('accounts.edit', $account) }}" class="underline underline-offset-4" wire:navigate>Edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
