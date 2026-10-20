<?php

namespace LLPhant\Embeddings\VectorStores\Meilisearch;

use Http\Discovery\Psr17Factory;
use Http\Discovery\Psr18ClientDiscovery;
use LLPhant\Utility;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Minimal Meilisearch REST client, limited to what the vector store needs.
 */
class LLPhantMeilisearchClient
{
    protected readonly ClientInterface $client;

    protected readonly StreamFactoryInterface&RequestFactoryInterface $factory;

    protected readonly string $baseUri;

    protected readonly ?string $apiKey;

    public function __construct(
        ?string $host = null,
        ?string $apiKey = null,
        ?ClientInterface $client = null
    ) {
        $this->baseUri = $host ?? (string) Utility::readEnvironment('MEILISEARCH_HOST', 'http://localhost:7700');
        $apiKey ??= Utility::readEnvironment('MEILISEARCH_API_KEY');
        $this->apiKey = $apiKey ? (string) $apiKey : null;
        $this->client = $client ?? Psr18ClientDiscovery::find();
        $this->factory = new Psr17Factory;
    }

    public function indexExists(string $uid): bool
    {
        $response = $this->sendRequest('GET', '/indexes/'.rawurlencode($uid));
        if ($response->getStatusCode() === 404) {
            return false;
        }
        $this->assertSuccess($response);

        return true;
    }

    public function createIndex(string $uid, int $embeddingLength, string $embedderName): void
    {
        $this->waitForTask($this->sendRequest('POST', '/indexes', ['uid' => $uid, 'primaryKey' => 'id']));
        $this->waitForTask($this->sendRequest('PATCH', '/indexes/'.rawurlencode($uid).'/settings/embedders', [
            $embedderName => ['source' => 'userProvided', 'dimensions' => $embeddingLength],
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     */
    public function addDocuments(string $uid, array $points): void
    {
        $this->waitForTask($this->sendRequest('POST', '/indexes/'.rawurlencode($uid).'/documents', $points));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function search(string $uid, array $query): array
    {
        $response = $this->sendRequest('POST', '/indexes/'.rawurlencode($uid).'/search', $query);
        $this->assertSuccess($response);

        return \json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Meilisearch processes writes asynchronously: poll the enqueued task until it ends.
     */
    protected function waitForTask(ResponseInterface $response, int $timeoutSeconds = 30): void
    {
        $this->assertSuccess($response);
        $task = \json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $taskUid = $task['taskUid'] ?? null;
        if ($taskUid === null) {
            return;
        }

        $deadline = time() + $timeoutSeconds;
        do {
            $status = \json_decode((string) $this->sendRequest('GET', '/tasks/'.$taskUid)->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $state = $status['status'] ?? null;
            if ($state === 'succeeded') {
                return;
            }
            if ($state === 'failed' || $state === 'canceled') {
                throw new \Exception('Meilisearch task '.$taskUid.' '.$state.': '.json_encode($status['error'] ?? null));
            }
            usleep(50_000);
        } while (time() < $deadline);

        throw new \Exception('Timed out waiting for Meilisearch task '.$taskUid);
    }

    protected function assertSuccess(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \Exception('Meilisearch API error ('.$status.'): '.(string) $response->getBody());
        }
    }

    /**
     * @param  array<mixed>|null  $body
     */
    protected function sendRequest(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $url = sprintf('%s/%s', rtrim($this->baseUri, '/'), ltrim($path, '/'));

        $request = $this->factory->createRequest($method, $url)
            ->withHeader('Accept', 'application/json');

        if ($this->apiKey !== null) {
            $request = $request->withHeader('Authorization', 'Bearer '.$this->apiKey);
        }
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->client->sendRequest($request);
    }
}
