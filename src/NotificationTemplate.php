<?php

declare(strict_types=1);

namespace VoidLabs\Comments;

final class NotificationTemplate
{
    public const DEFAULT_BODY = "Nuovo commento in moderazione\n\nPagina: {route}\nAutore: {author}\nEmail: {email}\nID: {id}\n\n{body}\n\nApri il commento in moderazione:\n{moderation_url}\n\nVedi la pagina pubblica:\n{public_url}";

    /** @param array<string, string> $values */
    public static function render(string $template, array $values): string
    {
        $replacements = [];
        foreach ($values as $name => $value) {
            $replacements['{' . $name . '}'] = $value;
        }

        return strtr($template, $replacements);
    }
}
