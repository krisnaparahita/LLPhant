<?php

namespace LLPhant\Embeddings\VectorStores\Meilisearch;

use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\DocumentUtils;
use LLPhant\Embeddings\VectorStores\VectorStoreBase;
use LLPhant\Exception\MissingParameterException;

class MeilisearchVectorStore extends VectorStoreBase
{
    final public const MEILISEARCH_EMBEDDER_NAME = 'default';

    public function __construct(
        private readonly string $indexName,
        private readonly LLPhantMeilisearchClient $client = new LLPhantMeilisearchClient(),
        private readonly string $embedderName = self::MEILISEARCH_EMBEDDER_NAME,
    ) {
    }

    /**
     * @param  int  $embeddingLength  this depends on the embedding generator you use
     */
    public function createIndexIfDoesNotExist(string $name, int $embeddingLength): bool
    {
        if ($this->client->indexExists($name)) {
            return true;
        }
        $this->createIndex($name, $embeddingLength);

        return false;
    }

    /**
     * @param  int  $embeddingLength  this depends on the embedding generator you use
     */
    public function createIndex(string $name, int $embeddingLength): void
    {
        $this->client->createIndex($name, $embeddingLength, $this->embedderName);
    }

    /**
     * @throws MissingParameterException
     */
    public function addDocument(Document $document): void
    {
        $this->addDocuments([$document]);
    }

    /**
     * @throws MissingParameterException
     */
    public function addDocuments(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $points = [];
        foreach ($documents as $document) {
            $points[] = $this->createPointFromDocument($document);
        }

        $this->client->addDocuments($this->indexName, $points);
    }

    /**
     * @param  float[]  $embedding
     * @param  array<string, string|int>|array<mixed[]>  $additionalArguments  extra Meilisearch search parameters, e.g. ['filter' => 'sourceType = "files"']
     * @return Document[]
     */
    public function similaritySearch(array $embedding, int $k = 4, array $additionalArguments = []): array
    {
        $query = $additionalArguments + [
            'vector' => $embedding,
            'hybrid' => ['semanticRatio' => 1.0, 'embedder' => $this->embedderName],
            'limit' => $k,
            'attributesToRetrieve' => ['content', 'hash', 'sourceName', 'sourceType', 'chunkNumber'],
        ];

        $response = $this->client->search($this->indexName, $query);

        $documents = [];
        foreach ($response['hits'] ?? [] as $hit) {
            $document = new Document();
            $document->content = $hit['content'];
            $document->hash = $hit['hash'];
            $document->sourceName = $hit['sourceName'];
            $document->sourceType = $hit['sourceType'];
            $document->chunkNumber = $hit['chunkNumber'];
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MissingParameterException
     */
    private function createPointFromDocument(Document $document): array
    {
        if ($document->embedding === null) {
            throw new MissingParameterException('It is impossible to save a document without its vectors. You need to call an embeddingGenerator: $embededDocuments = $embeddingGenerator->embedDocuments($formattedDocuments);');
        }

        return [
            'id' => $this->getId($document),
            '_vectors' => [$this->embedderName => $document->embedding],
            'content' => $document->content,
            'hash' => $document->hash,
            'sourceName' => $document->sourceName,
            'sourceType' => $document->sourceType,
            'chunkNumber' => $document->chunkNumber,
        ];
    }

    protected function getId(Document $document): string
    {
        return \hash('sha256', $document->content.DocumentUtils::getUniqueId($document));
    }
}
