<?php

namespace App\Support\Smk\Profile;

use RuntimeException;

final class ProfileFilesystem
{
    public readonly string $root;

    public function __construct(string $root)
    {
        $canonical = realpath($root);
        $this->need($canonical !== false && is_dir($canonical), 'root: existing directory required');
        $this->root = $canonical;
        $this->inspect('');
    }

    public function need(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    public static function relative(string $path): void
    {
        if (! preg_match('~\A[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*\z~D', $path)) {
            throw new RuntimeException('path: portable relative ASCII segments required: '.$path);
        }
        foreach (explode('/', strtolower($path)) as $segment) {
            if (in_array($segment, ['vendor', 'node_modules', '.git', '.env'], true)) {
                throw new RuntimeException('path: protected location: '.$path);
            }
        }
    }

    // Internal paths use the same component checks, including private transaction files.
    public function inspect(string $path): array
    {
        if ($path !== '') {
            self::relative($path);
        }
        $result = [];
        $parts = $path === '' ? [] : explode('/', $path);
        $current = $this->root;
        foreach (array_merge([''], $parts) as $index => $part) {
            if ($index > 0) {
                if (is_dir($current)) {
                    foreach (scandir($current) as $sibling) {
                        $this->need(strtolower($sibling) !== strtolower($part) || $sibling === $part, 'path: case alias collision: '.$part);
                    }
                }
                $current .= '/'.$part;
            }
            clearstatcache(true, $current);
            $s = @lstat($current);
            if ($s === false) {
                $result[$current] = null;

                continue;
            }
            $type = $s['mode'] & 0170000;
            $this->need(in_array($type, [0040000, 0100000], true), 'path: symlink or non-regular object: '.$current);
            $this->need($s['uid'] === posix_geteuid(), 'path: unexpected owner: '.$current);
            $this->need(($s['mode'] & 0022) === 0, 'path: group/world writable component: '.$current);
            $this->need($type !== 0100000 || $s['nlink'] === 1, 'path: shared hardlink: '.$current);
            $this->need($index === count($parts) || $type === 0040000, 'path: file used as directory: '.$current);
            $result[$current] = ['dev' => $s['dev'], 'ino' => $s['ino'], 'uid' => $s['uid'], 'gid' => $s['gid'], 'mode' => $s['mode'] & 0777, 'type' => $type];
            if ($type === 0100000) {
                $result[$current] += ['size' => $s['size'], 'mtime' => $s['mtime'], 'sha256' => hash_file('sha256', $current)];
            }
        }

        return $result;
    }

    public function identity(string $path): ?array
    {
        $all = $this->inspect($path);

        return end($all);
    }

    public function file(string $path): ?array
    {
        $all = $this->inspect($path);
        $last = end($all);
        $this->need($last === null || $last['type'] === 0100000, 'path: regular file required: '.$path);

        return $last;
    }

    public function read(string $path): string
    {
        $this->need($this->file($path) !== null, 'path: missing source: '.$path);
        $bytes = file_get_contents($this->root.'/'.$path);
        $this->need(is_string($bytes), 'path: unreadable: '.$path);

        return $bytes;
    }

    public function mkdir(string $path, int $mode = 0755): void
    {
        self::relative($path);
        $cursor = '';
        foreach (explode('/', $path) as $part) {
            $cursor = ltrim($cursor.'/'.$part, '/');
            $all = $this->inspect($cursor);
            if (end($all) === null) {
                $this->need(mkdir($this->root.'/'.$cursor, $mode), 'mkdir failed: '.$cursor);
                $this->syncDir(dirname($this->root.'/'.$cursor));
            }
        }
    }

    public function writeNew(string $path, string $bytes, int $mode = 0600, ?int $gid = null): void
    {
        $this->need($this->file($path) === null, 'write: destination exists: '.$path);
        $f = fopen($this->root.'/'.$path, 'x+b');
        $this->need($f !== false, 'write: exclusive create failed');
        try {
            $this->need(chmod($this->root.'/'.$path, $mode), 'chmod failed');
            if ($gid !== null) {
                $this->need(chgrp($this->root.'/'.$path, $gid), 'group metadata cannot be preserved');
            }
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $n = fwrite($f, substr($bytes, $offset));
                $this->need($n !== false && $n > 0, 'write failed');
                $offset += $n;
            }
            $this->need(fflush($f) && fsync($f), 'write: durable flush unavailable');
        } finally {
            fclose($f);
        }
        $this->syncDir(dirname($this->root.'/'.$path));
    }

    public function syncDir(string $absolute): void
    {
        $f = @fopen($absolute, 'r');
        $this->need($f !== false, 'directory durability unavailable');
        try {
            $this->need(@fsync($f), 'directory fsync unavailable; apply refused');
        } finally {
            fclose($f);
        }
    }

    public function rename(string $from, string $to): void
    {
        $a = $this->file($from);
        $b = $this->file($to);
        $this->need($a !== null, 'rename: missing source');
        $parents = $this->inspect(dirname($to));
        $parent = end($parents);
        $this->need($parent !== null && $parent['dev'] === $a['dev'], 'rename: same filesystem required');
        $this->need(rename($this->root.'/'.$from, $this->root.'/'.$to), 'rename failed');
        $this->syncDir(dirname($this->root.'/'.$from));
        $this->syncDir(dirname($this->root.'/'.$to));
    }

    public static function json(array|object $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
