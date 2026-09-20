<?php

namespace Tests\Unit;

use App\Models\AppSetting;
use App\Support\LinuxAudioDevices;
use App\Support\Linux5090;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinuxAudioDevicesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        LinuxAudioDevices::resetProbes();
        parent::tearDown();
    }

    public function test_windows_arm_and_non_linux_never_discover(): void
    {
        $this->assertFalse(LinuxAudioDevices::shouldDiscover('Windows', 'ARM64'));
        $this->assertFalse(LinuxAudioDevices::shouldDiscover('Windows', 'x86_64'));
        $this->assertFalse(LinuxAudioDevices::shouldDiscover('Linux', 'aarch64'));
        $this->assertFalse(LinuxAudioDevices::isLinux('Windows'));
        $this->assertTrue(LinuxAudioDevices::isLinux('Linux'));

        $empty = LinuxAudioDevices::discover('Windows', 'ARM64');
        $this->assertFalse($empty['linux']);
        $this->assertSame([], $empty['sources']);
        $this->assertSame([], $empty['missing_packages']);
        $this->assertSame([], LinuxAudioDevices::packageStatus('Windows', 'ARM64'));
    }

    public function test_matches_s500_and_bluez_input_not_onboard(): void
    {
        $this->assertTrue(LinuxAudioDevices::matchesPreferred('Anker PowerConf S500'));
        $this->assertTrue(LinuxAudioDevices::matchesPreferred('bluez_input.84_D3_52_EE_10_E3.headset-head-unit'));
        $this->assertTrue(LinuxAudioDevices::matchesPreferred('bluez_output.84_D3_52_EE_10_E3.a2dp-sink'));
        $this->assertFalse(LinuxAudioDevices::matchesPreferred('Built-in Audio Analog Stereo'));
        $this->assertFalse(LinuxAudioDevices::matchesPreferred('ALC897 Analog'));
        $this->assertFalse(LinuxAudioDevices::matchesPreferred(''));
    }

    public function test_parse_pactl_prefers_s500_source_and_skips_monitors(): void
    {
        $sources = LinuxAudioDevices::parsePactlList(self::pactlSourcesFixture(), 'source');
        $this->assertCount(2, $sources);
        $names = array_column($sources, 'name');
        $this->assertContains('alsa_input.pci-0000_00_1f.3.analog-stereo', $names);
        $this->assertContains('bluez_input.84_D3_52_EE_10_E3.headset-head-unit', $names);
        $this->assertNotContains('alsa_output.pci-0000_00_1f.3.analog-stereo.monitor', $names);

        $preferred = LinuxAudioDevices::prefer($sources);
        $this->assertNotNull($preferred);
        $this->assertSame('bluez_input.84_D3_52_EE_10_E3.headset-head-unit', $preferred['name']);
        $this->assertSame('Anker PowerConf S500', $preferred['description']);
        $this->assertSame(1, $preferred['channels']);
        $this->assertSame(16000, $preferred['rate']);
        $this->assertTrue($preferred['preferred']);
    }

    public function test_a2dp_active_profile_warns_no_mic(): void
    {
        $cards = LinuxAudioDevices::parsePactlCards(self::pactlCardsA2dpFixture());
        $this->assertCount(1, $cards);
        $this->assertSame('a2dp-sink', $cards[0]['active_profile']);
        $this->assertContains('headset-head-unit', $cards[0]['profiles']);
        $this->assertTrue(LinuxAudioDevices::cardIsA2dpOnly($cards[0]));

        LinuxAudioDevices::$pactlSourcesProbe = fn () => self::pactlSourcesA2dpOnlyFixture();
        LinuxAudioDevices::$pactlSinksProbe = fn () => self::pactlSinksFixture();
        LinuxAudioDevices::$pactlCardsProbe = fn () => self::pactlCardsA2dpFixture();
        LinuxAudioDevices::$wpctlProbe = fn () => self::wpctlFixture();
        LinuxAudioDevices::$commandProbe = fn () => true;
        LinuxAudioDevices::$fileProbe = fn () => true;

        $found = LinuxAudioDevices::discover('Linux', 'x86_64');
        $this->assertTrue($found['linux']);
        $this->assertNotNull($found['preferred_sink']);
        $this->assertNull($found['preferred_source']);
        $this->assertNotEmpty($found['warnings']);
        $this->assertStringContainsString('headset-head-unit', $found['warnings'][0]);
    }

    public function test_discover_handsfree_prefers_bluez_input_and_output(): void
    {
        LinuxAudioDevices::$pactlSourcesProbe = fn () => self::pactlSourcesFixture();
        LinuxAudioDevices::$pactlSinksProbe = fn () => self::pactlSinksFixture();
        LinuxAudioDevices::$pactlCardsProbe = fn () => self::pactlCardsHandsfreeFixture();
        LinuxAudioDevices::$wpctlProbe = fn () => self::wpctlFixture();
        LinuxAudioDevices::$commandProbe = fn () => true;
        LinuxAudioDevices::$fileProbe = fn () => true;

        $found = LinuxAudioDevices::discover('Linux', 'x86_64');
        $this->assertSame('bluez_input.84_D3_52_EE_10_E3.headset-head-unit', $found['preferred_source']['name']);
        $this->assertSame('bluez_output.84_D3_52_EE_10_E3.headset-head-unit', $found['preferred_sink']['name']);
        $this->assertSame('headset-head-unit', $found['preferred_card']['active_profile']);
        $this->assertFalse(LinuxAudioDevices::cardIsA2dpOnly($found['preferred_card']));
        $this->assertSame('Anker PowerConf S500', $found['wpctl']['default_source']);
        $this->assertSame([], $found['warnings']);
        $this->assertSame([], $found['missing_packages']);
    }

    public function test_pick_media_input_saved_id_then_label_then_auto_s500(): void
    {
        $devices = [
            ['deviceId' => 'default', 'label' => 'Default', 'kind' => 'audioinput'],
            ['deviceId' => 'onboard', 'label' => 'Built-in Audio Analog Stereo', 'kind' => 'audioinput'],
            ['deviceId' => 's500-id', 'label' => 'Anker PowerConf S500 Mono', 'kind' => 'audioinput'],
        ];

        $byId = LinuxAudioDevices::pickMediaInput($devices, [
            'linux' => true,
            'auto_prefer' => true,
            'input_device' => 'onboard',
            'input_label' => '',
        ]);
        $this->assertSame('onboard', $byId['deviceId']);

        $byLabel = LinuxAudioDevices::pickMediaInput($devices, [
            'linux' => true,
            'auto_prefer' => true,
            'input_device' => 'stale-id',
            'input_label' => 'Anker PowerConf S500 Mono',
        ]);
        $this->assertSame('s500-id', $byLabel['deviceId']);

        $auto = LinuxAudioDevices::pickMediaInput($devices, [
            'linux' => true,
            'auto_prefer' => true,
            'input_device' => '',
            'input_label' => '',
        ]);
        $this->assertSame('s500-id', $auto['deviceId']);

        $windows = LinuxAudioDevices::pickMediaInput($devices, [
            'linux' => false,
            'auto_prefer' => true,
            'input_device' => '',
            'input_label' => '',
        ]);
        $this->assertNull($windows);

        $noAuto = LinuxAudioDevices::pickMediaInput($devices, [
            'linux' => true,
            'auto_prefer' => false,
            'input_device' => '',
            'input_label' => '',
        ]);
        $this->assertNull($noAuto);
    }

    public function test_capture_config_reads_settings_and_windows_omits_hints(): void
    {
        AppSetting::set(LinuxAudioDevices::SETTING_INPUT_LABEL, 'Anker PowerConf S500 Mono');
        AppSetting::set(LinuxAudioDevices::SETTING_AUTO_PREFER, '0');

        LinuxAudioDevices::$pactlSourcesProbe = fn () => '';
        LinuxAudioDevices::$pactlSinksProbe = fn () => '';
        LinuxAudioDevices::$pactlCardsProbe = fn () => '';
        LinuxAudioDevices::$wpctlProbe = fn () => '';
        LinuxAudioDevices::$commandProbe = fn () => false;
        LinuxAudioDevices::$fileProbe = fn () => false;

        $win = LinuxAudioDevices::captureConfig('Windows', 'ARM64');
        $this->assertFalse($win['linux']);
        $this->assertNull($win['capture']);
        $this->assertFalse($win['auto_prefer']);
        $this->assertSame('Anker PowerConf S500 Mono', $win['input_label']);
        $this->assertSame([], $win['pipewire']['sources']);

        $this->assertSame(16000, LinuxAudioDevices::captureHints()['sampleRate']);
        $this->assertSame(1, LinuxAudioDevices::captureHints()['channelCount']);
    }

    public function test_settings_persist_audio_keys_and_json_endpoint(): void
    {
        $this->from('/settings')->post('/settings', [
            'whisperx_model' => 'base',
            'whisperx_languages' => 'en,es',
            'audio_input_device' => 's500-id',
            'audio_input_label' => 'Anker PowerConf S500 Mono',
            'audio_input_pulse_name' => 'bluez_input.84_D3_52_EE_10_E3.headset-head-unit',
            'audio_output_label' => 'Anker PowerConf S500',
            'audio_auto_prefer' => '1',
        ])->assertRedirect('/settings');

        $this->assertSame('s500-id', AppSetting::get('audio_input_device'));
        $this->assertSame('Anker PowerConf S500 Mono', AppSetting::get('audio_input_label'));
        $this->assertSame('bluez_input.84_D3_52_EE_10_E3.headset-head-unit', AppSetting::get('audio_input_pulse_name'));
        $this->assertSame('1', AppSetting::get('audio_auto_prefer'));

        $json = $this->getJson('/settings/audio-capture');
        $json->assertOk();
        $json->assertJsonPath('input_device', 's500-id');
        $json->assertJsonPath('preferred_mac', '84:D3:52:EE:10:E3');
        $json->assertJsonPath('preferred_label', 'Anker PowerConf S500');
    }

    public function test_recording_and_settings_ui_select_s500_windows_keeps_default_gum(): void
    {
        $ctx = file_get_contents(dirname(__DIR__, 2).'/resources/js/Contexts/RecordingContext.tsx');
        $lib = file_get_contents(dirname(__DIR__, 2).'/resources/js/lib/audioCapture.ts');
        $settings = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Settings/Index.tsx');
        $enroll = file_get_contents(dirname(__DIR__, 2).'/resources/js/hooks/useEnrollmentCapture.ts');
        $voices = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Campaigns/Voices.tsx');
        $this->assertIsString($ctx);
        $this->assertIsString($lib);
        $this->assertIsString($settings);

        $this->assertStringContainsString('openCaptureStream', $ctx);
        $this->assertStringContainsString('fetchAudioCaptureConfig', $ctx);
        $this->assertStringContainsString('activeInputLabel', $ctx);

        $this->assertIsString($enroll);
        $this->assertStringContainsString('openCaptureStream', $enroll);
        $this->assertStringContainsString('fetchAudioCaptureConfig', $enroll);
        $this->assertStringContainsString('MediaRecorder', $enroll);
        $this->assertIsString($voices);
        $this->assertStringContainsString('useEnrollmentCapture', $voices);
        $this->assertStringNotContainsString('getUserMedia({ audio: true })', $voices);

        $this->assertStringContainsString('{ audio: true }', $lib);
        $this->assertStringContainsString('Anker PowerConf S500', $lib);
        $this->assertStringContainsString('bluez_input.84_d3_52_ee_10_e3', $lib);
        $this->assertStringContainsString('Windows ARM / non-Linux with no saved device', $lib);
        $this->assertStringContainsString('sampleRate', $lib);
        $this->assertStringContainsString('channelCount', $lib);

        $this->assertStringContainsString('Session Audio', $settings);
        $this->assertStringContainsString('audio_input_device', $settings);
        $this->assertStringContainsString('headset-head-unit', $settings);
        $this->assertStringContainsString('linux-5090-audio.sh', $settings);
        $this->assertStringNotContainsString('tiny.en', $settings);
    }

    public function test_docs_and_helper_cover_s500_and_windows_arm_untouched(): void
    {
        $root = dirname(__DIR__, 2);
        $md = file_get_contents($root.'/LINUX-5090.md');
        $helper = file_get_contents($root.'/scripts/linux-5090-audio.sh');
        $setup = file_get_contents($root.'/scripts/linux-5090-setup.sh');
        $ps1 = file_get_contents($root.'/resources/python/setup.ps1');
        $serve = file_get_contents($root.'/scripts/native-serve.ps1');
        $arm = file_get_contents($root.'/WINDOWS-ARM.md');

        $this->assertIsString($md);
        $this->assertIsString($helper);
        $this->assertIsString($setup);
        $this->assertIsString($ps1);
        $this->assertIsString($serve);
        $this->assertIsString($arm);

        foreach (['84:D3:52:EE:10:E3', 'headset-head-unit', 'bluez_input', 'Anker PowerConf S500', 'arecord -l', 'libspa-0.2-bluetooth', 'wpctl status', 'pipewire-pulse'] as $needle) {
            $this->assertStringContainsString($needle, $md);
            $this->assertStringContainsString($needle === 'wpctl status' ? 'wpctl' : $needle, $helper);
        }
        $this->assertStringContainsString('No Anker proprietary driver', $md);
        $this->assertStringContainsString('linux-5090-audio.sh --apply', $md);
        $this->assertStringContainsString('linux-5090-audio.sh --apply', $setup);

        $this->assertStringNotContainsString('linux-5090-audio', $ps1);
        $this->assertStringNotContainsString('PowerConf', $ps1);
        $this->assertStringNotContainsString('pipewire', $ps1);
        $this->assertStringNotContainsString('linux-5090-audio', $serve);
        $this->assertStringNotContainsString('PowerConf', $arm);
        $this->assertStringNotContainsString('pipewire', $arm);
        $this->assertStringNotContainsString('headset-head-unit', $arm);

        $this->assertTrue(Linux5090::isLinuxX86('Linux', 'x86_64'));
        $this->assertFalse(Linux5090::isWindowsArmPath('Linux', 'x86_64', ''));
    }

    public function test_php_has_no_sounddevice_or_arecord_capture_path(): void
    {
        $py = file_get_contents(dirname(__DIR__, 2).'/resources/python/run_whisperx.py');
        $this->assertIsString($py);
        $this->assertStringNotContainsString('sounddevice', $py);
        $this->assertStringNotContainsString('arecord', $py);
        $this->assertStringContainsString('whisperx.load_audio', $py);
    }

    private static function pactlSourcesFixture(): string
    {
        return <<<'TXT'
Source #12
	State: SUSPENDED
	Name: alsa_output.pci-0000_00_1f.3.analog-stereo.monitor
	Description: Monitor of Built-in Audio Analog Stereo
	Driver: PipeWire
	Sample Specification: s32le 2ch 48000Hz
	Monitor of Sink: alsa_output.pci-0000_00_1f.3.analog-stereo
Source #13
	State: SUSPENDED
	Name: alsa_input.pci-0000_00_1f.3.analog-stereo
	Description: Built-in Audio Analog Stereo
	Driver: PipeWire
	Sample Specification: s32le 2ch 48000Hz
	Monitor of Sink: n/a
Source #45
	State: RUNNING
	Name: bluez_input.84_D3_52_EE_10_E3.headset-head-unit
	Description: Anker PowerConf S500
	Driver: PipeWire
	Sample Specification: s16le 1ch 16000Hz
	Monitor of Sink: n/a
	Properties:
		api.bluez5.address = "84:D3:52:EE:10:E3"
		api.bluez5.profile = "headset-head-unit"
		node.name = "bluez_input.84_D3_52_EE_10_E3.headset-head-unit"
TXT;
    }

    private static function pactlSourcesA2dpOnlyFixture(): string
    {
        return <<<'TXT'
Source #13
	State: SUSPENDED
	Name: alsa_input.pci-0000_00_1f.3.analog-stereo
	Description: Built-in Audio Analog Stereo
	Driver: PipeWire
	Sample Specification: s32le 2ch 48000Hz
	Monitor of Sink: n/a
TXT;
    }

    private static function pactlSinksFixture(): string
    {
        return <<<'TXT'
Sink #41
	State: SUSPENDED
	Name: alsa_output.pci-0000_00_1f.3.analog-stereo
	Description: Built-in Audio Analog Stereo
	Driver: PipeWire
	Sample Specification: s32le 2ch 48000Hz
Sink #43
	State: RUNNING
	Name: bluez_output.84_D3_52_EE_10_E3.headset-head-unit
	Description: Anker PowerConf S500
	Driver: PipeWire
	Sample Specification: s16le 1ch 16000Hz
TXT;
    }

    private static function pactlCardsA2dpFixture(): string
    {
        return <<<'TXT'
Card #42
	Name: bluez_card.84_D3_52_EE_10_E3
	Driver: module-bluez5-device.c
	Owner Module: n/a
	Properties:
		device.description = "Anker PowerConf S500"
		device.string = "84:D3:52:EE:10:E3"
	Profiles:
		a2dp-sink: High Fidelity Playback (A2DP Sink) (sinks: 1, sources: 0, priority: 18, available: yes)
		headset-head-unit: Headset Head Unit (HSP/HFP) (sinks: 1, sources: 1, priority: 1, available: yes)
		off: Off (sinks: 0, sources: 0, priority: 0, available: yes)
	Active Profile: a2dp-sink
	Ports:
		speaker-output: Speaker (type: Speaker, priority: 0, latency offset: 0 usec, available)
TXT;
    }

    private static function pactlCardsHandsfreeFixture(): string
    {
        return <<<'TXT'
Card #42
	Name: bluez_card.84_D3_52_EE_10_E3
	Driver: module-bluez5-device.c
	Properties:
		device.description = "Anker PowerConf S500"
	Profiles:
		a2dp-sink: High Fidelity Playback (A2DP Sink) (sinks: 1, sources: 0, priority: 18, available: yes)
		headset-head-unit: Headset Head Unit (HSP/HFP) (sinks: 1, sources: 1, priority: 1, available: yes)
	Active Profile: headset-head-unit
	Ports:
		speaker-output: Speaker (type: Speaker, priority: 0)
TXT;
    }

    private static function wpctlFixture(): string
    {
        return <<<'TXT'
Audio
 ├─ Devices:
 │      40. Built-in Audio                          [alsa]
 │      42. Anker PowerConf S500                    [bluez5]
 ├─ Sinks:
 │      41. Built-in Audio Analog Stereo            [vol: 0.40]
 │  *   43. Anker PowerConf S500                    [vol: 1.00]
 ├─ Sink endpoints:
 ├─ Sources:
 │      44. Built-in Audio Analog Stereo            [vol: 1.00]
 │  *   45. Anker PowerConf S500                    [vol: 1.00]
 ├─ Source endpoints:
 └─ Streams:
Video
 ├─ Devices:
TXT;
    }
}
