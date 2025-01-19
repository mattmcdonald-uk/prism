<?php

declare(strict_types=1);

use EchoLabs\Prism\Providers\DeepSeek\Maps\MessageMap;
use EchoLabs\Prism\ValueObjects\Messages\AssistantMessage;
use EchoLabs\Prism\ValueObjects\Messages\Support\Image;
use EchoLabs\Prism\ValueObjects\Messages\ToolResultMessage;
use EchoLabs\Prism\ValueObjects\Messages\UserMessage;
use EchoLabs\Prism\ValueObjects\ToolCall;
use EchoLabs\Prism\ValueObjects\ToolResult;

it('maps user messages', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?'),
        ],
        systemPrompt: ''
    );

    expect($messageMap())->toBe([[
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'Who are you?'],
        ],
    ]]);
});

it('maps user messages with images from path', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', [
                Image::fromPath('tests/Fixtures/test-image.png'),
            ]),
        ],
        systemPrompt: ''
    );

    $mappedMessage = $messageMap();

    expect(data_get($mappedMessage, '0.content.1.type'))
        ->toBe('image_url');
    expect(data_get($mappedMessage, '0.content.1.image_url.url'))
        ->toStartWith('data:image/png;base64,');
    expect(data_get($mappedMessage, '0.content.1.image_url.url'))
        ->toContain(base64_encode(file_get_contents('tests/Fixtures/test-image.png')));
});

it('maps user messages with images from base64', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', [
                Image::fromBase64(base64_encode(file_get_contents('tests/Fixtures/test-image.png')), 'image/png'),
            ]),
        ],
        systemPrompt: ''
    );

    $mappedMessage = $messageMap();

    expect(data_get($mappedMessage, '0.content.1.type'))
        ->toBe('image_url');
    expect(data_get($mappedMessage, '0.content.1.image_url.url'))
        ->toStartWith('data:image/png;base64,');
    expect(data_get($mappedMessage, '0.content.1.image_url.url'))
        ->toContain(base64_encode(file_get_contents('tests/Fixtures/test-image.png')));
});

it('maps user messages with images from url', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new UserMessage('Who are you?', [
                Image::fromUrl('https://storage.echolabs.dev/assets/logo.png'),
            ]),
        ],
        systemPrompt: ''
    );

    $mappedMessage = $messageMap();

    expect(data_get($mappedMessage, '0.content.1.type'))
        ->toBe('image_url');
    expect(data_get($mappedMessage, '0.content.1.image_url.url'))
        ->toBe('https://storage.echolabs.dev/assets/logo.png');
});

it('maps assistant message', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('I am Nyx'),
        ],
        systemPrompt: ''
    );

    expect($messageMap())->toContain([
        'role' => 'assistant',
        'content' => 'I am Nyx',
    ]);
});

it('maps assistant message with tool calls', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new AssistantMessage('I am Nyx', [
                new ToolCall(
                    'tool_1234',
                    'search',
                    [
                        'query' => 'Laravel collection methods',
                    ]
                ),
            ]),
        ],
        systemPrompt: ''
    );

    expect($messageMap())->toBe([[
        'role' => 'assistant',
        'content' => 'I am Nyx',
        'tool_calls' => [[
            'id' => 'tool_1234',
            'type' => 'function',
            'function' => [
                'name' => 'search',
                'arguments' => json_encode([
                    'query' => 'Laravel collection methods',
                ]),
            ],
        ]],
    ]]);
});

it('maps tool result messages', function (): void {
    $messageMap = new MessageMap(
        messages: [
            new ToolResultMessage([
                new ToolResult(
                    'tool_1234',
                    'search',
                    [
                        'query' => 'Laravel collection methods',
                    ],
                    '[search results]'
                ),
            ]),
        ],
        systemPrompt: ''
    );

    expect($messageMap())->toBe([[
        'role' => 'tool',
        'tool_call_id' => 'tool_1234',
        'content' => '[search results]',
    ]]);
});

it('maps system prompt', function (): void {
    $messageMap = new MessageMap(
        messages: [],
        systemPrompt: 'MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]'
    );

    expect($messageMap())->toContain([
        'role' => 'system',
        'content' => 'MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]',
    ]);
});