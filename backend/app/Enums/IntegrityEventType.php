<?php

namespace App\Enums;

use App\Support\Timecode;

enum IntegrityEventType: string
{
    case SeekSkip = 'seek_skip';              // jumped forward past unwatched content
    case SpeedViolation = 'speed_violation';  // playbackRate above allowed
    case MutedPlayback = 'muted_playback';    // video playing muted where campaign disallows it
    case TabHidden = 'tab_hidden';            // tab/window lost focus while playing
    case IdleTimeout = 'idle_timeout';        // no interaction for too long
    case HeartbeatGap = 'heartbeat_gap';      // missing 15s heartbeats
    case ScrollTooFast = 'scroll_too_fast';   // reading lesson skimmed
    case MultiSession = 'multi_session';      // concurrent sessions detected

    public function label(): string
    {
        return match ($this) {
            self::SeekSkip => 'Timeline skip',
            self::SpeedViolation => 'Playback too fast',
            self::MutedPlayback => 'Muted playback',
            self::TabHidden => 'Tab unfocused',
            self::IdleTimeout => 'Idle timeout',
            self::HeartbeatGap => 'Heartbeat gap',
            self::ScrollTooFast => 'Scrolled too fast',
            self::MultiSession => 'Multiple sessions',
        };
    }

    /**
     * Human-readable description of an event's meta payload.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function describe(?array $meta): string
    {
        $meta ??= [];

        return match ($this) {
            self::SeekSkip => ($meta['reason'] ?? null) === 'oversized_segment'
                ? sprintf(
                    'Reported %s of video in a window that only allows %s.',
                    Timecode::clock($meta['claimed_seconds'] ?? 0),
                    Timecode::clock($meta['allowed_seconds'] ?? 0),
                )
                : sprintf(
                    'Jumped forward from %s to %s.',
                    Timecode::clock($meta['from'] ?? 0),
                    Timecode::clock($meta['to'] ?? 0),
                ),
            self::SpeedViolation => sprintf('Played at %sx (limit %sx).', $meta['playback_rate'] ?? '?', $meta['max_allowed'] ?? '?'),
            self::MutedPlayback => 'Video was playing while muted.',
            self::TabHidden => 'The lesson tab was hidden or lost focus.',
            self::IdleTimeout => 'No interaction for too long.',
            self::HeartbeatGap => sprintf('No heartbeat received for %s seconds.', $meta['gap_seconds'] ?? '?'),
            self::ScrollTooFast => sprintf('Scrolled from %s%% to %s%% in a single interval.', $meta['from'] ?? '?', $meta['to'] ?? '?'),
            self::MultiSession => 'Concurrent sessions detected.',
        };
    }
}
