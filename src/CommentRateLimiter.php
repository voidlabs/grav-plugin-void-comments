<?php

declare(strict_types=1);

namespace VoidLabs\Comments;

require_once __DIR__ . '/AtomicJson.php';

final class CommentRateLimiter
{
    public function __construct(private readonly string $file, private readonly int $window = 3600, private readonly int $maximum = 3) {}

    public function consume(string $address, string $email, ?int $now = null): bool
    {
        $now ??= time();
        try {
            return AtomicJson::locked($this->file . '.lock', function () use ($address, $email, $now): bool {
                $data = AtomicJson::read($this->file);
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
                AtomicJson::write($this->file, $data);
                return true;
            });
        } catch (\Throwable) {
            // Missing files start new counters; damaged existing files fail closed.
            return false;
        }
    }
}
