<?php

namespace App\Enums;

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
}
