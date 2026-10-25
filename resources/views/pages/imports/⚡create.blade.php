<?php

use App\Actions\Imports\PreviewImport;
use App\Enums\ImportFormatType;
use App\Exceptions\ImportFileException;
use App\Models\Account;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Upload a file')] class extends Component
{
    use WithFileUploads;

    public string $account_id = '';

    public string $format = 'template';

    /** @var UploadedFile|null */
    public $file = null;

    public function mount(): void
    {
        if ($this->accounts->count() === 1) {
            $this->account_id = (string) $this->accounts->first()?->id;
        }
    }

    /**
     * Read the file into a preview. The uploaded file is deleted as soon as it
     * has been read; only its name, a fingerprint and its rows are kept.
     */
    public function preview(PreviewImport $previewImport): void
    {
        $this->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->whereNull('archived_at')],
            'format' => ['required', Rule::in(array_map(fn (ImportFormatType $type) => $type->value, array_filter(ImportFormatType::cases(), fn (ImportFormatType $type) => $type->isSupported())))],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'format.in' => 'This format is not available yet. It will be built from a real sample export.',
            'file.mimes' => 'Upload a CSV file.',
            'file.max' => 'The file must be smaller than 2 MB.',
        ], [
            'account_id' => 'account',
        ]);

        $file = $this->file;
        $contents = (string) file_get_contents($file->getRealPath());
        $filename = $file->getClientOriginalName();

        $this->deleteUpload();

        try {
            $batch = $previewImport->handle(Account::findOrFail((int) $this->account_id), ImportFormatType::from($this->format), $contents, $filename, auth()->id());
        } catch (ImportFileException $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        }

        $this->redirectRoute('imports.show', $batch, navigate: true);
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()->active()->orderBy('nickname')->get();
    }

    private function deleteUpload(): void
    {
        if ($this->file !== null && method_exists($this->file, 'delete')) {
            $this->file->delete();
        }

        $this->file = null;
    }
};
?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-2xl font-semibold">Upload a file</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Nothing is saved to your records yet: you will see a preview of every row first. Statements are sensitive, so the file
            itself is deleted right after it is read.
        </p>
    </div>

    <form wire:submit="preview" class="card flex flex-col gap-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="account_id" class="form-label">Account</label>
                <select id="account_id" wire:model="account_id" class="form-input">
                    <option value="">Choose…</option>
                    @foreach ($this->accounts as $account)
                        <option value="{{ $account->id }}" wire:key="import-account-{{ $account->id }}">{{ $account->displayName() }}</option>
                    @endforeach
                </select>
                @error('account_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="format" class="form-label">Format</label>
                <select id="format" wire:model="format" class="form-input">
                    @foreach (ImportFormatType::cases() as $type)
                        <option value="{{ $type->value }}" @disabled(! $type->isSupported())>
                            {{ $type->label() }}{{ $type->isSupported() ? '' : ' (needs a sample file — not available yet)' }}
                        </option>
                    @endforeach
                </select>
                <p class="form-help">
                    <a href="{{ route('imports.template') }}" class="underline underline-offset-4">Download the Protracker template</a>
                    with one example of each kind of row.
                </p>
                @error('format') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label for="file" class="form-label">CSV file</label>
                <input id="file" type="file" accept=".csv,text/csv" wire:model="file" class="form-input">
                <p class="form-help">Up to 2 MB and 5,000 rows.</p>
                @error('file') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Preview</button>
            <a href="{{ route('imports.index') }}" class="btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>

    <section class="card flex flex-col gap-2 text-sm">
        <h2 class="text-lg font-semibold">Template columns</h2>
        <p><strong>Required in the header:</strong> date, action, symbol, quantity, price, fees, amount, option_type, strike, expiration.</p>
        <p><strong>Optional:</strong> multiplier (default 100), settlement_date, ex_date, transaction_id, description.</p>
        <p>
            <strong>Actions:</strong> BUY, SELL, SELL_TO_OPEN, BUY_TO_CLOSE, EXPIRED, ASSIGNED, DIVIDEND, DEPOSIT, WITHDRAWAL,
            INTEREST, OTHER_CREDIT, OTHER_CHARGE. Anything else is flagged, never guessed.
        </p>
        <p>
            Quantity is shares for stock and contracts for options. Price is per share. A blank fee uses the account default.
            For a dividend, the date is the pay date and ex_date is needed.
        </p>
    </section>
</div>
