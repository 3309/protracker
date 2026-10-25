<?php

use App\Livewire\Forms\AccountForm;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit account')] class extends Component
{
    public Account $account;

    public AccountForm $form;

    public function mount(Account $account): void
    {
        $this->account = $account;
        $this->form->setAccount($account);
    }

    public function save(): void
    {
        $this->form->update();

        unset($this->auditEntries);

        session()->now('message', 'Account saved.');
    }

    public function archive(): void
    {
        $this->validateReason();

        $this->account->archive($this->form->reason);

        $this->finishStatusChange('Account archived. Its history is kept and you can restore it at any time.');
    }

    public function restore(): void
    {
        $this->validateReason();

        $this->account->restore($this->form->reason);

        $this->finishStatusChange('Account restored.');
    }

    /**
     * @return Collection<int, AuditEntry>
     */
    #[Computed]
    public function auditEntries(): Collection
    {
        return $this->account->auditEntries()->with('user')->latest('id')->get();
    }

    #[Computed]
    public function displayTimezone(): string
    {
        return Setting::current()->display_timezone;
    }

    private function validateReason(): void
    {
        $this->validate(
            ['form.reason' => ['required', 'string', 'max:1000']],
            ['form.reason.required' => 'Please explain why you are making this change. It is saved in the audit history.'],
        );
    }

    private function finishStatusChange(string $message): void
    {
        $this->form->reset('reason');

        unset($this->auditEntries);

        session()->now('message', $message);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">
            {{ $account->displayName() }}
            @if ($account->isArchived())
                <span class="ms-2 rounded bg-zinc-200 px-2 py-0.5 text-sm font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">Archived</span>
            @endif
        </h1>
        <a href="{{ route('accounts.index') }}" class="btn-secondary" wire:navigate>Back to accounts</a>
    </div>

    @if (session('message'))
        <div class="status-message">{{ session('message') }}</div>
    @endif

    <form wire:submit="save" class="card flex flex-col gap-6">
        @include('partials.account-form-fields')

        <div>
            <label for="reason" class="form-label">Reason for this change</label>
            <textarea id="reason" rows="2" wire:model="form.reason" class="form-input" placeholder="e.g. Fidelity raised its option fee"></textarea>
            <p class="form-help">Required for any change, including archiving. It is kept permanently in the history below.</p>
            @error('form.reason') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap gap-3">
            <button type="submit" class="btn-primary">Save changes</button>

            @if ($account->isArchived())
                <button type="button" wire:click="restore" class="btn-secondary">Restore account</button>
            @else
                <button type="button" wire:click="archive" wire:confirm="Archive this account? Nothing is deleted and you can restore it later." class="btn-danger">Archive account</button>
            @endif
        </div>
    </form>

    @include('partials.audit-history', ['entries' => $this->auditEntries, 'timezone' => $this->displayTimezone])
</div>
