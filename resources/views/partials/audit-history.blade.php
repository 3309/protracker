{{-- Expects $entries (a collection of AuditEntry with users loaded) and $timezone. --}}
<section class="card flex flex-col gap-4">
    <h2 class="text-lg font-semibold">{{ $heading ?? 'Change history' }}</h2>

    @if ($entries->isEmpty())
        <p class="text-sm text-zinc-500 dark:text-zinc-400">No changes recorded yet.</p>
    @else
        <ol class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($entries as $entry)
                <li wire:key="audit-{{ $entry->id }}" class="flex flex-col gap-1 py-3 text-sm">
                    <div>
                        <span class="font-medium">{{ $entry->event->label() }}</span>
                        <span class="text-zinc-500 dark:text-zinc-400">
                            {{ $entry->created_at->timezone($timezone)->format('M j, Y g:i A T') }}
                            @if ($entry->user) by {{ $entry->user->name }} @endif
                        </span>
                    </div>

                    @if ($entry->reason)
                        <div>Reason: {{ $entry->reason }}</div>
                    @endif

                    @if ($entry->event !== App\Enums\AuditEvent::Created)
                        <ul class="text-zinc-600 dark:text-zinc-400">
                            @foreach ($entry->readableChanges() as $change)
                                <li>{{ $change['field'] }}: {{ $change['old'] }} → {{ $change['new'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</section>
