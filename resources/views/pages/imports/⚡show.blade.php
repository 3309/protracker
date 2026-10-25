<?php

use App\Actions\Imports\CommitImportBatch;
use App\Enums\ImportRowStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Import preview')] class extends Component
{
    public ImportBatch $importBatch;

    public function toggleImportAnyway(int $rowId): void
    {
        abort_if($this->importBatch->isCommitted(), 403, 'This file has already been imported.');

        $row = $this->importBatch->rows()->findOrFail($rowId);

        abort_unless(in_array($row->status, [ImportRowStatus::Ambiguous, ImportRowStatus::PossibleDuplicate], true), 403);

        $row->forceFill(['import_anyway' => ! $row->import_anyway])->save();

        unset($this->rows, $this->readyCount);
    }

    public function commit(CommitImportBatch $commitImportBatch): void
    {
        abort_if($this->importBatch->isCommitted(), 403, 'This file has already been imported.');

        $result = $commitImportBatch->handle($this->importBatch);

        $this->importBatch->refresh();

        unset($this->rows, $this->readyCount);

        session()->now('message', "{$result['imported']} ".str('row')->plural($result['imported']).' imported.'
            .($result['failed'] > 0 ? " {$result['failed']} could not be imported and are waiting in the review queue." : ''));
    }

    public function discard(): void
    {
        abort_if($this->importBatch->isCommitted(), 403, 'An imported file cannot be discarded; its rows are part of the audit trail.');

        $this->importBatch->delete();

        session()->flash('message', 'Preview discarded. Nothing was imported.');

        $this->redirectRoute('imports.index', navigate: true);
    }

    /**
     * @return Collection<int, ImportRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->importBatch->rows()->with(['pairedRow', 'imported'])->orderBy('row_number')->get();
    }

    #[Computed]
    public function readyCount(): int
    {
        return $this->rows->filter(fn (ImportRow $row) => $row->status === ImportRowStatus::Recognized
            || (in_array($row->status, [ImportRowStatus::Ambiguous, ImportRowStatus::PossibleDuplicate], true) && $row->import_anyway))->count();
    }

    #[Computed]
    public function displayTimezone(): string
    {
        return Setting::current()->display_timezone;
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">{{ $importBatch->original_filename }}</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ $importBatch->account->displayName() }} · {{ $importBatch->format->label() }} · {{ $importBatch->row_count }} rows ·
                {{ $importBatch->status->label() }}
                @if ($importBatch->committed_at)
                    on {{ $importBatch->committed_at->timezone($this->displayTimezone)->format('M j, Y g:i A') }}
                @endif
            </p>
            <p class="text-xs text-zinc-500">File fingerprint (SHA-256): {{ $importBatch->file_sha256 }}</p>
        </div>
        <a href="{{ route('imports.index') }}" class="btn-secondary" wire:navigate>Back to imports</a>
    </div>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    <div class="card flex flex-wrap gap-x-6 gap-y-2 text-sm">
        @foreach ($importBatch->statusCounts() as $status => $count)
            <span wire:key="count-{{ $status }}"><strong class="tabular-nums">{{ $count }}</strong> {{ App\Enums\ImportRowStatus::from($status)->label() }}</span>
        @endforeach
    </div>

    @unless ($importBatch->isCommitted())
        <div class="card flex flex-col gap-3">
            <p class="text-sm">
                Rows marked <strong>Ready to import</strong> will be saved. Rows that need a decision are imported only if you tick
                <strong>Import anyway</strong>. Rows already imported before are skipped. Incomplete, invalid and unrecognized rows go
                to the review queue, where you can correct them.
            </p>
            <div class="flex flex-wrap gap-3">
                <button type="button" wire:click="commit" wire:confirm="Import {{ $this->readyCount }} rows into your records?" class="btn-primary" @disabled($this->readyCount === 0)>
                    Import {{ $this->readyCount }} {{ Str::plural('row', $this->readyCount) }}
                </button>
                <button type="button" wire:click="discard" wire:confirm="Discard this preview? Nothing has been imported." class="btn-secondary">Discard preview</button>
            </div>
        </div>
    @else
        <x-alert level="success" title="Imported">
            This file has been imported. Rows that still need a correction are in the
            <a href="{{ route('imports.review') }}" class="link" wire:navigate>review queue</a>.
        </x-alert>
    @endunless

    <div class="card overflow-x-auto p-0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Line</th>
                    <th>Date</th>
                    <th>Row</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->rows as $row)
                    <tr wire:key="row-{{ $row->id }}">
                        <td class="tabular-nums">{{ $row->row_number }}</td>
                        <td>{{ $row->value('date') }}</td>
                        <td>
                            @include('partials.import-row-summary', ['row' => $row])
                            @foreach ($row->messages as $message)
                                <span class="block text-xs text-amber-700 dark:text-amber-400">{{ $message }}</span>
                            @endforeach
                            @if ($row->skip_reason)
                                <span class="block text-xs text-zinc-500">{{ $row->skip_reason }}</span>
                            @endif
                        </td>
                        <td>
                            <x-badge :tone="match ($row->status) {
                                ImportRowStatus::Imported, ImportRowStatus::Recognized => 'green',
                                ImportRowStatus::Ambiguous, ImportRowStatus::PossibleDuplicate, ImportRowStatus::Incomplete => 'amber',
                                ImportRowStatus::Invalid, ImportRowStatus::Unrecognized, ImportRowStatus::Failed => 'red',
                                default => 'gray',
                            }">{{ $row->status->label() }}</x-badge>
                        </td>
                        <td class="text-right">
                            @if (! $importBatch->isCommitted() && in_array($row->status, [ImportRowStatus::Ambiguous, ImportRowStatus::PossibleDuplicate], true))
                                <label class="inline-flex items-center gap-2">
                                    <input type="checkbox" wire:click="toggleImportAnyway({{ $row->id }})" @checked($row->import_anyway)>
                                    Import anyway
                                </label>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
