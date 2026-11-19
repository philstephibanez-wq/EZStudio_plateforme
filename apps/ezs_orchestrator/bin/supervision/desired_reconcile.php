<?php
declare(strict_types=1);

/** EZS desired-state convergence worker. No HTTP context; no PROD/ROOT writes. */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Ezs\Web\Module\Infrastructure\NativeWindowsLifecycle;
use Ezs\Web\Module\Infrastructure\DesiredServiceState;

$once = in_array('--once', $argv, true);
$dry = in_array('--dry-run', $argv, true);
if (!$once && !$dry) {
    fwrite(STDERR, "Usage: php scripts/supervision/desired_reconcile.php --once [--dry-run]\n");
    exit(64);
}
if (PHP_OS_FAMILY !== 'Windows') {
    fwrite(STDERR, "EZS_RECONCILE_WINDOWS_REQUIRED\n");
    exit(69);
}
$desiredPath = $root . '/runtime/supervision/desired-services.json';
if (!is_file($desiredPath)) {
    fwrite(STDERR, "EZS_RECONCILE_DESIRED_FILE_MISSING\n");
    exit(65);
}
$lockDir = $root . '/runtime/supervision';
$lockFile = $lockDir . '/desired-reconcile.lock';
$lock = fopen($lockFile, 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "EZS_RECONCILE_BUSY\n");
    exit(75);
}
$log = $lockDir . '/desired-reconcile.jsonl';
$emit = static function (array $event) use ($log): void {
    $event = ['timestamp_utc' => gmdate('c')] + $event;
    $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($log, $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('EZS_RECONCILE_AUDIT_FAILED');
    }
    echo $line;
};
$exit = 0;
try {
    $intent = new DesiredServiceState($desiredPath);
    $expected = $intent->all();
    $lifecycle = new NativeWindowsLifecycle();
    $groups = NativeWindowsLifecycle::groups();
    // Recheck live state before EACH mutation; never trust a stale first snapshot.
    foreach (['dev', 'lab', 'assistant'] as $group) {
        $wanted = $expected[$group] ?? null;
        if (!in_array($wanted, [DesiredServiceState::RUNNING, DesiredServiceState::STOPPED], true)) {
            throw new RuntimeException('EZS_RECONCILE_INTENT_INVALID_' . $group);
        }
        // Another operator could change desired state after this run began.
        if ($intent->get($group) !== $wanted) {
            $emit(['group'=>$group, 'result'=>'SKIPPED_INTENT_CHANGED']);
            continue;
        }
        $states = $lifecycle->status();
        $members = $groups[$group];
        $observed = [];
        foreach ($members as $name) $observed[$name] = $states[$name] ?? 'MISSING';
        $safe = count(array_filter($observed, static fn($s) => in_array($s, ['ABSENT','ATTESTED'], true))) === count($members);
        if (!$safe) {
            $emit(['group'=>$group,'desired'=>$wanted,'observed'=>$observed,'result'=>'BLOCKED_UNVERIFIED']);
            $exit = 2;
            continue;
        }
        $allRunning = count(array_filter($observed, static fn($s) => $s === 'ATTESTED')) === count($members);
        $allStopped = count(array_filter($observed, static fn($s) => $s === 'ABSENT')) === count($members);
        if (($wanted === DesiredServiceState::RUNNING && $allRunning) || ($wanted === DesiredServiceState::STOPPED && $allStopped)) {
            $emit(['group'=>$group,'desired'=>$wanted,'observed'=>$observed,'result'=>'CONVERGED']);
            continue;
        }
        if ($dry) {
            $emit(['group'=>$group,'desired'=>$wanted,'observed'=>$observed,'result'=>'WOULD_RECONCILE']);
            continue;
        }
        try {
            $result = $wanted === DesiredServiceState::RUNNING ? $lifecycle->startGroup($group) : $lifecycle->stopGroup($group);
            $after = $lifecycle->status();
            $attested = true;
            $post = [];
            foreach ($members as $name) {
                $post[$name] = $after[$name] ?? 'MISSING';
                if ($post[$name] !== ($wanted === DesiredServiceState::RUNNING ? 'ATTESTED' : 'ABSENT')) $attested = false;
            }
            $emit(['group'=>$group,'desired'=>$wanted,'before'=>$observed,'after'=>$post,'result'=>$attested?'CONVERGED':'POSTCONDITION_FAILED','operation'=>$wanted === DesiredServiceState::RUNNING?'START':'STOP','members_changed'=>array_keys($result)]);
            if (!$attested) $exit = 2;
        } catch (Throwable $failure) {
            $emit(['group'=>$group,'desired'=>$wanted,'before'=>$observed,'result'=>'OPERATION_FAILED','error_code'=>get_class($failure),'error_message'=>$failure->getMessage()]);
            $exit = 2;
        }
    }
} catch (Throwable $failure) {
    try { $emit(['result'=>'FATAL','error_code'=>get_class($failure),'error_message'=>$failure->getMessage()]); } catch (Throwable $_) {}
    $exit = 2;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit($exit);
