<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Réponses des appels HTTP sortants en test (back-channel logout) : enregistre chaque requête
 * et répond 200, ou le code demandé par failWith().
 */
final class RecordingHttpClient
{
    /** @var list<array{method: string, url: string, body: string}> */
    public array $requests = [];
    private int $status = 200;

    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $body = $options['body'] ?? '';
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => \is_string($body) ? $body : http_build_query($body)];

        return new MockResponse('', ['http_code' => $this->status]);
    }

    public function failWith(int $status): void
    {
        $this->status = $status;
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->status = 200;
    }
}
