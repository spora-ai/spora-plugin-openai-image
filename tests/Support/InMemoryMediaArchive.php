<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Tests\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use RuntimeException;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\Principal;
use Spora\Services\AssetStore;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveIngestPipeline;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * A real {@see MediaArchiveService} on top of an in-memory SQLite schema.
 *
 * `MediaArchiveService` is `final`, so the plugin cannot inject a mock and
 * the only way to observe what the tool hands `ingest()` is to let a real
 * service persist the row. The host migrations are replayed into
 * `:memory:` and a user/principal/agent triple is seeded so
 * `MediaArchiveIngestPipeline` can resolve the request's owner.
 *
 * The asset store threshold is deliberately high so small fixtures take the
 * `data_url` branch and never touch the filesystem — the opaque
 * `/api/v1/assets/<token>.<ext>` URL is minted by the pipeline on persist
 * regardless of the backing store.
 */
final class InMemoryMediaArchive
{
    public const AGENT_ID = 1;
    public const USER_ID = 1;
    public const PRINCIPAL_ID = 1;

    private function __construct(private readonly Capsule $capsule) {}

    public static function boot(): self
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $connection = $capsule->getConnection();
        foreach (self::hostMigrations() as $migration) {
            $migration->up($connection->getSchemaBuilder());
        }

        $connection->table('users')->insert([
            'id' => self::USER_ID,
            'email' => 'archive-test@example.test',
            'password' => 'not-a-real-hash',
            'registered' => 1,
        ]);
        $connection->table('principals')->insert([
            'id' => self::PRINCIPAL_ID,
            'type' => 'user',
            'user_id' => self::USER_ID,
        ]);
        $connection->table('agents')->insert([
            'id' => self::AGENT_ID,
            'principal_id' => self::PRINCIPAL_ID,
        ]);

        return new self($capsule);
    }

    /**
     * The context the orchestrator would supply for the seeded triple, so
     * `execute()` sees the same owner/runner attribution production does
     * rather than the deprecated `$userId` argument.
     */
    public function context(): PrincipalContext
    {
        return new PrincipalContext(
            principalId: self::PRINCIPAL_ID,
            type: Principal::TYPE_USER,
            ownerUserId: self::USER_ID,
            runnerUserId: self::USER_ID,
        );
    }

    public function service(): MediaArchiveService
    {
        return $this->serviceBackedBy(new AutoAssetStore(
            new DataUrlAssetStore(1024 * 1024),
            $this->localStore(),
            1024 * 1024,
        ));
    }

    /**
     * A real {@see MediaArchiveService} whose backing store always refuses the
     * payload, so the tool's "a failed ingest must not break the result"
     * contract can be asserted without depending on a broken environment.
     */
    public function serviceWithFailingStore(): MediaArchiveService
    {
        return $this->serviceBackedBy(new FailingAssetStore());
    }

    /**
     * A real {@see MediaAssetReader} over the same in-memory schema, so the
     * plugin's `MediaAssetReader` → `OpenAIImageMediaArchiveResolver` DI
     * closure can be exercised end to end.
     */
    public function reader(): MediaAssetReader
    {
        return new MediaAssetReader(new DatabaseAssetStore(), $this->localStore());
    }

    /** Seed a row the reader can hand back, standing in for a previously generated asset. */
    public function insertAsset(
        string $id,
        string $bytes,
        string $mime,
        string $storageMode = 'data_url',
        ?string $sourceUrl = null,
    ): void {
        $this->capsule->getConnection()->table('media_assets')->insert([
            'id'           => $id,
            'agent_id'     => self::AGENT_ID,
            'user_id'      => self::USER_ID,
            'principal_id' => self::PRINCIPAL_ID,
            'plugin_slug'  => 'openai-image',
            'tool_name'    => 'image',
            'mime_type'    => $mime,
            'byte_size'    => strlen($bytes),
            'asset_url'    => '/api/v1/assets/' . $id . '.png',
            'storage_mode' => $storageMode,
            'source_url'   => $sourceUrl,
            'payload'      => $storageMode === 'data_url' ? $bytes : null,
        ]);
    }

    /** @return array<string, mixed>|null The persisted row, so tests can assert on stored metadata. */
    public function findAsset(string $id): ?array
    {
        $row = $this->capsule->getConnection()->table('media_assets')->where('id', $id)->first();
        return $row === null ? null : (array) $row;
    }

    private function serviceBackedBy(AssetStore $store): MediaArchiveService
    {
        $logger = new NullLogger();
        $sniffer = new MimeSniffer();

        return new MediaArchiveService(new MediaArchiveIngestPipeline(
            new MediaIngestDecoder(),
            new MediaArchiveUrlResolver(
                new RemoteMediaFetcher(new MockHttpClient([]), $logger, 30, 1024 * 1024),
                $sniffer,
                $logger,
                true,
                1024 * 1024,
            ),
            $sniffer,
            new MetadataExtractor($logger, false),
            $store,
            new MediaConverterRegistry(new ThrowingContainer()),
            new PrincipalService(new PrincipalResolver()),
            $logger,
        ));
    }

    private function localStore(): LocalAssetStore
    {
        return new LocalAssetStore(
            new Paths(sys_get_temp_dir() . '/openai-image-test-archive'),
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            1024 * 1024,
        );
    }

    /**
     * @return list<object>
     */
    private static function hostMigrations(): array
    {
        $directory = dirname(__DIR__, 2) . '/vendor/spora-ai/spora-core/database/migrations';
        if (!is_dir($directory)) {
            throw new RuntimeException("Host migrations not found at {$directory} — is spora-ai/spora-core installed?");
        }

        $migrations = [];
        foreach ((array) glob($directory . '/*.php') as $path) {
            $migrations[$path] = require $path;
        }
        ksort($migrations);

        return array_values($migrations);
    }
}
