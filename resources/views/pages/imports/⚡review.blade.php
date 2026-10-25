<?php

use App\Actions\Imports\AssessImportRows;
use App\Actions\Imports\CommitImportBatch;
use App\Enums\ImportAction;
use App\Enums\ImportRowStatus;
use App\Models\ImportRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Import review queue')] class extends Component
{
    /** The row being corrected, if any. */
    public ?int $editingRowId = null;

    public string $action = '';

    /** @var array<string, string> */
    public array $fields = [];

    public string $skipReason = '';

    public function edit(int $rowId): void
    {
        $row = $this->queuedRow($rowId);

        $this->resetErrorBag();
        $this->editingRowId = $row->id;
        $this->action = $row->action->value ?? '';
        $this->fields = collect(ImportRow::FIELDS)->reject(fn (string $field) => $field === 'action')
            ->mapWithKeys(fn (string $field) => [$field => $row->value($field)])->all();
        $this->skipReason = '';
    }

    public function cancel(): void
    {
        $this->reset('editingRowId', 'action', 'fields', 'skipReason');
    }

    /**
     * Save the corrected values and check the row again. Nothing is imported yet.
     */
    public function saveCorrection(AssessImportRows $assessImportRows): void
    {
        $this->validate(['action' => ['required', Rule::enum(ImportAction::class)]], ['action.required' => 'Choose what kind of row this is.']);

        $row = $this->queuedRow((int) $this->editingRowId);

        $row->action = ImportAction::from($this->action);
        $row->data = [...$row->data, ...array_map(fn ($value) => trim((string) $value), $this->fields), 'action' => $this->action];
        $row->import_anyway = false;
        $row->messages = [];

        $assessImportRows->row($row);

        unset($this->rows);

        session()->now('message', "Row {$row->row_number} checked again: {$row->status->label()}.");
    }

    /**
     * Import one corrected row (or one where "import anyway" was chosen).
     */
    public function import(int $rowId, bool $anyway = false): void
    {
        $row = $this->queuedRow($rowId);

        abort_unless($row->status === ImportRowStatus::Recognized || ($anyway && in_array($row->status, [ImportRowStatus::Ambiguous, ImportRowStatus::PossibleDuplicate], true)), 403);

        $imported = app(CommitImportBatch::class)->importRow($row->load('batch.account'));

        $this->cancel();
        unset($this->rows);

        session()->now('message', $imported ? "Row {$row->row_number} imported." : "Row {$row->row_number} could not be imported: ".implode(' ', $row->fresh()?->messages ?? []));
    }

    public function skip(int $rowId): void
    {
        $this->validate(['skipReason' => ['required', 'string', 'max:1000']], ['skipReason.required' => 'Please say why you are skipping this row. It is kept with the row.']);

        $row = $this->queuedRow($rowId);
        $row->forceFill(['status' => ImportRowStatus::Skipped, 'skip_reason' => $this->skipReason])->save();

        $this->cancel();
        unset($this->rows);

        session()->now('message', "Row {$row->row_number} skipped.");
    }

    /**
     * Rows of imported files that still need a correction or decision, plus
     * corrected rows that are ready to import.
     *
     * @return Collection<int, ImportRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        return ImportRow::query()
            ->with('batch.account')
            ->whereHas('batch', fn (Builder $batch) => $batch->where('status', 'committed'))
            ->whereIn('status', [...array_filter(ImportRowStatus::cases(), fn (ImportRowStatus $status) => $status->needsReview()), ImportRowStatus::Recognized, ImportRowStatus::PossibleDuplicate])
            ->orderBy('import_batch_id')
            ->orderBy('row_number')
            ->get();
    }

    private function queuedRow(int $rowId): ImportRow
    {
        $row = ImportRow::query()->with('batch.account')->findOrFail($rowId);

        abort_unless($row->batch->isCommitted() && ($row->status->needsReview() || in_array($row->status, [ImportRowStatus::Recognized, ImportRowStatus::PossibleDuplicate], true)), 403);

        return $row;
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">Import review queue</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Rows that were not imported, with the reason. Correct a row and check it again, then import it, or skip it with a
                reason. Rows that were already imported can never be imported twice.
            </p>
        </div>
        <a href="{{ route('imports.index') }}" class="btn-secondary" wire:navigate>Back to imports</a>
    </div>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    @if ($this->rows->isEmpty())
        <x-empty-state>Nothing is waiting for review.</x-empty-state>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($this->rows as $row)
                <div class="card flex flex-col gap-3" wire:key="review-{{ $row->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="text-sm">
                            <div class="text-xs text-zinc-500">{{ $row->batch->original_filename }} · line {{ $row->row_number }} · {{ $row->batch->account->displayName() }}</div>
                            <div>{{ $row->value('date') }} · @include('partials.import-row-summary', ['row' => $row])</div>
                            <div class="font-medium">{{ $row->status->label() }}</div>
                            @foreach ($row->messages as $message)
                                <div class="text-amber-700 dark:text-amber-400">{{ $message }}</div>
                            @endforeach
                            <details class="mt-1 text-xs text-zinc-500">
                                <summary>Original values in the file</summary>
                                <ul>
                                    @foreach ($row->raw as $column => $value)
                                        <li>{{ $column }}: {{ $value }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if ($row->status === ImportRowStatus::Recognized)
                                <button type="button" wire:click="import({{ $row->id }})" class="btn-primary">Import this row</button>
                            @elseif (in_array($row->status, [ImportRowStatus::Ambiguous, ImportRowStatus::PossibleDuplicate], true))
                                <button type="button" wire:click="import({{ $row->id }}, true)" wire:confirm="Import this row anyway?" class="btn-secondary">Import anyway</button>
                            @endif
                            <button type="button" wire:click="edit({{ $row->id }})" class="btn-secondary">Correct or skip</button>
                        </div>
                    </div>

                    @if ($editingRowId === $row->id)
                        <form wire:submit="saveCorrection" class="flex flex-col gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                <div>
                                    <label for="review_action" class="form-label">Action</label>
                                    <select id="review_action" wire:model="action" class="form-input">
                                        <option value="">Choose…</option>
                                        @foreach (ImportAction::cases() as $importAction)
                                            <option value="{{ $importAction->value }}">{{ $importAction->label() }}</option>
                                        @endforeach
                                    </select>
                                    @error('action') <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                @foreach ($fields as $field => $value)
                                    <div wire:key="field-{{ $field }}">
                                        <label for="review_{{ $field }}" class="form-label">{{ str_replace('_', ' ', $field) }}</label>
                                        <input id="review_{{ $field }}" type="text" wire:model="fields.{{ $field }}" class="form-input">
                                    </div>
                                @endforeach
                            </div>
                            <p class="form-help">Dates as YYYY-MM-DD. Quantity is shares for stock and contracts for options. A blank fee uses the account default.</p>

                            <div class="flex flex-wrap items-end gap-3">
                                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save and check again</button>
                                <button type="button" wire:click="cancel" class="btn-secondary">Cancel</button>
                                <div class="grow"></div>
                                <div>
                                    <label for="skip_reason" class="form-label">Reason to skip</label>
                                    <input id="skip_reason" type="text" wire:model="skipReason" class="form-input" placeholder="e.g. Not a transaction">
                                    @error('skipReason') <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                <button type="button" wire:click="skip({{ $row->id }})" class="btn-danger">Skip row</button>
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
