<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonProgress extends Model
{
    use HasFactory;

    protected $table = 'lesson_progress';

    // Computed columns (watched_ranges, *_seconds, completion_percent) are set
    // explicitly by services (SegmentMerger), not mass-assigned from client input.
    protected $fillable = [
        'enrollment_id',
        'lesson_key',
        'lesson_title',
        'lesson_url',
        'content_type',
        'video_duration',
        'lesson_group',
    ];

    protected function casts(): array
    {
        return [
            'watched_ranges' => 'array',          // [[start, end], ...] in seconds
            'video_duration' => 'float',
            'watched_seconds' => 'float',
            'active_seconds' => 'integer',
            'max_scroll_percent' => 'integer',
            'completion_percent' => 'decimal:2',
            'last_heartbeat_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function integrityLogs(): HasMany
    {
        return $this->hasMany(IntegrityLog::class);
    }
}
