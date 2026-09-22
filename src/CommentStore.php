<?php

declare(strict_types=1);

namespace VoidLabs\Comments;

final class CommentStore
{
    public function __construct(private readonly string $directory) {}

    public function addPending(CommentRequest $request, string $address, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = bin2hex(random_bytes(16));
        $record = [
            'schema_version' => 1, 'id' => $id, 'route' => $request->route,
            'parent_id' => $request->parentId, 'author' => $request->name, 'email' => $request->email,
            'body' => $request->body, 'created_at' => $now->format(\DateTimeInterface::ATOM),
            'technical' => ['address_sha256' => hash('sha256', strtolower(trim($address))), 'expires_at' => $now->modify('+7 days')->format(\DateTimeInterface::ATOM)],
        ];
        $this->write('pending', $id, $record);
        return $record;
    }

    public function pending(): array { return $this->records('pending'); }

    public function approved(): array { return $this->records('approved'); }

    public function find(string $status, string $query = ''): array
    {
        $query = mb_strtolower(trim($query), 'UTF-8');
        $records = array_values(array_filter($this->records($status), static function (array $record) use ($query): bool {
            if ($query === '') {
                return true;
            }
            $haystack = mb_strtolower(implode("\n", [
                (string) ($record['id'] ?? ''),
                (string) ($record['route'] ?? ''),
                (string) ($record['author'] ?? ''),
                (string) ($record['email'] ?? ''),
                (string) ($record['body'] ?? ''),
            ]), 'UTF-8');
            return mb_strpos($haystack, $query, 0, 'UTF-8') !== false;
        }));
        usort($records, static fn(array $a, array $b): int => [
            (string) ($b['created_at'] ?? ''), (string) ($b['id'] ?? ''),
        ] <=> [
            (string) ($a['created_at'] ?? ''), (string) ($a['id'] ?? ''),
        ]);
        return $records;
    }

    /**
     * Return an administrative page of comments, newest first.
     *
     * @return array{items:list<array<string,mixed>>, pagination:array{current:int,pages:int,total:int,per_page:int}}
     */
    public function page(
        string $status,
        string $query = '',
        string $route = '',
        int $page = 1,
        int $perPage = 20,
        ?string $focusId = null
    ): array {
        if (!in_array($status, ['pending', 'approved'], true)) {
            throw new \InvalidArgumentException('Stato commento non valido.');
        }
        $route = trim($route);
        $records = $this->find($status, $query);
        if ($route !== '') {
            $records = array_values(array_filter($records, static fn(array $record): bool => (string) ($record['route'] ?? '') === $route));
        }

        $perPage = min(100, max(1, $perPage));
        $total = count($records);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($pages, max(1, $page));
        if ($focusId !== null && $focusId !== '') {
            foreach ($records as $index => $record) {
                if ((string) ($record['id'] ?? '') === $focusId) {
                    $page = min($pages, (int) floor($index / $perPage) + 1);
                    break;
                }
            }
        }

        return [
            'items' => array_values(array_slice($records, ($page - 1) * $perPage, $perPage)),
            'pagination' => ['current' => $page, 'pages' => $pages, 'total' => $total, 'per_page' => $perPage],
        ];
    }

    public function approvedForRoute(string $route): array
    {
        $records = array_filter($this->records('approved'), static fn(array $record): bool => ($record['route'] ?? '') === $route);
        $byParent = []; $known = [];
        foreach ($records as $record) $known[(string) ($record['id'] ?? '')] = true;
        foreach ($records as $record) {
            $parent = (string) ($record['parent_id'] ?? '');
            $byParent[$parent !== '' && isset($known[$parent]) ? $parent : ''][] = $record;
        }
        foreach ($byParent as &$siblings) {
            usort($siblings, static fn(array $a, array $b): int => [(string) ($a['created_at'] ?? ''), (string) ($a['id'] ?? '')] <=> [(string) ($b['created_at'] ?? ''), (string) ($b['id'] ?? '')]);
        }
        unset($siblings);
        $ordered = []; $visited = [];
        $append = static function (string $parent, int $depth) use (&$append, &$byParent, &$ordered, &$visited): void {
            foreach ($byParent[$parent] ?? [] as $record) {
                $id = (string) ($record['id'] ?? '');
                if ($id === '' || isset($visited[$id])) continue;
                $visited[$id] = true; $record['depth'] = min($depth, 6); $ordered[] = $record; $append($id, $depth + 1);
            }
        };
        $append('', 0);
        return $ordered;
    }

