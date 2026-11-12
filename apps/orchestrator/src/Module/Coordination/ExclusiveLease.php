<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/**
 * Non-blocking, process-wide lifetime lease based on OS file locking.
 * Retain the handle for the entire critical operation; never unlink lock files.
 */
final class ExclusiveLease
{
    private mixed $handle = null;

    public function __construct(
        private readonly string $directory,
        private readonly string $scope,
    ) {
        if (!in_array($scope, ['control', 'gpu-executor'], true)) {
            throw new \InvalidArgumentException('unknown_lease_scope');
        }
    }

    public function acquire(): bool
    {
        if (is_resource($this->handle)) {
            return true;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('lease_directory_unavailable');
        }
        $path = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $this->scope . '.lock';
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('lease_file_unavailable');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
