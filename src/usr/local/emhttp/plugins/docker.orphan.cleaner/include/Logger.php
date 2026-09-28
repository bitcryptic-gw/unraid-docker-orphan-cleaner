<?php
declare(strict_types=1);

/**
 * Writes to syslog using PHP's native syslog functions. No shell command is
 * used. Newlines are stripped so one action produces one line and the message
 * is capped so a single line cannot grow without bound. If syslog is not
 * available the call fails silently.
 */
final class Logger
{
    private const TAG = 'docker.orphan.cleaner';
    private const MAX_LENGTH = 2000;

    public static function log(string $message): void
    {
        $message = str_replace(["\r", "\n", "\0"], ' ', $message);
        if (strlen($message) > self::MAX_LENGTH) {
            $message = substr($message, 0, self::MAX_LENGTH);
        }
        if (!function_exists('openlog') || !function_exists('syslog')) {
            return;
        }
        @openlog(self::TAG, LOG_PID, LOG_USER);
        @syslog(LOG_INFO, $message);
        @closelog();
    }
}
