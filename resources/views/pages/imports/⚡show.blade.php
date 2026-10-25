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
            <h1 class="text-2xl font-semibold">{{ $importBatch->original_filename }}</h1>
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
        <div class="status-message">{{ session('message') }}</div>
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
        <div class="card text-sm">
            This file has been imported. Rows that still need a correction are in the
            <a href="{{ route('imports.review') }}" class="underline underline-offset-4" wire:navigate>review queue</a>.
        </div>
    @endunless

    <div class="card overflow-x-auto p-0">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">Line</th>
                    <th class="px-4 py-3 font-medium">Date</th>
                    <th class="px-4 py-3 font-medium">Row</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->rows as $row)
                    <tr wire:key="row-{{ $row->id }}">
                        <td class="px-4 py-2 tabular-nums">{{ $row->row_number }}</td>
                        <td class="px-4 py-2">{{ $row->value('date') }}</td>
                        <td class="px-4 py-2">
                            @include('partials.import-row-summary', ['row' => $row])
                            @foreach ($row->messages as $message)
                                <span class="block text-xs text-amber-700 dark:text-amber-400">{{ $message }}</span>
                            @endforeach
                            @if ($row->skip_reason)
                                <span class="block text-xs text-zinc-500">{{ $row->skip_reason }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $row->status->label() }}</td>
                        <td class="px-4 py-2 text-right">
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
