<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QueueBarrierPaths;
use PHPUnit\Framework\TestCase;

final class QueueBarrierPathsTest extends TestCase
{
    public function testProductionAndTestPathsAreDistinct(): void
    {
        $old=$_SERVER['APP_ENV'] ?? null;
        try {
            $_SERVER['APP_ENV']='test';
            self::assertSame('/root/var/orchestrator_queue_paused_on_error_test.json',QueueBarrierPaths::pause('/root'));
            self::assertSame('/root/var/orchestrator_queue_stopped_cancel_test.json',QueueBarrierPaths::stoppedCancellation('/root'));
            $_SERVER['APP_ENV']='dev';
            self::assertSame('/root/var/orchestrator_queue_paused_on_error.json',QueueBarrierPaths::pause('/root'));
            self::assertSame('/root/var/orchestrator_queue_stopped_cancel.json',QueueBarrierPaths::stoppedCancellation('/root'));
        } finally {
            if ($old===null) unset($_SERVER['APP_ENV']); else $_SERVER['APP_ENV']=$old;
        }
    }
}
