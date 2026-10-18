<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What a save from the post editor asks for, turned into the status and
 * publication time the post should end up with (1.2.60).
 *
 * The editor sends the status select plus the publish panel's choice:
 *
 *   publish_mode   "now" | "schedule" | "keep" | "date"  (absent from older forms and apps)
 *   publish_date   Y-m-d               in the site timezone
 *   publish_time   H:i                 in the site timezone
 *
 * This class owns the rules for reading that, so the form controller has no
 * publishing logic of its own and every rule is testable without a database:
 *
 *  - Going out (published / scheduled / private) needs the type's publish
 *    capability. Without it the post is submitted for review, as before.
 *  - "Schedule" needs a valid date and time that is still in the future (and
 *    before 2038-01-19, the end of a MySQL TIMESTAMP). It gives `scheduled`
 *    and the UTC instant.
 *  - "Now" on a post that is not yet live gives `published` at the server's
 *    current time, never a time the browser sent.
 *  - A post that is already live stays exactly as it is (status and date)
 *    unless the editor explicitly chose "Change date" (1.2.62, or "Schedule"
 *    from 1.2.60 forms): a date that has passed re-dates the live post; a
 *    future one takes it off the site until then. The panel asks first.
 *  - A refused request never changes the status or the date: the caller gets
 *    an error and keeps what the post already had, so no save loses work and
 *    no mistake publishes anything.
 *
 * Times are compared as UTC instants. The site timezone is applied only to
 * read what the person typed; daylight-saving gaps resolve the way PHP does
 * (a wall time that does not exist moves forward by the gap).
 */
final class PostPublishing
{
    /** Statuses the editor may send. */
    public const STATUSES = ['draft', 'pending', 'published', 'scheduled', 'private'];

    /** Statuses that need the publish capability. */
    public const OUT = ['published', 'scheduled', 'private'];

    /** The last instant a TIMESTAMP column can hold. */
    public const MAX_UTC = '2038-01-19 03:14:07';

    /**
     * @param array|null $existing  the stored post (status, published_at) or null for a new one
     * @param array      $input     status, publish_mode, publish_date, publish_time as sent
     * @param bool       $canPublish whether the user holds the type's publish capability
     * @param \DateTimeZone $zone   the site timezone
     * @param int        $now       the current Unix time (the server's)
     *
     * @return array{
     *   status: string,
     *   published_at?: ?string,
     *   error?: string,
     *   notice?: string,
     *   scheduled_for?: \DateTimeImmutable
     * } `published_at` is present only when it must change (UTC Y-m-d H:i:s or null).
     */
    public static function resolve(?array $existing, array $input, bool $canPublish, \DateTimeZone $zone, int $now): array
    {
        $current = $existing !== null ? (string) ($existing['status'] ?? 'draft') : null;
        $requested = (string) ($input['status'] ?? ($current ?? 'draft'));
        if (!in_array($requested, self::STATUSES, true)) {
            return self::keep($current, 'That status is not one this editor offers.');
        }
        $mode = isset($input['publish_mode']) ? (string) $input['publish_mode'] : null;
        if ($mode !== null && !in_array($mode, ['now', 'schedule', 'keep', 'date'], true)) $mode = null;

        // -- Not going out ----------------------------------------------------
        if (!in_array($requested, self::OUT, true)) {
            $out = ['status' => $requested];
            // A scheduled post taken back to draft forgets its date, so it is
            // not listed (or later published) with a time nobody chose for it.
            if ($current === 'scheduled') $out['published_at'] = null;
            return $out;
        }

        // -- Going out without the right to ----------------------------------
        if (!$canPublish) {
            return [
                'status' => 'pending',
                'notice' => 'Submitted for review — your role cannot publish directly. An editor will choose when it goes live.',
            ] + ($current === 'scheduled' ? ['published_at' => null] : []);
        }

        if ($requested === 'private') {
            return ['status' => 'private'] + ($current === 'scheduled' ? ['published_at' => null] : []);
        }

        // -- published / scheduled -------------------------------------------
        // "keep": leave a live post's date, or a schedule, as it is.
        if ($mode === 'keep') {
            if ($current === 'published') return ['status' => 'published'];
            if ($current === 'scheduled') { $mode = null; $requested = 'scheduled'; }
            else $mode = 'now';
        }
        // "date" (1.2.62): set the publication date of a live post. A time
        // that has passed re-dates it and it stays live; a future one
        // schedules it. On a post that is not live it means "schedule".
        if ($mode === 'date' && $current !== 'published') $mode = 'schedule';
        if ($mode === 'date') {
            $at = self::parseLocal(trim((string) ($input['publish_date'] ?? '')), trim((string) ($input['publish_time'] ?? '')), $zone);
            if ($at === null) return self::keep($current, 'Choose a valid date and time.');
            if ($at->getTimestamp() > (int) strtotime(self::MAX_UTC . ' UTC')) {
                return self::keep($current, 'Choose a date before 19 January 2038.');
            }
            $utc = $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            if ($at->getTimestamp() <= $now) return ['status' => 'published', 'published_at' => $utc];
            return ['status' => 'scheduled', 'published_at' => $utc, 'scheduled_for' => $at];
        }

        // An older form or an app that only sends status="scheduled" means
        // "keep the schedule"; status="published" alone means "now".
        $schedule = $mode === 'schedule' || ($mode === null && $requested === 'scheduled');

        if (!$schedule) {
            if ($current === 'published') {
                return ['status' => 'published'];        // an Update: nothing about timing changes
            }
            $stored = self::utc($existing['published_at'] ?? null);
            // A date already in the past (a post that was live before) is
            // kept; none, or a future one left by a schedule, becomes now.
            if ($stored !== null && $stored->getTimestamp() <= $now && $current !== 'scheduled') {
                return ['status' => 'published'];
            }
            return ['status' => 'published', 'published_at' => gmdate('Y-m-d H:i:s', $now)];
        }

        $date = trim((string) ($input['publish_date'] ?? ''));
        $time = trim((string) ($input['publish_time'] ?? ''));
        if ($date === '' && $time === '' && $mode === null && $current === 'scheduled') {
            // status="scheduled" with no date from a form that has no panel:
            // keep the stored schedule if it is still ahead.
            $stored = self::utc($existing['published_at'] ?? null);
            if ($stored !== null && $stored->getTimestamp() > $now) return ['status' => 'scheduled'];
            return self::keep($current, 'Choose a date and time to schedule this for.');
        }

        $at = self::parseLocal($date, $time, $zone);
        if ($at === null) {
            return self::keep($current, 'Choose a valid date and time to schedule this for.');
        }
        if ($at->getTimestamp() <= $now) {
            return self::keep($current, 'The scheduled time has to be in the future. Pick a later time, or choose Publish immediately.');
        }
        if ($at->getTimestamp() > (int) strtotime(self::MAX_UTC . ' UTC')) {
            return self::keep($current, 'Choose a date before 19 January 2038.');
        }

        return [
            'status' => 'scheduled',
            'published_at' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'scheduled_for' => $at,
        ];
    }