    public function approvedParentForRoute(string $id, string $route): ?array
    {
        foreach ($this->records('approved') as $record) {
            if (($record['id'] ?? '') === $id && ($record['route'] ?? '') === $route) return $record;
        }
        return null;
    }

    public function approve(string $id, ?\DateTimeImmutable $now = null): bool
    {
        $record = $this->read('pending', $id);
        if ($record === null) return false;
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $record['moderated_at'] = $now->format(\DateTimeInterface::ATOM); unset($record['technical']);
        $this->write('approved', $id, $record);
        $source = $this->filename('pending', $id);
        if (is_file($source) && !unlink($source)) throw new \RuntimeException('Impossibile rimuovere il commento pending.');
        return true;
    }

    public function deletePending(string $id): bool
    {
        $filename = $this->filename('pending', $id);
        return is_file($filename) && unlink($filename);
    }

    public function deleteApproved(string $id): bool
    {
        $filename = $this->filename('approved', $id);
        return is_file($filename) && unlink($filename);
    }

    public function update(string $status, string $id, array $changes): bool
    {
        if (!in_array($status, ['pending', 'approved'], true)) throw new \InvalidArgumentException('Stato commento non valido.');
        $record = $this->read($status, $id);
        if ($record === null) return false;
        if (array_key_exists('author', $changes)) {
            $author = trim((string) $changes['author']);
            if ($author === '' || mb_strlen($author, 'UTF-8') > 120) throw new \InvalidArgumentException('Nome commentatore non valido.');
            $record['author'] = $author;
        }
        if (array_key_exists('email', $changes)) {
            $email = strtolower(trim((string) $changes['email']));
            if ($email === '') {
                unset($record['email']);
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                throw new \InvalidArgumentException('Email commentatore non valida.');
            } else {
                $record['email'] = $email;
            }
        }
        if (array_key_exists('body', $changes)) {
            $body = trim((string) $changes['body']);
            if ($body === '' || mb_strlen($body, 'UTF-8') > 5000) throw new \InvalidArgumentException('Testo commento non valido.');
            $record['body'] = $body;
        }
        $record['updated_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $this->write($status, $id, $record);
        return true;
    }

    public function prune(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $result = ['technical_removed' => 0, 'pending_deleted' => 0];
        foreach ($this->records('pending') as $record) {
            $id = (string) ($record['id'] ?? '');
            $created = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) ($record['created_at'] ?? ''));
            if ($created && $created <= $now->modify('-90 days')) {
                if ($this->deletePending($id)) ++$result['pending_deleted'];
                continue;
            }
            $expires = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) ($record['technical']['expires_at'] ?? ''));
            if (isset($record['technical']) && $expires && $expires <= $now) {
                unset($record['technical']); $this->write('pending', $id, $record); ++$result['technical_removed'];
            }
        }
        return $result;
    }

    private function records(string $status): array
    {
        $directory = $this->directory . '/' . $status;
        if (!is_dir($directory)) return [];
        $records = [];
        foreach (glob($directory . '/*.json') ?: [] as $filename) {
            $record = json_decode((string) file_get_contents($filename), true);
            if (is_array($record)) {
                // La directory e' l'unica fonte dello stato. I record legacy
                // possono contenere `status`, che non fa parte del contratto.
                unset($record['status']);
                $records[] = $record;
            }
        }
        return $records;
    }

    private function read(string $status, string $id): ?array
    {
        $filename = $this->filename($status, $id);
        if (!is_file($filename)) return null;
        $record = json_decode((string) file_get_contents($filename), true);
        if (is_array($record)) unset($record['status']);
        return is_array($record) ? $record : null;
    }

    private function write(string $status, string $id, array $record): void
    {
        // The directory is the sole source of truth for moderation state.
        // Never add a redundant status field to runtime JSON records.
        unset($record['status']);
        $directory = $this->directory . '/' . $status;
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('Impossibile creare lo storage dei commenti.');
        $target = $this->filename($status, $id); $temporary = $target . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $target)) {
            @unlink($temporary); throw new \RuntimeException('Impossibile aggiornare il commento.');
        }
    }

    private function filename(string $status, string $id): string
    {
        if (!in_array($status, ['pending', 'approved'], true) || !preg_match('/^[a-f0-9]{32}$/', $id)) throw new \InvalidArgumentException('Identificativo commento non valido.');
        return $this->directory . '/' . $status . '/' . $id . '.json';
    }
}
