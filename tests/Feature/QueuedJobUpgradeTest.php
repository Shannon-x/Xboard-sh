<?php

namespace Tests\Feature;

use App\Jobs\NodeUserSyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class QueuedJobUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public static function legacyActions(): array
    {
        return [['created'], ['updated'], ['deleted']];
    }

    #[DataProvider('legacyActions')]
    public function test_worker_can_consume_jobs_enqueued_before_addon_support(string $action): void
    {
        $data = (new NodeUserSyncJob(42, $action, 17))->__serialize();
        // The Sept 5 producer only serialized userId, action, oldGroupId and queue metadata.
        unset($data["\0" . NodeUserSyncJob::class . "\0oldAddonGroups"]);
        $legacy = (new ReflectionClass(NodeUserSyncJob::class))->newInstanceWithoutConstructor();
        $legacy->__unserialize($data);
        $restored = unserialize(serialize($legacy));
        $restored->handle();
        $this->assertSame('node_sync', $restored->queue);
    }
}
