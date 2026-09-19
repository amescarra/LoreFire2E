<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * PipeWire / Pulse / BlueZ discovery for the linux-5090 session-capture path.
 *
 * Live recording is Electron getUserMedia (not raw ALSA). Bluetooth speakerphone
 * mics such as the Anker PowerConf S500 appear as bluez_input nodes only after
 * the Handsfree / headset-head-unit profile is active. A2DP is playback-only.
 *
 * Windows ARM never uses this class for capture defaults.
 */
class LinuxAudioDevices
{
    public const PREFERRED_LABEL = 'Anker PowerConf S500';

    public const PREFERRED_MAC = '84:D3:52:EE:10:E3';

    /** Pulse / PipeWire node fragment for this MAC. */
    public const PREFERRED_MAC_NODE = '84_D3_52_EE_10_E3';

    /** @var list<string> */
    public const PREFERRED_NEEDLES = [
        'anker powerconf s500',
        'powerconf s500',
        'bluez_input.84_d3_52_ee_10_e3',
        'bluez_output.84_d3_52_ee_10_e3',
        'bluez_card.84_d3_52_ee_10_e3',
    ];

    /** WhisperX load_audio() resamples to 16 kHz. HFP wideband SCO is also 16 kHz. */
    public const CAPTURE_SAMPLE_RATE = 16000;

    public const CAPTURE_CHANNELS = 1;

    public const SETTING_INPUT_DEVICE = 'audio_input_device';

    public const SETTING_INPUT_LABEL = 'audio_input_label';

    public const SETTING_INPUT_PULSE = 'audio_input_pulse_name';

    public const SETTING_OUTPUT_DEVICE = 'audio_output_device';

    public const SETTING_OUTPUT_LABEL = 'audio_output_label';

    public const SETTING_OUTPUT_PULSE = 'audio_output_pulse_name';

    public const SETTING_AUTO_PREFER = 'audio_auto_prefer';

    /** @var callable|null fn(): string pactl list sources */
    public static $pactlSourcesProbe = null;

    /** @var callable|null fn(): string pactl list sinks */
    public static $pactlSinksProbe = null;

    /** @var callable|null fn(): string pactl list cards */
    public static $pactlCardsProbe = null;

    /** @var callable|null fn(): string wpctl status */
    public static $wpctlProbe = null;

    /** @var callable|null fn(string $bin): bool */
    public static $commandProbe = null;

    /** @var callable|null fn(string $path): bool */
    public static $fileProbe = null;

    public static function isLinux(?string $osFamily = null): bool
    {
        $osFamily ??= PHP_OS_FAMILY;

        return $osFamily === 'Linux' && ! Linux5090::isWindowsArmPath();
    }

    /**
     * Linux x86_64 only — same gate as the 5090 path. ARM Linux / Windows skip.
     */
    public static function shouldDiscover(?string $osFamily = null, ?string $machine = null): bool
    {
        return Linux5090::isLinuxX86($osFamily, $machine) && ! Linux5090::isWindowsArmPath();
    }

    public static function matchesPreferred(string $haystack): bool
    {
        $hay = strtolower($haystack);
        if ($hay === '') {
            return false;
        }
        foreach (self::PREFERRED_NEEDLES as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }
        if (str_contains($hay, 'bluez_input') && str_contains($hay, 's500')) {
            return true;
        }

        return false;
    }

    /**
     * Conference-speakerphone getUserMedia hints. Applied only on the Linux path.
     *
     * @return array{channelCount: int, sampleRate: int, echoCancellation: bool, noiseSuppression: bool, autoGainControl: bool}
     */
    public static function captureHints(): array
    {
        return [
            'channelCount' => self::CAPTURE_CHANNELS,
            'sampleRate' => self::CAPTURE_SAMPLE_RATE,
            'echoCancellation' => true,
            'noiseSuppression' => true,
            'autoGainControl' => true,
        ];
    }

