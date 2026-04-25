<?php

declare(strict_types=1);

namespace Miit\Support;

use Miit\Exception\MiitException;

final class FileMutex
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $name, private readonly string $directory)
    {
    }

    public function acquire(): void
    {
        $file = $this->directory . '/' . sha1($this->name) . '.lock';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new MiitException('failed to open lock file');
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new MiitException('failed to acquire lock');
        }

        $this->handle = $handle;
    }

    public function tryAcquire(): bool
    {
        $file = $this->directory . '/' . sha1($this->name) . '.lock';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new MiitException('failed to open lock file');
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
}
