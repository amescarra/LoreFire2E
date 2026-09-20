<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Support\LinuxAudioDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AppSettingController extends Controller
{
    protected array $allowedKeys = [
        'llm_provider',      // openai | anthropic | ollama | zai | none
        'openai_api_key',
        'anthropic_api_key',
        'ollama_base_url',
        'ollama_model',
        'zai_api_key',
        'zai_model',         // e.g. glm-4-flash, glm-4.7, etc.
        'zai_plan',          // coding | standard — determines the base URL automatically
        'zai_base_url',      // derived and stored on save; never shown as a raw input
        'whisperx_model',    // tiny | base | small | medium | large-v2 | large-v3 (never *.en)
        'whisperx_languages', // csv allowlist, default en,es
        'whisperx_language', // legacy single code; read-only fallback
        'huggingface_token', // WhisperX diarization only; Oracle/AskOracle never read this key
        'default_art_style',      // comic | lifelike
        'image_gen_provider',     // none | zai | openai | comfyui
        'image_gen_model',        // e.g. cogview-4-flash, dall-e-3 (not used for comfyui)
        'image_gen_zai_api_key',  // separate standard-plan key for z.ai image generation
        'comfyui_base_url',       // e.g. http://localhost:8188
        'audio_input_device',     // Chromium/Electron deviceId (empty = auto)
        'audio_input_label',      // Human label used to rematch after deviceId churn
        'audio_input_pulse_name', // PipeWire/Pulse source name (Linux only)
        'audio_output_device',
        'audio_output_label',
        'audio_output_pulse_name',
        'audio_auto_prefer',      // 1 = prefer Anker PowerConf S500 / bluez_input on Linux
    ];

    public function index(): Response
    {
        $settings = [];
        foreach ($this->allowedKeys as $key) {
            $settings[$key] = AppSetting::get($key);
        }

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
            'whisperx_languages' => \App\Support\WhisperxLanguages::csv(),
            'audio_capture' => LinuxAudioDevices::captureConfig(),
        ]);
    }

    public function audioCapture(): JsonResponse
    {
        return response()->json(LinuxAudioDevices::captureConfig());
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'llm_provider'        => 'nullable|in:openai,anthropic,ollama,zai,none',
            'openai_api_key'      => 'nullable|string|max:255',
            'anthropic_api_key'   => 'nullable|string|max:255',
            'ollama_base_url'     => 'nullable|url|max:255',
            'ollama_model'        => 'nullable|string|max:100',
            'zai_api_key'         => 'nullable|string|max:255',
            'zai_model'           => 'nullable|string|max:100',
            'zai_plan'            => 'nullable|in:coding,standard',
            'whisperx_model'      => 'nullable|string|max:40',
            'whisperx_languages'  => 'nullable|string|max:20',
            'whisperx_language'   => 'nullable|string|max:10',
            'huggingface_token'   => 'nullable|string|max:255',
            'default_art_style'   => 'nullable|in:comic,lifelike',
            'image_gen_provider'  => 'nullable|in:none,zai,openai,comfyui',
            'image_gen_model'     => 'nullable|string|max:100',
            'image_gen_zai_api_key' => 'nullable|string|max:255',
            'comfyui_base_url'    => 'nullable|url|max:255',
            'audio_input_device'  => 'nullable|string|max:255',
            'audio_input_label'   => 'nullable|string|max:255',
            'audio_input_pulse_name' => 'nullable|string|max:255',
            'audio_output_device' => 'nullable|string|max:255',
            'audio_output_label'  => 'nullable|string|max:255',
            'audio_output_pulse_name' => 'nullable|string|max:255',
            'audio_auto_prefer'   => 'nullable|in:0,1,true,false',
        ]);

        // Derive zai_base_url from the plan selection — never store a user-supplied raw URL.
        $plan = $data['zai_plan'] ?? AppSetting::get('zai_plan', 'coding');
        $data['zai_base_url'] = $plan === 'standard'
            ? AppSetting::ZAI_STANDARD_URL
            : AppSetting::ZAI_CODING_URL;

        if (array_key_exists('whisperx_model', $data)) {
            $data['whisperx_model'] = \App\Support\WhisperxLanguages::coerceModel($data['whisperx_model'] ?? 'base');
        }
        if (array_key_exists('whisperx_languages', $data)) {
            $data['whisperx_languages'] = \App\Support\WhisperxLanguages::csv(
                \App\Support\WhisperxLanguages::parse($data['whisperx_languages'] ?? '')
            );
        }
        if (array_key_exists('audio_auto_prefer', $data) && $data['audio_auto_prefer'] !== null) {
            $data['audio_auto_prefer'] = filter_var($data['audio_auto_prefer'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        foreach ($data as $key => $value) {
            if (in_array($key, $this->allowedKeys)) {
                AppSetting::set($key, $value ?? '');
            }
        }

        return back()->with('success', 'Settings saved.');
    }
}
