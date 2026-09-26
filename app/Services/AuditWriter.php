<?php

namespace App\Services;

use App\Models\AuditEvent;

class AuditWriter
{
    /** @param array<string, int|string|bool|null> $metadata */
    public function record(string $event, ?int $actorUserId, string $subjectType, int $subjectId, array $metadata = []): void
    {
        AuditEvent::create([
            'event' => $event,
            'actor_user_id' => $actorUserId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata,
        ]);
    }
}
