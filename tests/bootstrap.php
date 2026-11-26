<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}


// N2: this suite owns only test barriers; never remove an existing receipt.
if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'test') {
    throw new RuntimeException('PHPUNIT_REQUIRES_APP_ENV_TEST');
}
$ezsTestRoot = dirname(__DIR__);
if (!is_dir($ezsTestRoot.'/var')) {
    throw new RuntimeException('PHPUNIT_VAR_DIRECTORY_MISSING');
}
$ezsTestLock = fopen($ezsTestRoot.'/var/orchestrator_phpunit.lock', 'c+');
if (!is_resource($ezsTestLock) || !flock($ezsTestLock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('PHPUNIT_ALREADY_RUNNING');
}
register_shutdown_function(static function () use ($ezsTestLock): void {
    flock($ezsTestLock, LOCK_UN);
    fclose($ezsTestLock);
});
foreach ([\App\Service\QueueBarrierPaths::pause($ezsTestRoot), \App\Service\QueueBarrierPaths::stoppedCancellation($ezsTestRoot)] as $ezsTestBarrier) {
    if (file_exists($ezsTestBarrier) || is_link($ezsTestBarrier)) {
        throw new RuntimeException('PHPUNIT_STALE_TEST_BARRIER: '.basename($ezsTestBarrier));
    }
}
