# Vector Driver for Kipchak

A vector database driver for Kipchak: store embeddings and search them by similarity, for semantic search,
recommendations and retrieval-augmented generation (RAG). One API over three stores:

* **Qdrant**, over its REST API.
* **PostgreSQL with pgvector**, on a connection of the [Doctrine driver](https://kipchak.dev/docs/driver-doctrine),
  so your vectors can live beside your data.
* **OpenSearch** with its k-NN plugin.

Moving between them is a configuration change: the same calls, filters and scores work on all three, and the
driver's tests run one shared suite against each.

* **Collections**: create, check, delete and describe.
* **Points**: upsert in batches, get by ID, delete by ID or by filter, count.
* **Search**: the closest points to a vector, with payload filters (equals, in, range, and, or, not, nested
  fields) and a score threshold. Scores mean the same thing in every store.
* **Text and RAG**: with an embedding model configured, `upsertTexts()` and `searchText()` embed text through the
  [OpenAI driver](https://kipchak.dev/docs/driver-openai), which also reaches OpenAI-compatible servers such as
  Azure OpenAI, Ollama and vLLM. Pair it with the Anthropic or OpenAI driver to answer from what was found.
* **Built on Kipchak's drivers**: requests go through the HTTP driver; pgvector uses the Doctrine driver's
  connection; embeddings use the OpenAI driver.

Tested against Qdrant 1.15, pgvector 0.8 on PostgreSQL 17, and OpenSearch 2.19.

#### Composer Package

`kipchak/driver-vector`

## Installation

```bash
composer require kipchak/driver-vector

# Only for what you use:
composer require kipchak/driver-doctrine   # pgvector
composer require kipchak/driver-openai     # embeddings
```

Initialise it in `drivers/drivers.php` after the HTTP driver, and after the Doctrine and OpenAI drivers if a
connection uses them:

```php
use Kipchak\Driver\Vector\Vector;

Vector::initialise($container);
```

pgvector needs PHP's `pdo_pgsql` extension, which every Kipchak runtime image includes. On another image, add it
with `RUN install-php-extensions pdo_pgsql`.

## Configuration

Copy [`sample.config.php`](sample.config.php) to `config/kipchak.vector.php`. Each connection names its store:

```php
return [
    'connections' => [
        'default' => [
            'store' => 'qdrant',
            'url' => env('QDRANT_URL', 'http://qdrant:6333'),
            'api_key' => env('QDRANT_API_KEY', ''),
            'embeddings' => ['openai' => 'default', 'model' => 'text-embedding-3-small'],
        ],
        'postgres' => [
            'store' => 'pgvector',
            'doctrine' => 'primary',
        ],
        'search' => [
            'store' => 'opensearch',
            'url' => env('OPENSEARCH_URL', 'https://opensearch:9200'),
            'username' => env('OPENSEARCH_USER', ''),
            'password' => env('OPENSEARCH_PASSWORD', ''),
        ],
    ],
];
```

| Key | Stores | Default | Meaning |
|---|---|---|---|
| `store` | all | required | `qdrant`, `pgvector` or `opensearch` |
| `batch_size` | all | `256` | Points per request when `upsert()` is given more |
| `url` | Qdrant, OpenSearch | required | Base URL, such as `http://qdrant:6333` |
| `api_key` | Qdrant | none | Sent as the `api-key` header |
| `username`, `password` | OpenSearch | none | Basic authentication |
| `timeout`, `connect_timeout` | Qdrant, OpenSearch | `10`, `2` | Seconds |
| `retries` | Qdrant, OpenSearch | `1` | Retries after a connection failure; the store's own errors are not retried |
| `tls.ca`, `tls.verify`, `tls.cert`, `tls.key` | Qdrant, OpenSearch | system CAs | Certificate verification and client certificates |
| `index_prefix` | OpenSearch | none | Prepended to each collection's index name |
| `engine` | OpenSearch | `lucene` | `lucene` or `faiss` |
| `refresh` | OpenSearch | `wait_for` | `wait_for` makes writes searchable when they return; `false` is faster |
| `doctrine` | pgvector | `primary` | The Doctrine driver's DBAL connection |
| `table_prefix` | pgvector | `vector_` | Prepended to each collection's table name |
| `embeddings.openai` | all | `default` | The OpenAI driver's connection |
| `embeddings.model` | all | required | Such as `text-embedding-3-small`, or `nomic-embed-text` on Ollama |
| `embeddings.dimensions` | all | the model's | Shorter vectors from models that support it (`text-embedding-3-*`) |
| `embeddings.service` | all | none | Instead of the OpenAI driver: a container key holding your own `Embedder` |
| `embeddings.text_field` | all | `text` | The payload key `upsertTexts()` stores each text under |

## Usage

### Collections

```php
use Kipchak\Driver\Vector\Vector;
use Kipchak\Driver\Vector\Query\Distance;

$vectors = Vector::get();

$vectors->createCollection('articles', 1536, Distance::Cosine);
$vectors->ensureCollection('articles', 1536);   // creates it unless it exists
$vectors->collectionExists('articles');
$vectors->info('articles');                     // dimensions, distance, count
$vectors->deleteCollection('articles');         // does nothing if it does not exist
```

Collection names are 1 to 63 lower-case letters, digits, underscores and hyphens, so the same name works as a
Qdrant collection, an OpenSearch index and a PostgreSQL table.

### Points

```php
use Kipchak\Driver\Vector\Result\Point;

$vectors->upsert('articles', [
    new Point('article-17', $embedding, ['tenant' => 'acme', 'published' => 1761696000, 'tags' => ['php']]),
    ['id' => 18, 'vector' => $other, 'payload' => ['tenant' => 'acme']],
]);

$vectors->get('articles', ['article-17', 18]);        // Records, in the order asked for
$vectors->find('articles', 'article-17', withVectors: true);
$vectors->delete('articles', ['article-17']);
$vectors->count('articles');
```

`upsert()` replaces a point with the same ID, and accepts any iterable (a generator too), sending `batch_size`
points per request. IDs are strings or non-negative integers and come back as strings. Payloads are any
JSON-compatible array; key order is not kept by every store.

Qdrant itself only accepts integers and UUIDs as IDs, so the driver maps other IDs to a UUID and keeps your ID in
the payload key `_kipchak_id`, which it removes from what it returns. You never see the difference, but do not use
that key yourself.

### Search

```php
use Kipchak\Driver\Vector\Query\Filter;

$matches = $vectors->search('articles', $queryEmbedding, limit: 5,
    filter: Filter::and(
        Filter::equals('tenant', 'acme'),
        Filter::range('published', gte: strtotime('-30 days')),
        Filter::not(Filter::equals('tags', 'draft')),
    ),
    threshold: 0.75,
);

foreach ($matches as $match) {
    $match->id;
    $match->score;     // 0.83
    $match->payload;   // ['tenant' => 'acme', ...]
}
```

Scores mean the same in every store, whatever its own scale:

| Distance | Score | Closer is | `threshold` keeps |
|---|---|---|---|
| `Cosine` | cosine similarity, -1 to 1 | higher | scores at or above it |
| `Dot` | dot product | higher | scores at or above it |
| `Euclidean` | distance | lower | scores at or below it |

Filters apply to payload fields, with dots for nested fields (`author.id`):

| Filter | Matches |
|---|---|
| `Filter::equals('tag', 'php')` | the value, or an array containing it |
| `Filter::in('lang', ['en', 'fr'])` | any of the values, or an array containing one |
| `Filter::range('price', gte: 10, lt: 20)` | numbers within the bounds (`gt`, `gte`, `lt`, `lte`) |
| `Filter::and(...)`, `Filter::or(...)` | all, or any, of the filters |
| `Filter::not($filter)` | everything else, including points without the field |

`range()` compares numbers, so store dates as Unix timestamps to filter on them. `deleteWhere()` and `count()`
take the same filters.

Vector search is approximate (HNSW in every store), which is what makes it fast. pgvector searches with a filter
use iterative index scans (pgvector 0.8), so a selective filter still returns up to the limit.

### Text and RAG

With `embeddings` configured, the driver embeds text for you:

```php
use Kipchak\Driver\Vector\Embedding\Document;

$vectors->ensureCollection('handbook', 1536);
$vectors->upsertTexts('handbook', [
    new Document('leave-1', 'Staff accrue 25 days of annual leave a year.', ['section' => 'leave']),
    ['id' => 'leave-2', 'text' => 'Unused leave carries over until March.', 'payload' => ['section' => 'leave']],
]);

$context = $vectors->searchText('handbook', 'How much holiday do I get?', limit: 3);
```

Each text is stored in its payload under `text`, so the matches carry what you need to put in a prompt:

```php
use Kipchak\Driver\Anthropic\Anthropic;

$sources = implode("\n\n", array_map(fn ($match) => $match->payload['text'], $context));
$answer = Anthropic::get()->messages()->create(
    messages: [['role' => 'user', 'content' => "Question: $question"]],
    maxTokens: 512,
    system: "Answer only from these sources:\n\n$sources",
);
```

`embed()` and `embedMany()` return vectors without storing them. Texts are embedded in batches of 100. Point
`embeddings.openai` at an OpenAI driver connection with a `base_uri` to use Azure OpenAI, Ollama, vLLM or another
OpenAI-compatible server, or set `embeddings.service` to your own `Kipchak\Driver\Vector\Embedding\Embedder`.

### Errors

* `VectorConnectionException`: the store could not be reached or did not answer in time. Answer 503.
* `CollectionNotFoundException`: the collection does not exist.
* `VectorException`: the store refused the operation, such as a vector with the wrong number of dimensions;
  `status` is the HTTP status (Qdrant, OpenSearch) and `storeError` the store's error type or SQLSTATE.

```php
$vectors->health();   // whether the store answers; never throws
```

## Stores in detail

**Qdrant**: one collection per collection, with `wait=true` on writes so they are searchable when `upsert()`
returns. Qdrant normalises vectors in Cosine collections, so `withVectors` returns them at length 1. Index payload
fields you filter on often in Qdrant itself for speed; filters work without an index.

**pgvector**: each collection is a table (`vector_<name>` with `id`, `embedding` and a `jsonb` `payload`), with an
HNSW index for its distance and a GIN index on the payload for `equals` and `in`. A registry table,
`vector_collections`, records each collection's dimensions and distance. Creating the first collection runs
`CREATE EXTENSION IF NOT EXISTS vector` if the extension is missing, which needs the rights to do so. Vectors over
2,000 dimensions (such as `text-embedding-3-large`'s 3,072) are indexed and searched as `halfvec`, because HNSW
cannot index longer `vector` columns.

**OpenSearch**: one index per collection with `index.knn` enabled, the vector in a `knn_vector` field and the
payload under `payload`, its strings mapped as keywords so filters match them exactly. Filters are applied during
the k-NN search, not after it. The driver checks a collection exists before its first write in each worker,
because a bulk write to a missing index would create one with the wrong mapping.

## FrankenPHP worker mode

One client per connection is kept for the life of each worker. Qdrant and OpenSearch requests go through the HTTP
driver and hold nothing open. pgvector uses the Doctrine driver's connection: when PostgreSQL restarts or drops
it, the operation is retried once on a new connection (every operation can safely run twice), unless your own
transaction is open on that connection. Each client remembers its collections' distances, which do not change.

## Testing

```bash
composer install
vendor/bin/phpunit --testsuite Unit

# Integration tests run one shared suite against Qdrant, pgvector and OpenSearch in Docker:
tests/Integration/start-services.sh
vendor/bin/phpunit
docker compose -f tests/Integration/compose.yaml down -v
```

## What is a Kipchak Driver?

Kipchak Drivers are used to connect Kipchak to various data sources or storage systems.

They provide a standardized interface for interacting with different data sources, allowing developers to
focus on building their applications rather than dealing with the complexities of each data source.

Drivers are basically Container Dependencies injected into Kipchak's Service Container.

## Licence

MIT
