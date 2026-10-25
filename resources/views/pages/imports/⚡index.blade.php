<?php

use App\Enums\ImportRowStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Import')] class extends Component
{
    /**
     * @return Collection<int, ImportBatch>
     */
    #[Computed]
    public function batches(): Collection
    {
        return ImportBatch::query()->with(['account', 'user'])->latest('id')->get();
    }

    #[Computed]
    public function reviewCount(): int
    {
        return ImportRow::query()
            ->whereIn('status', array_filter(ImportRowStatus::cases(), fn (ImportRowStatus $status) => $status->needsReview()))
            ->whereHas('batch', fn (Builder $batch) => $batch->where('status', 'committed'))
            ->count();
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
            <h1 class="page-title">Import</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Upload a CSV file, check every row in a preview, then confirm. Nothing is saved until you confirm, and a file
                imported twice adds nothing the second time.
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('imports.review') }}" class="btn-secondary" wire:navigate>Review queue ({{ $this->reviewCount }})</a>
            <a href="{{ route('imports.create') }}" class="btn-primary" wire:navigate>Upload a file</a>
        </div>
    </div>

    @if ($this->batches->isEmpty())
        <x-empty-state>No files imported yet.</x-empty-state>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Uploaded</th>
                        <th>File</th>
                        <th>Account</th>
                        <th>Format</th>
                        <th class="text-right">Rows</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->batches as $batch)
                        <tr wire:key="batch-{{ $batch->id }}">
                            <td>
                                {{ $batch->created_at?->timezone($this->displayTimezone)->format('M j, Y g:i A') }}
                                @if ($batch->user) <span class="block text-xs text-zinc-500">by {{ $batch->user->name }}</span> @endif
                            </td>
                            <td>{{ $batch->original_filename }}</td>
                            <td>{{ $batch->account->displayName() }}</td>
                            <td>{{ $batch->format->label() }}</td>
                            <td class="num">{{ $batch->row_count }}</td>
                            <td><x-badge :tone="$batch->status === App\Enums\ImportBatchStatus::Committed ? 'green' : 'amber'">{{ $batch->status->label() }}</x-badge></td>
                            <td class="text-right">
                                <a href="{{ route('imports.show', $batch) }}" class="link" wire:navigate>Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
