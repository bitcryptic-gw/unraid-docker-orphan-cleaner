<?php
declare(strict_types=1);

/**
 * Creates an Unraid notification without invoking the notify helper through a
 * shell. The file format and the unread/archive layout are the same as
 * /usr/local/emhttp/webGui/scripts/notify, so the webGUI shows the
 * notification the same way it shows one created by the helper.
 *
 * Scope note: this writes the browser notification (unread + archive) only.
 * The helper's email and notification-agent delivery paths are not reproduced.
 */
final class Notify
{
    public const DEFAULT_PATH = '/tmp/notifications';

    public static function send(
        string $event,
        string $subject,
        string $description,
        string $importance = 'normal',
        string $message = '',
        string $link = ''
    ): bool {
        $conf = self::config();
        $unreadDir = $conf['path'] . '/unread';
        $archiveDir = $conf['path'] . '/archive';
        if (!is_dir($unreadDir)) {
            @mkdir($unreadDir, 0755, true);
        }
        if (!is_dir($archiveDir)) {
            @mkdir($archiveDir, 0755, true);
        }

        $timestamp = time();
        $file = self::safeFilename($event . '-' . $timestamp . '.notify');
        $subject = self::cleanSubject($subject);

        $archive = [
            'timestamp'   => $timestamp,
            'event'       => $event,
            'subject'     => $subject,
            'description' => $description,
            'importance'  => $importance,
        ];
        if ($message !== '') {
            $archive['message'] = str_replace('\n', '<br>', $message);
        }
        $written = @file_put_contents($archiveDir . '/' . $file, self::buildIni($archive));

        $entity = (int) ($conf[$importance] ?? '1');
        if (($entity & 1) === 1) {
            $unread = [
                'timestamp'   => $timestamp,
                'event'       => $event,
                'subject'     => $subject,
                'description' => $description,
                'importance'  => $importance,
                'link'        => $link,
            ];
            @file_put_contents($unreadDir . '/' . $file, self::buildIni($unread));
        }

        return $written !== false;
    }

    /**
     * @return array<string,string>
     */
    private static function config(): array
    {
        $out = ['path' => self::DEFAULT_PATH, 'normal' => '1', 'warning' => '5', 'alert' => '5'];
        $file = '/boot/config/plugins/dynamix/dynamix.cfg';
        if (is_file($file)) {
            $ini = @parse_ini_file($file, true, INI_SCANNER_RAW);
            if (is_array($ini) && isset($ini['notify']) && is_array($ini['notify'])) {
                foreach (['path', 'normal', 'warning', 'alert'] as $key) {
                    if (isset($ini['notify'][$key]) && $ini['notify'][$key] !== '') {
                        $out[$key] = (string) $ini['notify'][$key];
                    }
                }
            }
        }
        $out['path'] = rtrim($out['path'], '/');
        if ($out['path'] === '') {
            $out['path'] = self::DEFAULT_PATH;
        }
        return $out;
    }

    private static function safeFilename(string $string): string
    {
        $special = ['?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}'];
        $string = trim(str_replace($special, '', $string));
        $string = (string) preg_replace('~[^0-9a-z -_]~i', '', $string);
        $string = (string) preg_replace('~[- ]~i', '_', $string);
        return trim($string);
    }

    private static function cleanSubject(string $subject): string
    {
        return (string) preg_replace('/&#?[a-z0-9]{2,8};/i', ' ', $subject);
    }

    /**
     * @param int|float|bool|string $value
     */
    private static function encodeValue($value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return '"' . strtr((string) $value, ['\\' => '\\\\', '"' => '\\"']) . '"';
    }

    /**
     * @param array<string,int|float|bool|string> $data
     */
    private static function buildIni(array $data): string
    {
        $lines = [];
        foreach ($data as $key => $value) {
            $lines[] = $key . '=' . self::encodeValue($value);
        }
        return implode("\n", $lines) . "\n";
    }
}
