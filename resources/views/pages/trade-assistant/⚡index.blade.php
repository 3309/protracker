<?php

use App\Exceptions\TradeAssistantException;
use App\Models\Account;
use App\Support\TradeAssistant\TradeContext;
use App\Support\TradeAssistant\TradeExtractor;
use App\Support\TradeAssistant\TradeProposal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The AI Trade Assistant: describe a trade in plain English, review what
 * the AI filled in, and save it through the normal entry forms.
 *
 * Analyzing only fills in a proposal; nothing is saved until the review
 * panel's Confirm and save.
 *
 * @phpstan-import-type ProposalArray from TradeProposal
 */
new #[Title('AI Trade Assistant')] class extends Component
{
    /**
     * Analyses each user may run per minute.
     */
    private const int ANALYSES_PER_MINUTE = 10;

    public string $description = '';

    public string $account_id = '';

    /** @var ProposalArray|null */
    #[Locked]
    public ?array $proposal = null;

    #[Locked]
    public string $proposalKey = '';

    public ?string $failure = null;

    public function mount(): void
    {
        if ($this->accounts->count() === 1) {
            $this->account_id = (string) $this->accounts->first()?->id;
        }
    }

    public function analyze(): void
    {
        $this->validate([
            'description' => ['required', 'string', 'max:2000'],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->whereNull('archived_at')],
        ], [
            'description.required' => 'Describe the trade first.',
            'description.max' => 'Keep the description under 2,000 characters.',
            'account_id.exists' => 'Choose an active account.',
        ]);

        $this->reset('proposal', 'proposalKey', 'failure');

        $limiterKey = 'trade-assistant:'.auth()->id();

        if (RateLimiter::tooManyAttempts($limiterKey, self::ANALYSES_PER_MINUTE)) {
            $this->failure = 'That is a lot of analyses in a short time. Wait '.RateLimiter::availableIn($limiterKey).' seconds and try again.';

            return;
        }

        RateLimiter::hit($limiterKey);

        try {
            $proposal = app(TradeExtractor::class)->extract($this->description, TradeContext::current());
        } catch (TradeAssistantException $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $proposal = $proposal->toArray();

        if ($proposal['fields']['account']['value'] === null && $this->account_id !== '') {
            $proposal['fields']['account'] = ['value' => $this->account_id, 'status' => 'selected'];
        }

        $this->proposal = $proposal;
        $this->proposalKey = (string) Str::uuid();
    }

    /**
     * Discard the proposal; the description stays so it can be changed and analyzed again.
     */
    public function cancel(): void
    {
        $this->reset('proposal', 'proposalKey', 'failure');
    }

    public function proposed(): ?TradeProposal
    {
        return $this->proposal !== null ? TradeProposal::fromArray($this->proposal) : null;
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()->active()->orderBy('nickname')->get();
    }
};
?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="page-title">AI Trade Assistant</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Describe one stock or option trade in plain English. The assistant fills in the entry form for you to check;
            nothing is saved until you confirm.
        </p>
    </div>

    <form wire:submit="analyze" class="card flex flex-col gap-4">
        <div>
            <label for="description" class="form-label required">Describe the trade</label>
            <textarea
                id="description"
                wire:model="description"
                rows="4"
                maxlength="2000"
                class="form-input"
                placeholder="e.g. Sell to open 2 TSLA puts at the $400 strike for $2.50 per share on June 10, 2026, expiring January 15, 2027, in Fidelity."
            ></textarea>
            <p class="form-help">
                Works for stock buys and sells; options sold or bought to open; buying back or selling to close (all or some contracts);
                expirations, assignments and rolls of recorded options; and dividends. Include the account, date, quantity and price. Your description is sent to the AI service (Anthropic) to be read;
                balances, positions and account numbers are not.
            </p>
            @error('description') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div class="sm:max-w-sm">
            <label for="account_id" class="form-label">Account, if not in the description</label>
            <select id="account_id" wire:model="account_id" class="form-input">
                <option value="">Choose…</option>
                @foreach ($this->accounts as $account)
                    <option value="{{ $account->id }}" wire:key="assistant-account-{{ $account->id }}">{{ $account->displayName() }}</option>
                @endforeach
            </select>
            @error('account_id') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="analyze">
                <x-icon name="sparkles" class="size-4" />
                Analyze Trade
            </button>
            <span class="muted" wire:loading wire:target="analyze">Reading the description…</span>
        </div>
    </form>

    @if ($failure)
        <x-alert level="critical" title="Could not analyze the trade">{{ $failure }}</x-alert>
    @endif

    @if ($proposed = $this->proposed())
        @if ($proposed->transactionCount > 1)
            <x-alert level="warning" title="One trade at a time">
                The description seems to contain {{ $proposed->transactionCount }} transactions. Enter them one at a time so each one can be checked.
            </x-alert>
            <div><button type="button" class="btn-secondary" wire:click="cancel">Start again</button></div>
        @elseif ($proposed->action === 'not_supported')
            <x-alert level="warning" title="Not supported">
                {{ $proposed->unsupportedReason ?? 'This kind of transaction' }} cannot be entered with the assistant.
                Use the normal pages instead, such as Cash ledger for deposits and transfers.
            </x-alert>
            <div><button type="button" class="btn-secondary" wire:click="cancel">Start again</button></div>
        @elseif (! $proposed->canBeReviewed())
            <x-alert level="warning" title="No trade found">
                The assistant could not find a stock or option trade in the description. Try describing it again, including what was bought or sold.
            </x-alert>
            <div><button type="button" class="btn-secondary" wire:click="cancel">Start again</button></div>
        @else
            @switch ($proposed->action)
                @case('buy_stock')
                @case('sell_stock')
                    <livewire:pages::trade-assistant.stock-review :proposal="$proposal" :proposal-key="$proposalKey" :key="$proposalKey" />
                    @break
                @case('sell_to_open')
                    <livewire:pages::trade-assistant.sell-review :proposal="$proposal" :proposal-key="$proposalKey" :key="$proposalKey" />
                    @break
                @case('buy_to_open')
                    <livewire:pages::trade-assistant.buy-review :proposal="$proposal" :proposal-key="$proposalKey" :key="$proposalKey" />
                    @break
                @case('buy_to_close')
                @case('sell_to_close')
                    <livewire:pages::trade-assistant.close-review :proposal="$proposal" :proposal-key="$proposalKey" :key="$proposalKey" />
                    @break
                @case('expire')
                @case('assign')
                    <livewire:pages::trade-assistant.lifecycle-review :proposal="$proposal" :proposal-key="$proposalKey" :key="$proposalKey" />
                    @break
                @case('roll')
                    <livewire:pages::trade-assistant.roll-review :proposal="$proposal" :proposal-key="$proposalKey" :key="$proposalKey" />
                    @break
                @case('dividend')
                    <livewire:pages::trade-assistant.dividend-review :proposal="$proposal" :proposal-key="$proposalKey" :key="$proposalKey" />
                    @break
            @endswitch
        @endif
    @endif
</div>
