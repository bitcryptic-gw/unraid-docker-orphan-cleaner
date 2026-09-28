<?php
declare(strict_types=1);

require_once __DIR__ . '/Exec.php';

/**
 * Plugin settings.
 *
 * Settings live on the flash boot device under
 * /boot/config/plugins/docker.orphan.cleaner/ so they survive a reboot.
 * Validation is applied both when loading and when saving, so a hand-edited
 * file cannot feed an invalid value into the rest of the plugin.
 */
final class Config
{
    public const DIR    = '/boot/config/plugins/docker.orphan.cleaner';
    public const FILE   = self::DIR . '/docker.orphan.cleaner.cfg';
    public const CRON   = self::DIR . '/docker.orphan.cleaner.cron';
    public const SCRIPT = '/usr/local/emhttp/plugins/docker.orphan.cleaner/scripts/scheduled.php';

    public const SCHEDULES = ['off', 'daily', 'weekly'];
    public const MODES     = ['notify', 'delete-untagged'];

    public const MAX_PINS       = 50;
    public const MAX_PIN_LENGTH = 200;

    /** @var int */
    public $minAgeDays = 7;

    /** @var string one of SCHEDULES */
    public $schedule = 'off';

    /** @var string one of MODES */
    public $scheduleMode = 'notify';

    /** @var bool */
    public $includeBuildCache = false;

    /** @var array<int,string> */
    public $pinPatterns = [];

    public static function load(): self
    {
        $cfg = new self();
        if (is_file(self::FILE)) {
            $raw = @file_get_contents(self::FILE);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($data)) {
                $cfg = self::fromArray($data);
            }
        }
        return $cfg;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $cfg = new self();
        if (isset($data['minAgeDays'])) {
            $cfg->minAgeDays = (int) $data['minAgeDays'];
        }
        if (isset($data['schedule']) && is_string($data['schedule'])) {
            $cfg->schedule = $data['schedule'];
        }
        if (isset($data['scheduleMode']) && is_string($data['scheduleMode'])) {
            $cfg->scheduleMode = $data['scheduleMode'];
        }
        if (array_key_exists('includeBuildCache', $data)) {
            $cfg->includeBuildCache = (bool) $data['includeBuildCache'];
        }
        if (isset($data['pinPatterns']) && is_array($data['pinPatterns'])) {
            $pins = [];
            foreach ($data['pinPatterns'] as $pin) {
                if (is_string($pin)) {
                    $pins[] = $pin;
                }
            }
            $cfg->pinPatterns = $pins;
        }
        $cfg->validate();
        return $cfg;
    }

    /**
     * Clamp/repair values in place.
     *
     * @return array<int,string> human readable problems (empty when clean)
     */
    public function validate(): array
    {
        $errors = [];

        if ($this->minAgeDays < 0 || $this->minAgeDays > 3650) {
            $errors[] = 'minimum age must be between 0 and 3650 days';
            $this->minAgeDays = 7;
        }
        if (!in_array($this->schedule, self::SCHEDULES, true)) {
            $errors[] = 'invalid schedule value';
            $this->schedule = 'off';
        }
        if (!in_array($this->scheduleMode, self::MODES, true)) {
            $errors[] = 'invalid scheduled mode';
            $this->scheduleMode = 'notify';
        }

        $pins = [];
        foreach ($this->pinPatterns as $pin) {
            if (!is_string($pin)) {
                continue;
            }
            $pin = trim($pin);
            if ($pin === '') {
                continue;
            }
            if (strlen($pin) > self::MAX_PIN_LENGTH) {
                $errors[] = 'pin pattern exceeds ' . self::MAX_PIN_LENGTH . ' characters';
                continue;
            }
            if (!preg_match('#^[A-Za-z0-9._/:*@-]+$#', $pin)) {
                $errors[] = 'pin pattern contains invalid characters';
                continue;
            }
            $pins[] = $pin;
            if (count($pins) >= self::MAX_PINS) {
                $errors[] = 'only the first ' . self::MAX_PINS . ' pin patterns are kept';
                break;
            }
        }
        $this->pinPatterns = $pins;

        return $errors;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'minAgeDays'        => $this->minAgeDays,
            'schedule'          => $this->schedule,
            'scheduleMode'      => $this->scheduleMode,
            'includeBuildCache' => $this->includeBuildCache,
            'pinPatterns'       => array_values($this->pinPatterns),
        ];
    }

    public function save(): bool
    {
        if (!is_dir(self::DIR) && !@mkdir(self::DIR, 0755, true) && !is_dir(self::DIR)) {
            return false;
        }
        $json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $tmp = self::FILE . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, self::FILE)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Rewrite the native Unraid cron fragment for this plugin and refresh the
     * system crontab. Unraid assembles /etc/cron.d/root from
     * /boot/config/plugins/<plugin>/*.cron, so this survives reboots.
     *
     * @return array<int,string> cron lines written
     */
    public function writeCron(): array
    {
        $lines = [];
        if ($this->schedule === 'daily') {
            $lines[] = '0 4 * * * /usr/bin/php ' . self::SCRIPT;
        } elseif ($this->schedule === 'weekly') {
            $lines[] = '0 4 * * 0 /usr/bin/php ' . self::SCRIPT;
        }

        if (!is_dir(self::DIR) && !@mkdir(self::DIR, 0755, true) && !is_dir(self::DIR)) {
            return [];
        }
        if (count($lines) > 0) {
            $body = "# managed by the docker.orphan.cleaner plugin - do not edit\n";
            $body .= implode("\n", $lines) . "\n";
            @file_put_contents(self::CRON, $body, LOCK_EX);
        } else {
            @unlink(self::CRON);
        }

        self::updateCron();
        return $lines;
    }

    private static function updateCron(): void
    {
        // Run Unraid's update_cron shell-free (argv array, no /bin/sh). Unraid
        // 7.x ships it in /usr/local/sbin; older layouts used the webGui path.
        $candidates = ['/usr/local/sbin/update_cron', '/usr/local/emhttp/webGui/scripts/update_cron'];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                try {
                    Exec::run([$path]);
                } catch (Throwable $e) {
                    // Nothing useful to do here; the cron fragment is still on flash.
                }
                return;
            }
        }
    }
}
