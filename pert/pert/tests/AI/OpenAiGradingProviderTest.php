<?php

namespace Tests\AI;

use App\Application\DTOs\GradingRequest;
use App\Infrastructure\AI\OpenAiGradingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiGradingProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_structured_stateless_anonymized_request(): void
    {
        config(['services.openai.key' => 'test-key', 'services.openai.base_url' => 'https://api.openai.test/v1']);
        Http::fake(['api.openai.test/*' => Http::response([
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'score' => 8.5, 'criterion_scores' => [['criterion' => 'Clareza', 'score' => 8.5, 'justification' => 'Correto']],
                'evidence' => ['Trecho relevante'], 'feedback' => 'Boa resposta.', 'confidence' => .9, 'warnings' => [],
            ])]]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ], 200)]);

        $result = app(OpenAiGradingProvider::class)->grade(new GradingRequest('Pergunta', 'Esperado', [['label' => 'Clareza', 'weight' => 1]], 'Resposta sem nome', 'Seja objetivo', 10, 1, 'pt-BR', str_repeat('a', 64), str_repeat('b', 64)));
        $this->assertSame(8.5, $result->score);
        Http::assertSent(function (Request $request) {
            $data = $request->data();
            return $request->url() === 'https://api.openai.test/v1/responses'
                && $data['store'] === false
                && $data['model'] === 'gpt-5.6-terra'
                && $data['text']['format']['type'] === 'json_schema'
                && ! str_contains($data['input'], '@');
        });
    }
}
