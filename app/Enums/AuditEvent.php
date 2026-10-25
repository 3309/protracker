<?php

namespace App\Enums;

enum AuditEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Archived = 'archived';
    case Restored = 'restored';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Archived => 'Archived',
            self::Restored => 'Restored',
            self::Voided => 'Voided',
        };
    }
}
