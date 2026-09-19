<?php

declare(strict_types=1);

namespace VoidLabs\Comments;

final class CommentRequest
{
    private function __construct(
        public readonly string $route,
        public readonly string $name,
        public readonly string $email,
        public readonly string $body,
        public readonly ?string $parentId,
    ) {}

    /** @param list<string> $allowedTemplates */
    public static function fromArray(array $data, string $currentRoute, string $currentTemplate, array $allowedTemplates): self
    {
        $route = self::route((string) ($data['route'] ?? ''));
        $currentRoute = self::route($currentRoute);
        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $body = trim((string) ($data['message'] ?? ''));
        $parentId = trim((string) ($data['parent_id'] ?? '')) ?: null;
        if ($route === '' || $route === '/' || $route !== $currentRoute || $currentTemplate === '' || !in_array($currentTemplate, $allowedTemplates, true)) {
            throw new \InvalidArgumentException('La pagina del commento non è valida.');
        }
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\r\n]/', $name)) throw new \InvalidArgumentException('Inserisci un nome valido.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) throw new \InvalidArgumentException('Inserisci un indirizzo email valido.');
        if ($body === '' || mb_strlen($body) > 5000) throw new \InvalidArgumentException('Inserisci un commento non più lungo di 5.000 caratteri.');
        if ($parentId !== null && !preg_match('/^(?:legacy-)?[0-9a-f-]{1,64}$/i', $parentId)) throw new \InvalidArgumentException('Il commento a cui rispondere non è valido.');
        return new self($route, $name, $email, $body, $parentId);
    }

    private static function route(string $route): string
    {
        $path = parse_url($route, PHP_URL_PATH);
        return is_string($path) ? '/' . trim($path, '/') : '';
    }
}
