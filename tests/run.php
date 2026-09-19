<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/CommentRequest.php';
require_once dirname(__DIR__) . '/src/CommentRateLimiter.php';
require_once dirname(__DIR__) . '/src/CommentStore.php';

use VoidLabs\Comments\CommentRateLimiter;
use VoidLabs\Comments\CommentRequest;
use VoidLabs\Comments\CommentStore;

$checks = 0;
$failures = [];

function check_comments(bool $condition, string $message): void
{
    global $checks, $failures;
    ++$checks;
    if (!$condition) {
        $failures[] = $message;
    }
}

function remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (glob($directory . '/*') ?: [] as $path) {
        if (is_dir($path)) {
            remove_tree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'void-comments-standalone-' . bin2hex(random_bytes(6));
$store = new CommentStore($directory);
$firstTime = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
$secondTime = $firstTime->modify('+1 minute');

try {
    $request = CommentRequest::fromArray(
        ['route' => '/article', 'name' => 'Mario', 'email' => 'mario@example.test', 'message' => 'Test'],
        '/article',
        'article',
        ['article']
    );
    check_comments($request->route === '/article', 'Richiesta valida rifiutata.');

    try {
        CommentRequest::fromArray(
            ['route' => '/article', 'name' => 'Mario', 'email' => 'mario@example.test', 'message' => 'Test'],
            '/article',
            'page',
            ['article']
        );
        check_comments(false, 'Template non consentito accettato.');
    } catch (InvalidArgumentException) {
        check_comments(true, 'Template non consentito rifiutato.');
    }

    $parent = $store->addPending($request, '127.0.0.1', $firstTime);
    $parentFile = $directory . '/pending/' . $parent['id'] . '.json';
    $parentData = json_decode((string) file_get_contents($parentFile), true, 512, JSON_THROW_ON_ERROR);
    check_comments(!array_key_exists('status', $parentData), 'Stato ridondante scritto nel pending.');
    check_comments(isset($parentData['technical']['address_sha256']), 'Hash tecnico del rate limit assente.');
    check_comments($store->approve($parent['id'], $firstTime), 'Approvazione del padre fallita.');

    $replyRequest = CommentRequest::fromArray(
        ['route' => '/article', 'parent_id' => $parent['id'], 'name' => 'Luisa', 'email' => 'luisa@example.test', 'message' => 'Risposta'],
        '/article',
        'article',
        ['article']
    );
    $reply = $store->addPending($replyRequest, '127.0.0.1', $secondTime);
    check_comments($store->approve($reply['id'], $secondTime), 'Approvazione della risposta fallita.');

    $thread = $store->approvedForRoute('/article');
    check_comments(count($thread) === 2, 'Thread incompleto.');
    check_comments(($thread[1]['parent_id'] ?? '') === $parent['id'], 'Parent id non conservato.');
    check_comments(($thread[1]['depth'] ?? null) === 1, 'Profondità del thread errata.');
    check_comments($store->approvedParentForRoute($parent['id'], '/article') !== null, 'Padre approvato non trovato.');
    check_comments($store->approvedParentForRoute($parent['id'], '/other') === null, 'Padre accettato su route diversa.');

    check_comments($store->update('approved', $reply['id'], ['body' => 'Censurata', 'email' => '']), 'Aggiornamento amministrativo fallito.');
    $replyData = json_decode((string) file_get_contents($directory . '/approved/' . $reply['id'] . '.json'), true, 512, JSON_THROW_ON_ERROR);
    check_comments(($replyData['body'] ?? '') === 'Censurata' && !array_key_exists('email', $replyData), 'Censura email o body non persistita.');

    $deletable = $store->addPending($request, '127.0.0.3', $secondTime);
    check_comments($store->deletePending($deletable['id']), 'Eliminazione del pending fallita.');
    check_comments(!is_file($directory . '/pending/' . $deletable['id'] . '.json'), 'Pending eliminato ancora presente.');
    check_comments($store->deleteApproved($reply['id']), 'Eliminazione dell’approvato fallita.');
    check_comments(!is_file($directory . '/approved/' . $reply['id'] . '.json'), 'Approvato eliminato ancora presente.');

    $limiterFile = $directory . '/rate-limit.json';
    $limiter = new CommentRateLimiter($limiterFile);
    check_comments($limiter->consume('127.0.0.1', 'one@example.test', 1000), 'Primo tentativo rate limit rifiutato.');
    check_comments($limiter->consume('127.0.0.1', 'one@example.test', 1000), 'Secondo tentativo rate limit rifiutato.');
    check_comments($limiter->consume('127.0.0.1', 'one@example.test', 1000), 'Terzo tentativo rate limit rifiutato.');
    check_comments(!$limiter->consume('127.0.0.1', 'one@example.test', 1000), 'Quarto tentativo rate limit accettato.');
    check_comments($limiter->consume('127.0.0.1', 'one@example.test', 4600), 'Rate limit non scaduto dopo la finestra.');

    $oldRequest = CommentRequest::fromArray(
        ['route' => '/old', 'name' => 'Old', 'email' => 'old@example.test', 'message' => 'Old'],
        '/old',
        'article',
        ['article']
    );
    $old = $store->addPending($oldRequest, '127.0.0.2', new DateTimeImmutable('2025-01-01T00:00:00+00:00'));
    $technicalRequest = CommentRequest::fromArray(
        ['route' => '/technical', 'name' => 'Technical', 'email' => 'technical@example.test', 'message' => 'Technical'],
        '/technical',
        'article',
        ['article']
    );
    $technical = $store->addPending($technicalRequest, '127.0.0.4', new DateTimeImmutable('2025-04-20T00:00:00+00:00'));
    $pruned = $store->prune(new DateTimeImmutable('2025-05-01T00:00:00+00:00'));
    check_comments($pruned['pending_deleted'] === 1, 'Pending scaduto non eliminato.');
    check_comments(!is_file($directory . '/pending/' . $old['id'] . '.json'), 'File pending scaduto ancora presente.');
    $technicalData = json_decode((string) file_get_contents($directory . '/pending/' . $technical['id'] . '.json'), true, 512, JSON_THROW_ON_ERROR);
    check_comments($pruned['technical_removed'] === 1, 'Dati tecnici scaduti non rimossi.');
    check_comments(!array_key_exists('technical', $technicalData), 'Dati tecnici scaduti ancora presenti.');
} finally {
    remove_tree($directory);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}" . PHP_EOL);
    }
    exit(1);
}

echo "OK: {$checks} void-comments checks." . PHP_EOL;
