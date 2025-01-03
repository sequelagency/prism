<?php

declare(strict_types=1);

namespace EchoLabs\Prism\Providers\OpenAI\Handlers;

use EchoLabs\Prism\Exceptions\PrismException;
use EchoLabs\Prism\Providers\OpenAI\Maps\FinishReasonMap;
use EchoLabs\Prism\Providers\OpenAI\Maps\MessageMap;
use EchoLabs\Prism\Providers\OpenAI\Maps\ToolCallMap;
use EchoLabs\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use EchoLabs\Prism\Providers\OpenAI\Maps\ToolMap;
use EchoLabs\Prism\Providers\ProviderResponse;
use EchoLabs\Prism\Text\Request;
use EchoLabs\Prism\ValueObjects\Usage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

class Text
{
    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): ProviderResponse
    {
        try {
            if ($request->stream) {
                return $this->handleStream($request);
            } else {
                return $this->handleNonStream($request);
            }
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model, $e);
        }
    }

    protected function handleNonStream(Request $request): ProviderResponse
    {
        $response = $this->sendRequest($request);
        $data = $response->json();

        if (data_get($data, 'error') || ! $data) {
            throw PrismException::providerResponseError(
                vsprintf('OpenAI Error:  [%s] %s', [
                    data_get($data, 'error.type', 'unknown'),
                    data_get($data, 'error.message', 'unknown'),
                ])
            );
        }

        return new ProviderResponse(
            text: data_get($data, 'choices.0.message.content') ?? '',
            toolCalls: ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', [])),
            usage: new Usage(
                data_get($data, 'usage.prompt_tokens'),
                data_get($data, 'usage.completion_tokens'),
            ),
            finishReason: FinishReasonMap::map(data_get($data, 'choices.0.finish_reason', '')),
            response: [
                'id' => data_get($data, 'id'),
                'model' => data_get($data, 'model'),
            ]
        );
    }

    protected function handleStream(Request $request): ProviderResponse
    {
        $response = $this->sendStreamRequest($request);
        $body = $response->toPsrResponse()->getBody();
        $finalText = '';

        $promptTokens = 0;
        $completeTokens = 0;

        $providerKey = array_key_first($request->providerMeta);
        $conversationId = $request->providerMeta[$providerKey]['conversation_id'] ?? null;

        $buffer = '';
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk) {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    $line = trim($line);
                    if (str_starts_with($line, 'data:')) {
                        $jsonLine = trim(substr($line, 5));
                        if ($jsonLine === '[DONE]') {
                            break 2;
                        }

                        if (!empty($jsonLine)) {
                            $decoded = json_decode($jsonLine, true);
                            if (isset($decoded['choices'][0]['delta']['content'])) {
                                $finalText .= $decoded['choices'][0]['delta']['content'];

                                if ($conversationId) {
                                    broadcast(new \App\Events\PartialMessage($conversationId, $finalText));
                                }
                            }
                        }
                    }
                }
            }
        }

        $finishReason = \EchoLabs\Prism\Providers\OpenAI\Maps\FinishReasonMap::map('stop');

        return new \EchoLabs\Prism\Providers\ProviderResponse(
            text: $finalText,
            toolCalls: [],
            usage: new \EchoLabs\Prism\ValueObjects\Usage($promptTokens, $completeTokens),
            finishReason: $finishReason,
            response: []
        );
    }


    public function sendRequest(Request $request): Response
    {
        return $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model,
                'messages' => (new MessageMap($request->messages, $request->systemPrompt ?? ''))(),
                'max_completion_tokens' => $request->maxTokens ?? 2048,
            ], array_filter([
                'temperature' => $request->temperature,
                'top_p' => $request->topP,
                'tools' => ToolMap::map($request->tools),
                'tool_choice' => ToolChoiceMap::map($request->toolChoice),
            ]))
        );
    }

    public function sendStreamRequest(Request $request): Response
    {
        return $this->client
            ->withOptions(['stream' => true]) // Tell Laravel HTTP client to keep stream open
            ->post(
                'chat/completions',
                array_merge([
                    'model' => $request->model,
                    'messages' => (new MessageMap($request->messages, $request->systemPrompt ?? ''))(),
                    'max_completion_tokens' => $request->maxTokens ?? 2048,
                    'stream' => true,
                ], array_filter([
                    'temperature' => $request->temperature,
                    'top_p' => $request->topP,
                    'tools' => ToolMap::map($request->tools),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice),
                ]))
            );
    }
}
