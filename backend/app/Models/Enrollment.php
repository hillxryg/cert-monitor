<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    use HasFactory;

    // Scores are written only by server-side services, never from request input.
    protected $fillable = ['campaign_id', 'student_id', 'joined_at'];

    protected function casts(): array
    {
        return [
            'integrity_score' => 'integer',
            'completion_percent' => 'decimal:2',
            'joined_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function integrityLogs(): HasMany
    {
        return $this->hasMany(IntegrityLog::class);
    }
}
