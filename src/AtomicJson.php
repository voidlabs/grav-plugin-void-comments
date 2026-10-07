<?php
declare(strict_types=1);
namespace VoidLabs\Comments;

/** Stable lock files must never be unlinked while workers can be running. */
final class AtomicJson
{
    public static function locked(string $file, callable $operation): mixed
    {
        self::directory(dirname($file));
        $handle = fopen($file, 'c+b');
        if ($handle === false) throw new \RuntimeException('Cannot open storage lock.');
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Cannot lock storage.');
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function read(string $file): array
    {
        if (!is_file($file)) return [];
        $raw = file_get_contents($file);
        if ($raw === false || trim($raw) === '') throw new \RuntimeException('Empty or unreadable JSON storage.');
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid JSON storage.');
        return $data;
    }

    public static function write(string $file, array $data): void
    {
        self::directory(dirname($file));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(12));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new \RuntimeException('Cannot create temporary storage.');
        try {
            chmod($temporary, 0600);
            for ($offset = 0, $length = strlen($json); $offset < $length; $offset += $written) {
                $written = fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) throw new \RuntimeException('Incomplete storage write.');
            }
            if (!fflush($handle) || !fsync($handle)) throw new \RuntimeException('Cannot flush storage.');
            fclose($handle);
            $handle = null;
            // Keep the old file intact if replacement fails (including on Windows).
            if (!rename($temporary, $file)) throw new \RuntimeException('Cannot replace storage.');
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (is_file($temporary)) unlink($temporary);
        }
    }

    private static function directory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create storage directory.');
        }
    }
}
