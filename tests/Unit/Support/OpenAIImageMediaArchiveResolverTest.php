<?php

declare(strict_types=1);

use Spora\Plugins\OpenAIImage\Support\OpenAIImageMediaArchiveResolver;

const RESOLVER_TEST_UUID    = '0d4f3c70-1234-5678-9abc-deadbeef0000';
const RESOLVER_TEST_PNG_MIME = 'image/png';
const RESOLVER_TEST_PNG_BYTES = 'PNGBYTES';

function resolver(): OpenAIImageMediaArchiveResolver
{
    return new OpenAIImageMediaArchiveResolver(
        static fn(string $id, ?int $userId) => null,
    );
}

function resolverWithReader(Closure $reader): OpenAIImageMediaArchiveResolver
{
    return new OpenAIImageMediaArchiveResolver($reader);
}

it('passes through a non-Media-Archive URL untouched', function () {
    $url = 'https://example.com/seed.png';
    $result = resolver()->resolveInputImage($url, 1);
    expect($result)->toBe(['resolved' => $url]);
});

it('passes through a data: URI untouched', function () {
    $dataUri = 'data:image/png;base64,AAAA';
    $result = resolver()->resolveInputImage($dataUri, 1);
    expect($result)->toBe(['resolved' => $dataUri]);
});

it('resolves a bare 36-char UUID via the reader and returns a data: URI', function () {
    $resolver = resolverWithReader(static function (string $id, ?int $userId): array {
        expect($id)->toBe(RESOLVER_TEST_UUID);
        expect($userId)->toBe(7);
        return ['status' => 'data_url', 'bytes' => RESOLVER_TEST_PNG_BYTES, 'mime' => RESOLVER_TEST_PNG_MIME];
    });

    $result = $resolver->resolveInputImage(RESOLVER_TEST_UUID, 7);
    expect($result)->toBe(['resolved' => 'data:image/png;base64,' . base64_encode(RESOLVER_TEST_PNG_BYTES)]);
});

it('resolves an opaque /api/v1/assets/<uuid>.<ext> URL and strips the extension', function () {
    $opaque = '/api/v1/assets/' . RESOLVER_TEST_UUID . '.png';
    $resolver = resolverWithReader(static function (string $id): array {
        expect($id)->toBe(RESOLVER_TEST_UUID);
        return ['status' => 'data_url', 'bytes' => RESOLVER_TEST_PNG_BYTES, 'mime' => RESOLVER_TEST_PNG_MIME];
    });

    $result = $resolver->resolveInputImage($opaque, 1);
    expect($result)->toBe(['resolved' => 'data:image/png;base64,' . base64_encode(RESOLVER_TEST_PNG_BYTES)]);
});

it('forwards an external source URL verbatim', function () {
    $url = 'https://cdn.example.com/seed.png';
    $resolver = resolverWithReader(static fn(string $id): array => [
        'status' => 'external',
        'sourceUrl' => $url,
    ]);

    $result = $resolver->resolveInputImage(RESOLVER_TEST_UUID, 1);
    expect($result)->toBe(['resolved' => $url]);
});

it('returns a failed ToolResult when the reader returns null (asset not found)', function () {
    $resolver = resolverWithReader(static fn(): ?array => null);

    $result = $resolver->resolveInputImage(RESOLVER_TEST_UUID, 1);
    expect($result)->toHaveKey('failed');
    expect($result['failed']->success)->toBeFalse();
    expect($result['failed']->content)->toContain('not found');
    expect($result['failed']->content)->toContain(RESOLVER_TEST_UUID);
});

it('returns a failed ToolResult when the reader returns an unknown status', function () {
    $resolver = resolverWithReader(static fn(): array => ['status' => 'legacy']);

    $result = $resolver->resolveInputImage(RESOLVER_TEST_UUID, 1);
    expect($result)->toHaveKey('failed');
});

it('rejects a 30 MB raw payload (over the 25 MB data URI cap)', function () {
    $resolver = resolverWithReader(static fn(): array => [
        'status' => 'data_url',
        'bytes' => str_repeat('A', 30 * 1024 * 1024),
        'mime' => RESOLVER_TEST_PNG_MIME,
    ]);

    $result = $resolver->resolveInputImage(RESOLVER_TEST_UUID, 1);
    expect($result)->toHaveKey('failed');
    expect($result['failed']->content)->toContain('25 MB');
});

it('accepts an 18 MB raw payload (under the 25 MB data URI cap)', function () {
    $payload = str_repeat('A', 18 * 1024 * 1024);
    $resolver = resolverWithReader(static fn(): array => [
        'status' => 'data_url',
        'bytes' => $payload,
        'mime' => RESOLVER_TEST_PNG_MIME,
    ]);

    $result = $resolver->resolveInputImage(RESOLVER_TEST_UUID, 1);
    expect($result)->toHaveKey('resolved');
    expect(strlen($result['resolved']))->toBeGreaterThan(18 * 1024 * 1024);
});
