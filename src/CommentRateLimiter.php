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
            $data = $raw === '' ? [] : json_decode((string) $raw, true);
            if (!is_array($data)) return false;
            $address = strtolower(trim($address));
            $email = strtolower(trim($email));
            $legacyKey = hash('sha256', $address . "\0" . $email);
            $keys = ['ip:' . hash('sha256', $address), 'email:' . hash('sha256', $email)];
            $threshold = $now - $this->window;
            foreach ($data as $candidate => $times) {
                $data[$candidate] = array_values(array_filter(
                    (array) $times,
                    static fn($time): bool => is_int($time) && $time > $threshold
                ));
                if ($data[$candidate] === []) unset($data[$candidate]);
            }
            // Files created by 0.1.x used a hash of IP + email. Keep active
            // entries for that exact pair effective while new requests use
            // independent IP and email counters.
            foreach ($keys as $key) {
                if (count($data[$legacyKey] ?? []) + count($data[$key] ?? []) >= $this->maximum) return false;
            }
            foreach ($keys as $key) $data[$key][] = $now;
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            rewind($handle);
            return ftruncate($handle, 0)
                && fwrite($handle, $json) === strlen($json)
                && fflush($handle);
        } finally {
            flock($handle, LOCK_UN); fclose($handle);
        }
    }
}
