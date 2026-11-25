<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'instructor_id',
        'title',
        'description',
        'platform_name',
        'join_code',
        'required_completion_percent',
        'min_integrity_score',
        'status',
        'target_url_pattern',
        'max_playback_speed',
        'allow_muted_playback',
        'max_skip_seconds',
        'expected_lessons',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'required_completion_percent' => 'integer',
            'min_integrity_score' => 'integer',
            'max_playback_speed' => 'float',
            'allow_muted_playback' => 'boolean',
            'max_skip_seconds' => 'integer',
            'expected_lessons' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign) {
            $campaign->join_code ??= static::generateJoinCode();
        });
    }

    /**
     * Campaigns currently accepting progress (SQL counterpart of isOpen(); keep them in sync).
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'active')
            ->where(fn(Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn(Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isOpen(): bool
    {
        return $this->status === 'active'
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public static function generateJoinCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('join_code', $code)->exists());

        return $code;
    }

    /**
     * Permanently delete this campaign and everything beneath it (integrity logs, lesson
     * progress, enrollments) in one transaction. The foreign keys already cascade, but doing
     * it explicitly keeps this correct even where the DB doesn't enforce them (e.g. SQLite
     * with foreign_key_constraints off).
     */
    public function purge(): void
    {
        DB::transaction(function () {
            $enrollmentIds = Enrollment::query()->where('campaign_id', $this->id)->select('id');

            IntegrityLog::query()->whereIn('enrollment_id', $enrollmentIds)->delete();
            LessonProgress::query()->whereIn('enrollment_id', $enrollmentIds)->delete();
            Enrollment::query()->where('campaign_id', $this->id)->delete();

            $this->delete();
        });
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
