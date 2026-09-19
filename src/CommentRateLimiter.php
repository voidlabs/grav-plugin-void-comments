<?php

declare(strict_types=1);

namespace VoidLabs\Comments;

final class CommentRateLimiter
{
    public function __construct(private readonly string $file, private readonly int $window = 3600, private readonly int $maximum = 3) {}

    public function consume(string $address, string $email, ?int $now = null): bool
    {
        $now ??= time();
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) return false;
        $handle = fopen($this->file, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            return false;
        }
        try {
            $raw = stream_get_contents($handle);
            $data = $raw === false || $raw === '' ? [] : (json_decode($raw, true) ?: []);
            $key = hash('sha256', strtolower(trim($address)) . "\0" . strtolower(trim($email)));
            $threshold = $now - $this->window;
            $attempts = array_values(array_filter((array) ($data[$key] ?? []), static fn($time): bool => is_int($time) && $time > $threshold));
            if (count($attempts) >= $this->maximum) return false;
            $attempts[] = $now;
            $data[$key] = $attempts;
            foreach ($data as $candidate => $times) {
                if (!array_filter((array) $times, static fn($time): bool => is_int($time) && $time > $threshold)) unset($data[$candidate]);
            }
            rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($data, JSON_THROW_ON_ERROR)); fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN); fclose($handle);
        }
    }
}
