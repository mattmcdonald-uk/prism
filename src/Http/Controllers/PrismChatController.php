<?php

namespace EchoLabs\Prism\Http\Controllers;

use EchoLabs\Prism\Contracts\Message;
use EchoLabs\Prism\Exceptions\PrismServerException;
use EchoLabs\Prism\Facades\PrismServer;
use EchoLabs\Prism\Generators\TextGenerator;
use EchoLabs\Prism\Responses\TextResponse;
use EchoLabs\Prism\ValueObjects\Messages\AssistantMessage;
use EchoLabs\Prism\ValueObjects\Messages\UserMessage;
use Illuminate\Support\ItemNotFoundException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PrismChatController
{
    public function __invoke(): Response
    {
        request()->validate([
            'stream' => 'sometimes|boolean',
            'model' => 'string|required',
            'messages' => 'sometimes|array',
        ]);

        try {
            /** @var array<array{role: string, content: string}> $messages */
            $messages = request('messages');

            $prism = $this->resolvePrism(request('model'));

            $prism->withMessages($this->mapMessages($messages));

            if (request('stream')) {
                return $this->stream($prism);
            }

            return $this->chat($prism);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    protected function stream(TextGenerator $generator): Response
    {
        return response()->stream(function () use ($generator): void {
            $response = $generator();

            $chunk = [
                'id' => $response->response['id'],
                'object' => 'chat.completion.chunk',
                'created' => now()->timestamp,
                'model' => $response->response['model'],
                'choices' => [[
                    'delta' => [
                        'role' => 'assistant',
                        'content' => $this->textFromResponse($response),
                    ],
                ]],
            ];

            echo 'data: '.json_encode($chunk)."\n\n";
            echo "data: [DONE]\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
    }

    protected function error(Throwable $e): Response
    {
        return response()->json([
            'error' => [
                'message' => $e->getMessage(),
            ],
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function chat(TextGenerator $generator): Response
    {
        $response = $generator();

        $data = [
            'id' => $response->response['id'],
            'object' => 'chat.completion',
            'created' => now()->timestamp,
            'model' => $response->response['model'],
            'usage' => [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
                'total_tokens' => $response->usage->promptTokens
                        + $response->usage->completionTokens,
            ],
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'content' => $this->textFromResponse($response),
                        'role' => 'assistant',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];

        return response()->json($data);
    }

    protected function textFromResponse(TextResponse $response): string
    {
        return $response->responseMessages
            ->filter(fn (Message $message): bool => $message instanceof AssistantMessage)
            ->implode(fn (Message $message): string => $message->content(), "\n");
    }

    /**
     * @param  array<int, mixed>  $messages
     * @return array<int, UserMessage|AssistantMessage>
     */
    protected function mapMessages($messages): array
    {
        return collect($messages)
            ->map(fn ($message): UserMessage|AssistantMessage => match ($message['role']) {
                'user' => new UserMessage($message['content']),
                'assistant' => new AssistantMessage($message['content']),
                default => throw new PrismServerException("Couldn't map messages to Prism messages")
            })
            ->toArray();
    }

    protected function resolvePrism(string $model): TextGenerator
    {
        try {
            $prism = PrismServer::prisms()
                ->sole('name', $model);
        } catch (ItemNotFoundException $e) {
            throw PrismServerException::unresolveableModel($model, $e);
        }

        return $prism['prism']();
    }
}