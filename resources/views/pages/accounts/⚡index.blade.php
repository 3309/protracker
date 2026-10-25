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
        <h1 class="page-title">Accounts</h1>
        <a href="{{ route('accounts.create') }}" class="btn-primary" wire:navigate>Add account</a>
    </div>

    @if ($this->accounts->isEmpty())
        <x-empty-state icon="cash">
            No accounts yet. Add your Fidelity and Robinhood accounts to get started.
            <x-slot:action>
                <a href="{{ route('accounts.create') }}" class="btn-primary" wire:navigate>Add account</a>
            </x-slot:action>
        </x-empty-state>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Type</th>
                        <th>Last 4</th>
                        <th class="text-right">Option fee / contract</th>
                        <th class="text-right">Stock fee / trade</th>
                        <th class="text-right">Assignment fee</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->accounts as $account)
                        <tr wire:key="account-{{ $account->id }}" @class(['text-zinc-400 dark:text-zinc-500' => $account->isArchived()])>
                            <td class="font-medium">{{ $account->displayName() }}</td>
                            <td>{{ $account->account_type->label() }}</td>
                            <td>{{ $account->account_number_last4 ?? '—' }}</td>
                            <td class="num">{{ $account->option_fee_per_contract->format() }}</td>
                            <td class="num">{{ $account->stock_fee_per_trade->format() }}</td>
                            <td class="num">{{ $account->assignment_fee->format() }}</td>
                            <td><x-badge :tone="$account->isArchived() ? 'gray' : 'green'">{{ $account->isArchived() ? 'Archived' : 'Active' }}</x-badge></td>
                            <td class="text-right">
                                <a href="{{ route('accounts.edit', $account) }}" class="link" wire:navigate>Edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
