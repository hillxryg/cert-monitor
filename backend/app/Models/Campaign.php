<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'instructor_id', 'title', 'description', 'platform_name', 'join_code',
        'required_completion_percent', 'min_integrity_score', 'status',
        'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'required_completion_percent' => 'integer',
            'min_integrity_score' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign) {
            $campaign->join_code ??= static::generateJoinCode();
        });
    }

    public static function generateJoinCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('join_code', $code)->exists());

        return $code;
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'enrollments', 'campaign_id', 'student_id')
            ->withPivot(['id', 'integrity_score', 'completion_percent', 'joined_at', 'completed_at'])
            ->withTimestamps();
    }

    public function lessonProgress(): HasManyThrough
    {
        return $this->hasManyThrough(LessonProgress::class, Enrollment::class);
    }

    public function integrityLogs(): HasManyThrough
    {
        return $this->hasManyThrough(IntegrityLog::class, Enrollment::class);
    }
}
