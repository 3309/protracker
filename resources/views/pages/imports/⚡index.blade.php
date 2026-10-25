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
            <h1 class="text-2xl font-semibold">Import</h1>
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
        <div class="card">No files imported yet.</div>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Uploaded</th>
                        <th class="px-4 py-3 font-medium">File</th>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Format</th>
                        <th class="px-4 py-3 text-right font-medium">Rows</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->batches as $batch)
                        <tr wire:key="batch-{{ $batch->id }}">
                            <td class="px-4 py-2">
                                {{ $batch->created_at?->timezone($this->displayTimezone)->format('M j, Y g:i A') }}
                                @if ($batch->user) <span class="block text-xs text-zinc-500">by {{ $batch->user->name }}</span> @endif
                            </td>
                            <td class="px-4 py-2">{{ $batch->original_filename }}</td>
                            <td class="px-4 py-2">{{ $batch->account->displayName() }}</td>
                            <td class="px-4 py-2">{{ $batch->format->label() }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $batch->row_count }}</td>
                            <td class="px-4 py-2">{{ $batch->status->label() }}</td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('imports.show', $batch) }}" class="underline underline-offset-4" wire:navigate>Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
