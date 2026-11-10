<?php

namespace App\Services\UniversalScraper;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LlmJsonClient
{
    /**
     * @return array<string, mixed>
     */
    public function primaryConfig(?string $device = null): array
    {
        $local = config('universal_scraper.local_llm');

        if (!empty($local['api_base']) && !empty($local['model'])) {
            $model = (string) $local['model'];
            $prefix = trim((string) ($local['model_prefix'] ?? ''));

            if (!str_contains($model, '/') && $prefix !== '') {
                $model = $prefix . $model;
            }

            return [
                'provider' => (string) config('universal_scraper.llm.provider', 'openai'),
                'api_base' => $this->resolveLocalApiBase((string) $local['api_base'], $device),
                'api_key' => (string) ($local['api_key'] ?: 'ollama'),
                'model' => $model,
                'temperature' => (float) config('universal_scraper.llm.temperature', 0),
                'model_tokens' => (int) ($local['model_tokens'] ?? 32768),
                'llm_device' => $this->normalizeDevice($device),
            ];
        }

        $apiKey = (string) config('universal_scraper.llm.api_key');

        if ($apiKey === '' && config('universal_scraper.llm.provider') !== 'ollama') {
            throw new RuntimeException('OPENAI_API_KEY is not set.');
        }

        return [
            'provider' => (string) config('universal_scraper.llm.provider', 'openai'),
            'api_base' => (string) config('universal_scraper.llm.api_base', 'https://api.openai.com/v1'),
            'api_key' => $apiKey,
            'model' => (string) config('universal_scraper.llm.model', 'gpt-4o-mini'),
            'temperature' => (float) config('universal_scraper.llm.temperature', 0),
            'model_tokens' => (int) config('universal_scraper.llm.model_tokens', 8192),
            'llm_device' => $this->normalizeDevice($device),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fallbackConfig(): ?array
    {
        $fallback = config('universal_scraper.fallback_llm');

        if (empty($fallback['api_key']) || empty($fallback['model'])) {
            return null;
        }

        return [
            'provider' => 'openai',
            'api_base' => (string) ($fallback['api_base'] ?: 'https://api.openai.com/v1'),
            'api_key' => (string) $fallback['api_key'],
            'model' => (string) $fallback['model'],
            'temperature' => (float) config('universal_scraper.llm.temperature', 0),
            'model_tokens' => (int) ($fallback['model_tokens'] ?? 8192),
            'llm_device' => 'fallback',
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function chatJson(string $prompt, array $config, ?int $maxTokens = null): mixed
    {
        $provider = strtolower((string) ($config['provider'] ?? config('universal_scraper.llm.provider', 'openai')));
        $apiPath = (string) config('universal_scraper.llm.api_path', '/v1/chat/completions');
        $endpoint = rtrim((string) ($config['api_base'] ?? 'https://api.openai.com/v1'), '/') . '/' . ltrim($apiPath, '/');
        $model = $this->stripModelPrefix((string) ($config['model'] ?? ''));
        $timeout = max(30, (int) config('universal_scraper.llm.timeout_seconds', 300));

        if ($provider === 'ollama' && !str_contains($apiPath, '/v1/')) {
            $payload = [
                'model' => $model,
                'messages' => $this->messages($prompt),
                'stream' => false,
                'options' => array_filter([
                    'temperature' => (float) ($config['temperature'] ?? 0),
                    'num_ctx' => $this->resolveOllamaNumCtx($config),
                ], fn ($value) => $value !== null),
            ];

            $keepAlive = (string) config('universal_scraper.llm.ollama_keep_alive', '');
            if ($keepAlive !== '') {
                $payload['keep_alive'] = $keepAlive;
            }

            $data = Http::timeout($timeout)->post($endpoint, $payload)->throw()->json();
            $content = is_array($data) && is_array($data['message'] ?? null) ? (string) ($data['message']['content'] ?? '') : '';

            return $this->parseJsonPayload($content);
        }

        $payload = [
            'model' => $model,
            'temperature' => (float) ($config['temperature'] ?? 0),
            'messages' => $this->messages($prompt),
        ];

        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }

        $request = Http::timeout($timeout)->acceptJson();
        $apiKey = (string) ($config['api_key'] ?? '');

        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        $data = $request->post($endpoint, $payload)->throw()->json();
        $content = $this->extractOpenAiContent($data);

        return $this->parseJsonPayload($content);
    }

    public function parseJsonPayload(string $raw): mixed
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = trim($text, "` \t\n\r\0\x0B");

        if ($text === '') {
            return [];
        }

        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $values = [];
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] !== '{' && $text[$i] !== '[') {
                continue;
            }

            $fragment = $this->extractBalancedJson($text, $i);

            if ($fragment === null) {
                continue;
            }

            $decoded = json_decode($fragment, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $values[] = $decoded;
            }
        }