    /**
     * What the shared Publish control (admin/assets/js/post-fields.js) needs to
     * show a post's publishing state, in the site timezone (1.2.62). The block
     * editor's sidebar and Quick Edit both build it here, so the two always
     * agree on what "now", the timezone and the stored date are.
     *
     * @param array|null $post        the stored post, or null for a new one
     * @param string     $typeLabel   "post", "page" … for the sentences
     */
    public static function panel(?array $post, bool $canPublish, string $typeLabel = 'post'): array
    {
        $status = $post !== null ? (string) ($post['status'] ?? 'draft') : 'draft';
        $stored = $post !== null && !empty($post['published_at']) ? (string) $post['published_at'] : '';
        $local = $stored !== '' ? \App\Core\Time::local($stored) : null;
        $now = \App\Core\Time::local(\App\Core\Time::now());
        $when = $local ? $local->format(\App\Core\Time::dateFormat()) . ' at ' . $local->format(\App\Core\Time::timeFormat()) : '';
        $live = in_array($status, ['published', 'scheduled'], true) && $local !== null;
        return [
            'status'       => $status,
            'can_publish'  => $canPublish,
            'tz'           => \App\Core\Time::timezone(),
            'tz_label'     => \App\Core\Time::abbreviation() . ' · UTC' . \App\Core\Time::offset(),
            'now'          => time(),
            'published_at' => $stored !== '' ? \App\Core\Time::iso($stored) : '',
            // The date fields start from the stored time for a live or
            // scheduled post (so "Change date" edits it), empty otherwise.
            'date'         => $live ? $local->format('Y-m-d') : '',
            'time'         => $live ? $local->format('H:i') : '',
            'mode'         => match ($status) { 'scheduled' => 'schedule', 'published' => 'keep', default => 'now' },
            'min_date'     => $now ? $now->format('Y-m-d') : '',
            'max_date'     => '2038-01-18',
            'type_label'   => $typeLabel,
            'summary'      => match ($status) {
                'published' => 'Published' . ($when !== '' ? ' ' . $when : ''),
                'scheduled' => 'Scheduled for ' . $when,
                'private'   => 'Private — only editors can see it',
                'pending'   => 'Pending review',
                default     => $post !== null ? 'Draft — not published yet' : 'New — not published yet',
            },
        ];
    }

    /**
     * "2026-10-20" + "09:30" in the site timezone, or null when either part is
     * malformed or not a real date. Seconds are always zero.
     */
    public static function parseLocal(string $date, string $time, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d)) return null;
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $time, $t)) return null;
        if (!checkdate((int) $d[2], (int) $d[3], (int) $d[1])) return null;
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$t[1]}:{$t[2]}", $zone);
        return $dt instanceof \DateTimeImmutable ? $dt : null;
    }

    /** A stored UTC "Y-m-d H:i:s" as an instant, or null. */
    private static function utc(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) return null;
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Refuse: keep the status the post has (a new post stays a draft). */
    private static function keep(?string $current, string $error): array
    {
        return ['status' => $current ?? 'draft', 'error' => $error];
    }
}
