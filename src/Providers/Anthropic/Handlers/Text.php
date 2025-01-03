<?php

declare(strict_types=1);

namespace EchoLabs\Prism\Providers\Anthropic\Handlers;

use EchoLabs\Prism\Exceptions\PrismException;
use EchoLabs\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use EchoLabs\Prism\Providers\Anthropic\Maps\MessageMap;
use EchoLabs\Prism\Providers\Anthropic\Maps\ToolChoiceMap;
use EchoLabs\Prism\Providers\Anthropic\Maps\ToolMap;
use EchoLabs\Prism\Providers\ProviderResponse;
use EchoLabs\Prism\Text\Request;
use EchoLabs\Prism\ValueObjects\ToolCall;
use EchoLabs\Prism\ValueObjects\Usage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;
use Illuminate\Support\Facades\Log;

class Text
{
    public function __construct(
        protected PendingRequest $client
    ) {}

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

        if (data_get($data, 'type') === 'error') {
            throw PrismException::providerResponseError(
                vsprintf('Anthropic Error: [%s] %s', [
                    data_get($data, 'error.type', 'unknown'),
                    data_get($data, 'error.message'),
                ])
            );
        }

        return new ProviderResponse(
            text: $this->extractText($data),
            toolCalls: $this->extractToolCalls($data),
            usage: new Usage(
                data_get($data, 'usage.input_tokens', 0),
                data_get($data, 'usage.output_tokens', 0),
            ),
            finishReason: FinishReasonMap::map(data_get($data, 'stop_reason', '')),
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
        $providerKey = array_key_first($request->providerMeta);
        $conversationId = $request->providerMeta[$providerKey]['conversation_id'] ?? null;

        $promptTokens = 0;
        $completeTokens = 0;

        $buffer = '';
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk) {
                $buffer .= $chunk;

                // Process all lines that are delimited by "\n"
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    $line = trim($line);

                    // We only care about lines that start with "data:"
                    // The preceding "event: ..." line can be ignored or used for debugging.
                    if (str_starts_with($line, 'data:')) {
                        $jsonLine = trim(substr($line, 5));
                        if (empty($jsonLine)) {
                            continue;
                        }

                        // Attempt to decode the JSON
                        $decoded = json_decode($jsonLine, true);
                        if (! is_array($decoded)) {
                            // Not valid JSON or empty
                            continue;
                        }

                        // Get prompt tokens
                        if ($decoded['type'] == 'message_start') {
                            $promptTokens = $decoded['message']['usage']['input_tokens'];
                        }
                        if (isset($decoded['usage']['output_tokens'])) {
                            $completeTokens = $decoded['usage']['output_tokens'];
                        }


                        // 1) If type === 'content_block_delta', partial text is in delta.text
                        if (($decoded['type'] ?? null) === 'content_block_delta') {
                            $partial = data_get($decoded, 'delta.text');
                            if (! empty($partial)) {
                                $finalText .= $partial;

                                // Broadcast partial text if you want
                                if ($conversationId) {
                                    broadcast(new \App\Events\PartialMessage($conversationId, $finalText));
                                }
                            }
                        }

                        // 2) If type === 'message_end', we've reached the end of the streaming
                        if (($decoded['type'] ?? null) === 'message_end') {
                            // We can break out of both loops
                            break 2;
                        }

                        // 3) If type === 'error', you might want to handle it
                        if (($decoded['type'] ?? null) === 'error') {
                            throw PrismException::providerResponseError(
                                "Anthropic SSE error: ".($decoded['message'] ?? 'Unknown')
                            );
                        }

                        // 4) If type === 'ping' or 'message_start', just ignore
                    }
                }
            }
        }

        // If we ended normally, set finishReason to "stop"
        $finishReason = FinishReasonMap::map('stop');

        return new ProviderResponse(
            text: $finalText,
            toolCalls: [],
            usage: new Usage($promptTokens, $completeTokens),
            finishReason: $finishReason,
            response: []
        );
    }

    public function sendRequest(Request $request): Response
    {
        return $this->client->post(
            'messages',
            array_merge([
                'model' => $request->model,
                'messages' => MessageMap::map($request->messages),
                'max_tokens' => $request->maxTokens ?? 2048,
            ], array_filter([
                'system' => MessageMap::mapSystemMessages($request->messages, $request->systemPrompt),
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
            ->withOptions(['stream' => true])  // keep the HTTP stream open
            ->post(
                'messages',
                array_merge([
                    'model' => $request->model,
                    'messages' => MessageMap::map($request->messages),
                    'max_tokens' => $request->maxTokens ?? 2048,
                    'stream' => true, // important for Anthropic SSE
                ], array_filter([
                    'system' => MessageMap::mapSystemMessages($request->messages, $request->systemPrompt),
                    'temperature' => $request->temperature,
                    'top_p' => $request->topP,
                    'tools' => ToolMap::map($request->tools),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice),
                ]))
            );
    }

    protected function extractText(array $data): string
    {
        return array_reduce(data_get($data, 'content', []), function (string $text, array $content): string {
            if (data_get($content, 'type') === 'text') {
                $text .= data_get($content, 'text');
            }

            return $text;
        }, '');
    }

    protected function extractToolCalls(array $data): array
    {
        $toolCalls = array_map(function ($content) {
            if (data_get($content, 'type') === 'tool_use') {
                return new ToolCall(
                    id: data_get($content, 'id'),
                    name: data_get($content, 'name'),
                    arguments: data_get($content, 'input')
                );
            }
        }, data_get($data, 'content', []));

        return array_values(array_filter($toolCalls));
    }
}