    /**
     * @return list<array{name: string, apt: string, present: bool, hint: string}>
     */
    public static function packageStatus(?string $osFamily = null, ?string $machine = null): array
    {
        if (! self::shouldDiscover($osFamily, $machine)) {
            return [];
        }

        $has = function (string $bin) {
            if (is_callable(self::$commandProbe)) {
                return (bool) (self::$commandProbe)($bin);
            }
            $path = trim((string) shell_exec('command -v '.escapeshellarg($bin).' 2>/dev/null'));

            return $path !== '';
        };

        $fileExists = function (string $path) {
            if (is_callable(self::$fileProbe)) {
                return (bool) (self::$fileProbe)($path);
            }

            return is_file($path);
        };

        $bluezSpa = $fileExists('/usr/lib/x86_64-linux-gnu/spa-0.2/bluez5/libspa-bluez5.so')
            || $fileExists('/usr/lib/spa-0.2/bluez5/libspa-bluez5.so')
            || $has('libspa-bluez5');

        return [
            [
                'name' => 'pipewire',
                'apt' => 'pipewire',
                'present' => $has('pipewire') || $has('pw-cli'),
                'hint' => 'PipeWire daemon',
            ],
            [
                'name' => 'pipewire-pulse',
                'apt' => 'pipewire-pulse',
                'present' => $has('pipewire-pulse') || $has('pactl'),
                'hint' => 'Pulse compatibility — Electron/Chromium and WhisperX capture go through this, not ALSA hw:',
            ],
            [
                'name' => 'wireplumber',
                'apt' => 'wireplumber',
                'present' => $has('wpctl'),
                'hint' => 'wpctl status / set-default',
            ],
            [
                'name' => 'pulseaudio-utils',
                'apt' => 'pulseaudio-utils',
                'present' => $has('pactl'),
                'hint' => 'pactl list sources / set-card-profile',
            ],
            [
                'name' => 'bluez',
                'apt' => 'bluez',
                'present' => $has('bluetoothctl'),
                'hint' => 'Pair / connect / trust the S500',
            ],
            [
                'name' => 'libspa-0.2-bluetooth',
                'apt' => 'libspa-0.2-bluetooth',
                'present' => $bluezSpa,
                'hint' => 'BlueZ SPA plugin (libspa-bluez5). No Anker proprietary driver.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function missingPackages(?string $osFamily = null, ?string $machine = null): array
    {
        $missing = [];
        foreach (self::packageStatus($osFamily, $machine) as $pkg) {
            if (! $pkg['present']) {
                $missing[] = $pkg['apt'];
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @return array<string, mixed>
     */
    public static function discover(?string $osFamily = null, ?string $machine = null): array
    {
        if (! self::shouldDiscover($osFamily, $machine)) {
            return self::emptyDiscovery();
        }

        $sourcesOut = self::probeOutput(self::$pactlSourcesProbe, 'pactl list sources 2>/dev/null');
        $sinksOut = self::probeOutput(self::$pactlSinksProbe, 'pactl list sinks 2>/dev/null');
        $cardsOut = self::probeOutput(self::$pactlCardsProbe, 'pactl list cards 2>/dev/null');
        $wpctlOut = self::probeOutput(self::$wpctlProbe, 'wpctl status 2>/dev/null');

        $sources = self::parsePactlList($sourcesOut, 'source');
        $sinks = self::parsePactlList($sinksOut, 'sink');
        $cards = self::parsePactlCards($cardsOut);
        $wpctl = self::parseWpctlStatus($wpctlOut);

        $preferredSource = self::prefer($sources);
        $preferredSink = self::prefer($sinks);
        $preferredCard = self::preferCard($cards);

        $warnings = [];
        if ($preferredCard !== null && self::cardIsA2dpOnly($preferredCard)) {
            $warnings[] = 'Anker PowerConf S500 is on A2DP (playback only). Switch the BlueZ profile to headset-head-unit for the microphone.';
        }
        if ($preferredSink !== null && $preferredSource === null) {
            $warnings[] = 'S500 sink is visible but no bluez_input source. Handsfree / headset-head-unit is required for capture.';
        }

        return [
            'linux' => true,
            'sources' => $sources,
            'sinks' => $sinks,
            'cards' => $cards,
            'wpctl' => $wpctl,
            'preferred_source' => $preferredSource,
            'preferred_sink' => $preferredSink,
            'preferred_card' => $preferredCard,
            'warnings' => $warnings,
            'missing_packages' => self::missingPackages($osFamily, $machine),
            'packages' => self::packageStatus($osFamily, $machine),
        ];
    }

    /**
     * Shared payload for Settings + live getUserMedia.
     *
     * @return array<string, mixed>
     */
    public static function captureConfig(?string $osFamily = null, ?string $machine = null): array
    {
        $linux = self::shouldDiscover($osFamily, $machine);
        $auto = self::autoPreferEnabled();

        return [
            'linux' => $linux,
            'input_device' => (string) (AppSetting::get(self::SETTING_INPUT_DEVICE, '') ?? ''),
            'input_label' => (string) (AppSetting::get(self::SETTING_INPUT_LABEL, '') ?? ''),
            'input_pulse_name' => (string) (AppSetting::get(self::SETTING_INPUT_PULSE, '') ?? ''),
            'output_device' => (string) (AppSetting::get(self::SETTING_OUTPUT_DEVICE, '') ?? ''),
            'output_label' => (string) (AppSetting::get(self::SETTING_OUTPUT_LABEL, '') ?? ''),
            'output_pulse_name' => (string) (AppSetting::get(self::SETTING_OUTPUT_PULSE, '') ?? ''),
            'auto_prefer' => $auto,
            'preferred_label' => self::PREFERRED_LABEL,
            'preferred_mac' => self::PREFERRED_MAC,
            'preferred_needles' => self::PREFERRED_NEEDLES,
            'capture' => $linux ? self::captureHints() : null,
            'pipewire' => $linux ? self::discover($osFamily, $machine) : self::emptyDiscovery(),
        ];
    }

    public static function autoPreferEnabled(): bool
    {
        $stored = AppSetting::get(self::SETTING_AUTO_PREFER);
        if ($stored === null || $stored === '') {
            return true;
        }

        return filter_var($stored, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  list<array<string, mixed>>  $devices
     * @return array<string, mixed>|null
     */
    public static function prefer(array $devices): ?array
    {
        foreach ($devices as $device) {
            $blob = implode(' ', [
                (string) ($device['name'] ?? ''),
                (string) ($device['description'] ?? ''),
                (string) ($device['label'] ?? ''),
            ]);
            if (self::matchesPreferred($blob)) {
                return $device;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, mixed>|null
     */
    public static function preferCard(array $cards): ?array
    {
        foreach ($cards as $card) {
            $blob = implode(' ', [
                (string) ($card['name'] ?? ''),
                (string) ($card['description'] ?? ''),
            ]);
            if (self::matchesPreferred($blob)) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $card
     */
    public static function cardIsA2dpOnly(array $card): bool
    {
        $active = strtolower((string) ($card['active_profile'] ?? ''));
        if ($active === '') {
            return false;
        }
        $handsfree = str_contains($active, 'headset') || str_contains($active, 'handsfree') || str_contains($active, 'head-unit');

        return (str_contains($active, 'a2dp') || str_contains($active, 'sink')) && ! $handsfree;
    }

    /**
     * Parse `pactl list sources` / `pactl list sinks`.
     *
     * @return list<array{kind: string, index: int|null, name: string, description: string, sample_spec: string, channels: int|null, rate: int|null, monitor_of: string|null, preferred: bool}>
     */
    public static function parsePactlList(string $output, string $kind): array
    {
        $blocks = preg_split('/^(?:Source|Sink) #/m', $output) ?: [];
        array_shift($blocks);
        $devices = [];
        foreach ($blocks as $block) {
            $index = null;
            if (preg_match('/^(\d+)/', $block, $m)) {
                $index = (int) $m[1];
            }
            $name = self::pactlField($block, 'Name');
            $description = self::pactlField($block, 'Description');
            $sample = self::pactlField($block, 'Sample Specification');
            $monitor = self::pactlField($block, 'Monitor of Sink');
            if ($monitor === 'n/a') {
                $monitor = null;
            }
            // Skip monitor sources — they are loopback, not a mic.
            if ($kind === 'source' && $monitor) {
                continue;
            }
            $channels = null;
            $rate = null;
            if (preg_match('/(\d+)ch\s+(\d+)Hz/i', $sample, $sm)) {
                $channels = (int) $sm[1];
                $rate = (int) $sm[2];
            }
            $preferred = self::matchesPreferred($name.' '.$description);
            $devices[] = [
                'kind' => $kind,
                'index' => $index,
                'name' => $name,
                'description' => $description,
                'label' => $description !== '' ? $description : $name,
                'sample_spec' => $sample,
                'channels' => $channels,
                'rate' => $rate,
                'monitor_of' => $monitor,
                'preferred' => $preferred,
            ];
        }

        return $devices;
    }

    /**
     * @return list<array{name: string, description: string, active_profile: string, profiles: list<string>, preferred: bool}>
     */
    public static function parsePactlCards(string $output): array
    {
        $blocks = preg_split('/^Card #/m', $output) ?: [];
        array_shift($blocks);
        $cards = [];
        foreach ($blocks as $block) {
            $name = self::pactlField($block, 'Name');
            $description = self::pactlField($block, 'device.description')
                ?: self::pactlQuotedProp($block, 'device.description')
                ?: self::pactlField($block, 'Description');
            $active = self::pactlField($block, 'Active Profile');
            $profiles = [];
            if (preg_match('/Profiles:\n(.*?)(?:\n\tPorts:|\n\tActive Profile:|\z)/s', $block, $pm)) {
                foreach (explode("\n", $pm[1]) as $line) {
                    if (preg_match('/^\t\t([^\s:]+):/', $line, $lm)) {
                        $profiles[] = $lm[1];
                    }
                }
            }
            $cards[] = [
                'name' => $name,
                'description' => $description,
                'active_profile' => $active,
                'profiles' => $profiles,
                'preferred' => self::matchesPreferred($name.' '.$description),
            ];
        }

        return $cards;
    }

    /**
     * @return array{default_source: string|null, default_sink: string|null, sources: list<string>, sinks: list<string>}
     */
    public static function parseWpctlStatus(string $output): array
    {
        $defaultSource = null;
        $defaultSink = null;
        $sources = [];
        $sinks = [];
        $section = null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/Sinks:/', $line)) {
                $section = 'sinks';

                continue;
            }
            if (preg_match('/Sources:/', $line)) {
                $section = 'sources';

                continue;
            }
            if (preg_match('/(?:Sink endpoints|Source endpoints|Filters|Streams|Video|Settings):/', $line)) {
                $section = null;

                continue;
            }
            if ($section === null) {
                continue;
            }
            if (! preg_match('/(\*?)\s+(\d+)\.\s+(.+?)\s*(?:\[.*)?$/', $line, $m)) {
                continue;
            }
            $star = trim($m[1]) === '*';
            $label = trim($m[3]);
            if ($section === 'sources') {
                $sources[] = $label;
                if ($star) {
                    $defaultSource = $label;
                }
            } else {
                $sinks[] = $label;
                if ($star) {
                    $defaultSink = $label;
                }
            }
        }

        return [
            'default_source' => $defaultSource,
            'default_sink' => $defaultSink,
            'sources' => $sources,
            'sinks' => $sinks,
        ];
    }

    /**
     * Pick a Chromium/Electron input from enumerateDevices() results.
     *
     * @param  list<array{deviceId?: string, label?: string, kind?: string}>  $mediaDevices
     * @param  array<string, mixed>  $config
     * @return array{deviceId: string, label: string}|null
     */
    public static function pickMediaInput(array $mediaDevices, array $config): ?array
    {
        $inputs = [];
        foreach ($mediaDevices as $device) {
            $kind = (string) ($device['kind'] ?? 'audioinput');
            if ($kind !== '' && $kind !== 'audioinput') {
                continue;
            }
            $id = (string) ($device['deviceId'] ?? '');
            $label = (string) ($device['label'] ?? '');
            if ($id === '' || $id === 'default' || $id === 'communications') {
                continue;
            }
            $inputs[] = ['deviceId' => $id, 'label' => $label];
        }

        $savedId = trim((string) ($config['input_device'] ?? ''));
        $savedLabel = trim((string) ($config['input_label'] ?? ''));
        if ($savedId !== '') {
            foreach ($inputs as $input) {
                if ($input['deviceId'] === $savedId) {
                    return $input;
                }
            }
        }
        if ($savedLabel !== '') {
            foreach ($inputs as $input) {
                if (strcasecmp($input['label'], $savedLabel) === 0) {
                    return $input;
                }
            }
            foreach ($inputs as $input) {
                if ($input['label'] !== '' && str_contains(strtolower($input['label']), strtolower($savedLabel))) {
                    return $input;
                }
            }
        }

        $auto = array_key_exists('auto_prefer', $config)
            ? filter_var($config['auto_prefer'], FILTER_VALIDATE_BOOLEAN)
            : true;
        $linux = ! empty($config['linux']);
        if ($linux && $auto) {
            foreach ($inputs as $input) {
                if (self::matchesPreferred($input['label'].' '.$input['deviceId'])) {
                    return $input;
                }
            }
        }

        return null;
    }

    public static function resetProbes(): void
    {
        self::$pactlSourcesProbe = null;
        self::$pactlSinksProbe = null;
        self::$pactlCardsProbe = null;
        self::$wpctlProbe = null;
        self::$commandProbe = null;
        self::$fileProbe = null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyDiscovery(): array
    {
        return [
            'linux' => false,
            'sources' => [],
            'sinks' => [],
            'cards' => [],
            'wpctl' => [
                'default_source' => null,
                'default_sink' => null,
                'sources' => [],
                'sinks' => [],
            ],
            'preferred_source' => null,
            'preferred_sink' => null,
            'preferred_card' => null,
            'warnings' => [],
            'missing_packages' => [],
            'packages' => [],
        ];
    }

    private static function probeOutput(?callable $override, string $command): string
    {
        if (is_callable($override)) {
            return (string) $override();
        }

        return (string) shell_exec($command);
    }

    private static function pactlField(string $block, string $key): string
    {
        if (preg_match('/^\t'.preg_quote($key, '/').':\s*(.+)$/m', $block, $m)) {
            return trim($m[1]);
        }

        return self::pactlQuotedProp($block, $key);
    }

    private static function pactlQuotedProp(string $block, string $key): string
    {
        if (preg_match('/^\t\t'.preg_quote($key, '/').'\s*=\s*"([^"]*)"/m', $block, $m)) {
            return trim($m[1]);
        }

        return '';
    }
}
