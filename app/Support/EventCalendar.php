<?php

namespace App\Support;

use App\Models\CompanyEvent;
use Carbon\Carbon;

/** One event as an iCalendar file (RFC 5545), for "Add to calendar" (#4648). */
final class EventCalendar
{
    /** Events have no end time; calendars get a two-hour slot. */
    public const DURATION_HOURS = 2;

    public static function ics(CompanyEvent $event): string
    {
        $event->loadMissing('company');
        $url = route('events.show', $event);
        $host = parse_url($url, PHP_URL_HOST) ?: 'hub';

        $from = $event->start_city.($event->start_company ? ' ('.$event->start_company.')' : '');
        $to = $event->dest_city.($event->dest_company ? ' ('.$event->dest_company.')' : '');
        $description = array_filter([
            $from.' → '.$to,
            $event->server ? 'Server: '.$event->server : null,
            CompanyEvent::GAMES[$event->game] ?? null,
            $event->route_notes,
            $url,
        ]);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//ProjectTrucks Hub//Events//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:event-'.$event->id.'@'.$host,
            'DTSTAMP:'.self::time(now()),
            'DTSTART:'.self::time($event->starts_at),
            'DTEND:'.self::time($event->starts_at->copy()->addHours(self::DURATION_HOURS)),
            'SUMMARY:'.self::text('['.$event->company->tag.'] '.$event->title),
            'LOCATION:'.self::text($from),
            'DESCRIPTION:'.self::text(implode("\n\n", $description)),
            'URL:'.$url,
            'STATUS:'.($event->isCancelled() ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    private static function time(\DateTimeInterface $time): string
    {
        return Carbon::instance($time)->utc()->format('Ymd\THis\Z');
    }

    /** TEXT value escaping: backslash, semicolon, comma and newlines. */
    private static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }

    /** Lines longer than 75 octets continue on the next line after a space. */
    private static function fold(string $line): string
    {
        $out = [];
        $limit = 75;
        while (strlen($line) > $limit) {
            $chunk = mb_strcut($line, 0, $limit, 'UTF-8');
            $out[] = $chunk;
            $line = substr($line, strlen($chunk));
            $limit = 74;
        }
        $out[] = $line;

        return implode("\r\n ", $out);
    }
}
