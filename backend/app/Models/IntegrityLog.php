<?php

namespace App\Models;

use App\Enums\IntegrityEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrityLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null; // append-only audit trail

    protected $fillable = [
        'enrollment_id', 'lesson_progress_id', 'event_type',
        'points_deducted', 'meta', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => IntegrityEventType::class,
            'points_deducted' => 'integer',
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function lessonProgress(): BelongsTo
    {
        return $this->belongsTo(LessonProgress::class);
    }
}
