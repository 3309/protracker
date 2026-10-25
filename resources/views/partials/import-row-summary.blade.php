{{-- Expects $row (ImportRow). A short description of what the row says. --}}
@php
    $parts = array_filter([
        $row->value('symbol'),
        $row->value('option_type') !== '' ? ucfirst($row->value('option_type')).' '.$row->value('strike').' exp '.$row->value('expiration') : null,
        $row->value('quantity') !== '' ? '× '.$row->value('quantity') : null,
        $row->value('price') !== '' ? '@ '.$row->value('price') : null,
        $row->value('amount') !== '' ? 'amount '.$row->value('amount') : null,
        $row->value('fees') !== '' ? 'fees '.$row->value('fees') : null,
    ]);
@endphp
<span class="font-medium">{{ $row->action?->label() ?? ($row->value('action') !== '' ? $row->value('action') : 'Unknown') }}</span>
<span class="text-zinc-600 dark:text-zinc-400">{{ implode(' ', $parts) }}</span>
