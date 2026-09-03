<?php

namespace App\Infrastructure\AI;

use App\Application\DTOs\GradingRequest;
use App\Application\DTOs\GradingResult;
use App\Domain\Grading\Contracts\AiGradingProvider;
use App\Infrastructure\AI\Exceptions\PermanentAiException;
use App\Infrastructure\AI\Exceptions\RetryableAiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiGradingProvider implements AiGradingProvider
{
    public function __construct(
        private readonly PromptComposer $prompts = new PromptComposer,
        private readonly GradingResponseMapper $responses = new GradingResponseMapper,
    ) {}

    /** @throws ConnectionException */
    public function grade(GradingRequest $request): GradingResult
    {
        $key = config('services.gemini.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('GEMINI_API_KEY não configurada. A correção manual continua disponível.');
        }

        try {
            $response = Http::baseUrl(config('services.gemini.base_url'))
                ->withHeaders(['x-goog-api-key' => $key])
                ->acceptJson()->asJson()
                ->connectTimeout(config('services.gemini.connect_timeout'))
                ->timeout(config('services.gemini.timeout'))
                ->post('/models/'.rawurlencode(config('services.gemini.model')).':generateContent', [
                    'system_instruction' => [
                        'parts' => [['text' => $this->prompts->systemPrompt()]],
                    ],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [['text' => $this->prompts->input($request)]],
                    ]],
                    'generationConfig' => [
                        'maxOutputTokens' => config('services.gemini.max_output_tokens'),
                        'temperature' => config('services.gemini.temperature'),
                        'responseMimeType' => 'application/json',
                        'responseJsonSchema' => $this->responses->schema($request->maximumScore),
                    ],
                ]);
        } catch (ConnectionException $exception) {
            if (str_contains($exception->getMessage(), 'cURL error 60')) {
                throw new PermanentAiException('Falha ao validar o certificado SSL do Gemini. Verifique curl.cainfo e openssl.cafile no php.ini usado pelo servidor.', previous: $exception);
            }

            throw new RetryableAiException($exception->getMessage(), previous: $exception);
        }

        if (! $response->successful()) {
            $message = 'Falha do provedor Gemini: HTTP '.$response->status();
            if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
                throw new RetryableAiException($message);
            }

            throw new PermanentAiException($message);
        }

        $payload = $response->json();
        $data = json_decode($this->extractOutputText($payload), true, flags: JSON_THROW_ON_ERROR);

        return $this->responses->map(
            $data,
            $request,
            isset($payload['usageMetadata']['promptTokenCount']) ? (int) $payload['usageMetadata']['promptTokenCount'] : null,
            isset($payload['usageMetadata']['candidatesTokenCount']) ? (int) $payload['usageMetadata']['candidatesTokenCount'] : null,
        );
    }

    private function extractOutputText(array $payload): string
    {
        $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (is_string($text) && $text !== '') {
            return $text;
        }

        throw new PermanentAiException('A resposta do Gemini não continha o resultado estruturado.');
    }
}
