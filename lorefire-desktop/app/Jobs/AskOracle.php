<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\OracleReply;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AskOracle implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    protected ?string $lastError = null;

    public function __construct(
        public OracleReply $reply,
        public string $systemPrompt,
        public array $messages,
    ) {}

    public function handle(): void
    {
        try {
            $provider = AppSetting::get('llm_provider', 'none');

            $text = match ($provider) {
                'openai' => $this->callOpenAI(),
                'anthropic' => $this->callAnthropic(),
                'ollama' => $this->callOllama(),
                'zai' => $this->callZai(),
                default => $this->rejectUnknownProvider((string) $provider),
            };

            if ($text === null || trim($text) === '') {
                $this->markFailed($this->lastError ?? $this->emptyProviderMessage((string) $provider));

                return;
            }

            $this->reply->update(['status' => 'done', 'reply' => trim($text)]);
        } catch (Throwable $e) {
            $this->markFailed('Oracle request failed: '.$e->getMessage(), $e);
        }
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed('Oracle request failed: '.$e->getMessage(), $e);
    }

    protected function rejectUnknownProvider(string $provider): ?string
    {
        $this->lastError = $provider === 'none' || $provider === ''
            ? 'No LLM provider configured. Set one in Settings.'
            : 'Unknown LLM provider "'.$provider.'". Set a supported provider in Settings.';

        return null;
    }

    protected function emptyProviderMessage(string $provider): string
    {
        return 'The '.$provider.' provider returned no text. Check Settings, the model name, and application logs.';
    }

    protected function markFailed(string $message, ?Throwable $e = null): void
    {
        Log::error('AskOracle failed', [
            'reply_id' => $this->reply->id,
            'error' => $message,
            'exception' => $e?->getMessage(),
        ]);

        $this->reply->refresh();
        if ($this->reply->status === 'done' && filled($this->reply->reply)) {
            return;
        }

        $this->reply->update([
            'status' => 'failed',
            'reply' => $message,
        ]);
    }

    // ── Providers ──────────────────────────────────────────────────────────

    protected function callZai(): ?string
    {
        $key = AppSetting::get('zai_api_key');
        $model = AppSetting::get('zai_model', 'glm-4.6');
        $baseUrl = AppSetting::get('zai_base_url', AppSetting::ZAI_CODING_URL);

        if (! $key) {
            $this->lastError = 'z.ai is selected but no API key is configured. Set one in Settings.';

            return null;
        }

        $payload = [
            'model' => $model,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $this->systemPrompt]],
                $this->messages
            ),
            'max_tokens' => 4000,
        ];

        if (str_contains($model, '4.7') || str_contains($model, '4-7')) {
            $payload['thinking'] = ['type' => 'enabled'];
            $payload['max_tokens'] = 8000;
        }

        $response = Http::withToken($key)
            ->timeout(240)
            ->post(rtrim($baseUrl, '/').'/chat/completions', $payload);

        if (! $response->successful()) {
            $this->lastError = 'z.ai returned HTTP '.$response->status().'. Check Settings and logs.';
            Log::warning('AskOracle: z.ai error', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        $content = $response->json('choices.0.message.content') ?? '';

        if (is_array($content)) {
            $content = collect($content)->where('type', 'text')->pluck('text')->implode("\n");
        }

        $text = trim((string) $content);
        if ($text === '') {
            $this->lastError = 'z.ai returned an empty reply.';

            return null;
        }

        return $text;
    }

    protected function callOpenAI(): ?string
    {
        $key = AppSetting::get('openai_api_key');
        if (! $key) {
            $this->lastError = 'OpenAI is selected but no API key is configured. Set one in Settings.';

            return null;
        }

        $response = Http::withToken($key)
            ->timeout(120)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => array_merge(
                    [['role' => 'system', 'content' => $this->systemPrompt]],
                    $this->messages
                ),
                'max_tokens' => 2000,
            ]);

        if (! $response->successful()) {
            $this->lastError = 'OpenAI returned HTTP '.$response->status().'. Check Settings and logs.';
            Log::warning('AskOracle: OpenAI error', ['status' => $response->status()]);

            return null;
        }

        $text = trim((string) ($response->json('choices.0.message.content') ?? ''));
        if ($text === '') {
            $this->lastError = 'OpenAI returned an empty reply.';

            return null;
        }

        return $text;
    }

    protected function callAnthropic(): ?string
    {
        $key = AppSetting::get('anthropic_api_key');
        if (! $key) {
            $this->lastError = 'Anthropic is selected but no API key is configured. Set one in Settings.';

            return null;
        }

        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(120)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-3-haiku-20240307',
                'max_tokens' => 2000,
                'system' => $this->systemPrompt,
                'messages' => $this->messages,
            ]);

        if (! $response->successful()) {
            $this->lastError = 'Anthropic returned HTTP '.$response->status().'. Check Settings and logs.';
            Log::warning('AskOracle: Anthropic error', ['status' => $response->status()]);

            return null;
        }

        $text = trim((string) ($response->json('content.0.text') ?? ''));
        if ($text === '') {
            $this->lastError = 'Anthropic returned an empty reply.';

            return null;
        }

        return $text;
    }

    protected function callOllama(): ?string
    {
        $baseUrl = AppSetting::get('ollama_base_url', 'http://localhost:11434');
        $model = AppSetting::get('ollama_model', 'llama3');

        $response = Http::timeout(240)
            ->post("{$baseUrl}/api/chat", \App\Support\Linux5090::withOllamaOptions([
                'model' => $model,
                'stream' => false,
                'messages' => array_merge(
                    [['role' => 'system', 'content' => $this->systemPrompt]],
                    $this->messages
                ),
            ]));

        if (! $response->successful()) {
            $this->lastError = 'Ollama returned HTTP '.$response->status()
                .' from '.$baseUrl.' (model '.$model.'). Check that Ollama is running and the model is pulled.';
            Log::warning('AskOracle: Ollama error', [
                'status' => $response->status(),
                'base_url' => $baseUrl,
                'model' => $model,
                'body' => $response->body(),
            ]);

            return null;
        }

        $text = trim((string) ($response->json('message.content') ?? ''));
        if ($text === '') {
            $this->lastError = 'Ollama returned an empty reply from '.$baseUrl.' (model '.$model.').';

            return null;
        }

        return $text;
    }
}