        foreach ($values as $value) {
            if (is_array($value) && array_is_list($value) && collect($value)->contains(fn ($item) => is_array($item))) {
                return $value;
            }
        }

        foreach ($values as $value) {
            if (is_array($value) && !array_is_list($value)) {
                return $value;
            }
        }

        return $values[0] ?? [];
    }

    private function normalizeDevice(?string $device): string
    {
        $raw = strtolower(trim($device ?: (string) env('LLM_DEVICE', 'auto')));

        return in_array($raw, ['cpu', 'gpu', 'auto'], true) ? $raw : 'auto';
    }

    private function resolveLocalApiBase(string $apiBase, ?string $device): string
    {
        $device = $this->normalizeDevice($device);

        if ($device === 'cpu' && config('universal_scraper.local_llm.api_base_cpu')) {
            return (string) config('universal_scraper.local_llm.api_base_cpu');
        }

        if ($device === 'gpu' && config('universal_scraper.local_llm.api_base_gpu')) {
            return (string) config('universal_scraper.local_llm.api_base_gpu');
        }

        if ($device === 'auto') {
            return $apiBase;
        }

        $parts = parse_url($apiBase);
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? null;

        if ($device === 'cpu') {
            if ($host === 'llm_gpu') {
                $host = 'llm';
            }
            if ($port === 11435) {
                $port = 11434;
            }
        }

        if ($device === 'gpu') {
            if ($host === 'llm') {
                $host = 'llm_gpu';
            }
            if ($port === 11434 && !in_array($host, ['llm', 'llm_gpu'], true)) {
                $port = 11435;
            }
        }

        $scheme = $parts['scheme'] ?? 'http';
        $path = $parts['path'] ?? '';

        return $scheme . '://' . $host . ($port ? ':' . $port : '') . $path;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function messages(string $prompt): array
    {
        return [
            ['role' => 'system', 'content' => 'Return only valid JSON. No explanations.'],
            ['role' => 'user', 'content' => $prompt],
        ];
    }

    private function stripModelPrefix(string $model): string
    {
        if (str_starts_with($model, 'openai/') || str_starts_with($model, 'ollama/')) {
            return explode('/', $model, 2)[1];
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveOllamaNumCtx(array $config): ?int
    {
        $explicit = (int) config('universal_scraper.llm.ollama_num_ctx', 0);

        if ($explicit > 0) {
            return $explicit;
        }

        $tokens = (int) ($config['model_tokens'] ?? 0);

        return $tokens > 0 ? $tokens : null;
    }

    private function extractOpenAiContent(mixed $data): string
    {
        if (!is_array($data)) {
            return '';
        }

        $content = $data['choices'][0]['message']['content'] ?? '';

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            return implode("\n", array_filter(array_map(
                fn ($item) => is_array($item) && is_string($item['text'] ?? null) ? $item['text'] : null,
                $content,
            )));
        }

        return '';
    }

    private function extractBalancedJson(string $text, int $start): ?string
    {
        $stack = [];
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{' || $char === '[') {
                $stack[] = $char;
                continue;
            }

            if ($char === '}' || $char === ']') {
                $open = array_pop($stack);

                if (($char === '}' && $open !== '{') || ($char === ']' && $open !== '[')) {
                    return null;
                }

                if ($stack === []) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
