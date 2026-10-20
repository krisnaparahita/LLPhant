<?php

namespace Tests\Unit\Embeddings\VectorStores\Meilisearch;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\VectorStores\Meilisearch\LLPhantMeilisearchClient;
use LLPhant\Embeddings\VectorStores\Meilisearch\MeilisearchVectorStore;
use LLPhant\Exception\MissingParameterException;

/**
 * @param  Response[]  $responses
 * @param  array<int, array{request: \Psr\Http\Message\RequestInterface}>  $history
 */
function meilisearchStore(array $responses, array &$history): MeilisearchVectorStore
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $client = new LLPhantMeilisearchClient('http://meili.test', 'secret', new Client(['handler' => $stack]));

    return new MeilisearchVectorStore('docs', $client);
}

function meilisearchDocument(): Document
{
    $document = new Document();
    $document->content = 'hello';
    $document->hash = 'abc';
    $document->sourceName = 'file.txt';
    $document->sourceType = 'files';
    $document->chunkNumber = 2;
    $document->embedding = [0.1, 0.2, 0.3];

    return $document;
}

it('creates an index with a user provided embedder', function () {
    $history = [];
    $store = meilisearchStore([
        new Response(404),
        new Response(202, [], '{"taskUid":1}'),
        new Response(200, [], '{"status":"succeeded"}'),
        new Response(202, [], '{"taskUid":2}'),
        new Response(200, [], '{"status":"succeeded"}'),
    ], $history);

    expect($store->createIndexIfDoesNotExist('docs', 3))->toBeFalse();

    $requests = array_column($history, 'request');
    expect($requests[1]->getMethod())->toBe('POST')
        ->and((string) $requests[1]->getUri())->toBe('http://meili.test/indexes')
        ->and($requests[1]->getHeaderLine('Authorization'))->toBe('Bearer secret')
        ->and((string) $requests[3]->getUri())->toBe('http://meili.test/indexes/docs/settings/embedders')
        ->and(json_decode((string) $requests[3]->getBody(), true))
        ->toBe(['default' => ['source' => 'userProvided', 'dimensions' => 3]]);
});

it('does not recreate an existing index', function () {
    $history = [];
    $store = meilisearchStore([new Response(200, [], '{"uid":"docs"}')], $history);

    expect($store->createIndexIfDoesNotExist('docs', 3))->toBeTrue()
        ->and($history)->toHaveCount(1);
});

it('adds documents with their vectors', function () {
    $history = [];
    $store = meilisearchStore([
        new Response(202, [], '{"taskUid":7}'),
        new Response(200, [], '{"status":"succeeded"}'),
    ], $history);

    $store->addDocument(meilisearchDocument());

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect((string) $history[0]['request']->getUri())->toBe('http://meili.test/indexes/docs/documents')
        ->and($body)->toHaveCount(1)
        ->and($body[0]['_vectors'])->toBe(['default' => [0.1, 0.2, 0.3]])
        ->and($body[0]['content'])->toBe('hello')
        ->and($body[0]['chunkNumber'])->toBe(2);
});

it('refuses documents without embedding', function () {
    $history = [];
    $store = meilisearchStore([], $history);
    $document = meilisearchDocument();
    $document->embedding = null;

    $store->addDocument($document);
})->throws(MissingParameterException::class);

it('runs a semantic search and maps hits to documents', function () {
    $history = [];
    $hit = ['content' => 'hello', 'hash' => 'abc', 'sourceName' => 'file.txt', 'sourceType' => 'files', 'chunkNumber' => 2];
    $store = meilisearchStore([new Response(200, [], json_encode(['hits' => [$hit]]))], $history);

    $results = $store->similaritySearch([0.1, 0.2, 0.3], 5);

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['limit'])->toBe(5)
        ->and($body['vector'])->toBe([0.1, 0.2, 0.3])
        ->and($body['hybrid'])->toBe(['semanticRatio' => 1, 'embedder' => 'default'])
        ->and($results)->toHaveCount(1)
        ->and($results[0]->content)->toBe('hello')
        ->and($results[0]->chunkNumber)->toBe(2);
});
