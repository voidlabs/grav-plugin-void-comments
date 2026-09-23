<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/CommentRequest.php';
require_once dirname(__DIR__) . '/src/CommentRateLimiter.php';
require_once dirname(__DIR__) . '/src/CommentStore.php';
require_once dirname(__DIR__) . '/src/NotificationTemplate.php';

use VoidLabs\Comments\CommentRateLimiter;
use VoidLabs\Comments\CommentRequest;
use VoidLabs\Comments\CommentStore;
use VoidLabs\Comments\NotificationTemplate;

$checks = 0;
$failures = [];

$renderedNotification = NotificationTemplate::render('Commento {id}: {body} ({moderation_url})', [
    'id' => 'abc123', 'body' => 'Testo', 'moderation_url' => 'https://example.test/modera',
]);
check_comments($renderedNotification === 'Commento abc123: Testo (https://example.test/modera)', 'Placeholder della notifica non sostituiti.');
check_comments(NotificationTemplate::render(NotificationTemplate::DEFAULT_BODY, [
    'route' => '/articolo', 'author' => 'Mario', 'email' => 'mario@example.test', 'id' => 'abc123',
    'body' => 'Testo', 'moderation_url' => 'https://example.test/modera', 'public_url' => 'https://example.test/articolo#comment-abc123',
]) === "Nuovo commento in moderazione\n\nPagina: /articolo\nAutore: Mario\nEmail: mario@example.test\nID: abc123\n\nTesto\n\nApri il commento in moderazione:\nhttps://example.test/modera\n\nVedi la pagina pubblica:\nhttps://example.test/articolo#comment-abc123", 'Template predefinito della notifica modificato inaspettatamente.');

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

    $legacyRequest = CommentRequest::fromArray(
        ['route' => '/legacy', 'name' => 'Legacy Author', 'email' => 'legacy-author@example.test', 'message' => 'Legacy approved record'],
        '/legacy',
        'article',
        ['article']
    );
    $legacyComment = $store->addPending($legacyRequest, '192.0.2.20', $firstTime);
    $legacyCommentFile = $directory . '/pending/' . $legacyComment['id'] . '.json';
    $legacyCommentData = json_decode((string) file_get_contents($legacyCommentFile), true, 512, JSON_THROW_ON_ERROR);
    $legacyCommentData['status'] = 'pending';
    check_comments(file_put_contents($legacyCommentFile, json_encode($legacyCommentData, JSON_THROW_ON_ERROR)) !== false, 'Scrittura della fixture legacy fallita.');
    $legacyApprovedFile = $directory . '/approved/' . $legacyComment['id'] . '.json';
    check_comments(rename($legacyCommentFile, $legacyApprovedFile), 'Spostamento manuale del commento legacy non riuscito.');
    $legacyApproved = $store->approvedForRoute('/legacy');
    check_comments(count($legacyApproved) === 1 && !array_key_exists('status', $legacyApproved[0]), 'La cartella approved determina lo stato e rimuove il campo status legacy.');
    check_comments($store->deleteApproved($legacyComment['id']), 'Pulizia del record legacy approvato fallita.');

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
    check_comments(count($store->approved()) === 2, 'Elenco dei commenti approvati per l amministrazione incompleto.');
    check_comments(count($store->find('approved', 'luisa@example.test')) === 1, 'Ricerca amministrativa dei commenti non trova email e testo.');

    check_comments($store->update('approved', $reply['id'], ['body' => 'Censurata', 'email' => '']), 'Aggiornamento amministrativo fallito.');
    $replyData = json_decode((string) file_get_contents($directory . '/approved/' . $reply['id'] . '.json'), true, 512, JSON_THROW_ON_ERROR);
    check_comments(($replyData['body'] ?? '') === 'Censurata' && !array_key_exists('email', $replyData), 'Censura email o body non persistita.');

    $deletable = $store->addPending($request, '127.0.0.3', $secondTime);
    check_comments($store->deletePending($deletable['id']), 'Eliminazione del pending fallita.');
    check_comments(!is_file($directory . '/pending/' . $deletable['id'] . '.json'), 'Pending eliminato ancora presente.');
    check_comments($store->deleteApproved($reply['id']), 'Eliminazione dell’approvato fallita.');
    check_comments(!is_file($directory . '/approved/' . $reply['id'] . '.json'), 'Approvato eliminato ancora presente.');

    $latestRequest = CommentRequest::fromArray(
        ['route' => '/article', 'name' => 'Latest', 'email' => 'latest@example.test', 'message' => 'Latest'],
        '/article',
        'article',
        ['article']
    );
    $latest = $store->addPending($latestRequest, '127.0.0.5', $secondTime->modify('+1 day'));
    check_comments($store->approve($latest['id'], $secondTime->modify('+1 day')), 'Approvazione del commento più recente fallita.');
    $articlePage = $store->page('approved', '', '/article', 1, 1);
    check_comments($articlePage['pagination']['total'] === 2 && $articlePage['pagination']['pages'] === 2, 'Paginazione commenti o filtro per route errati.');
    check_comments(($articlePage['items'][0]['id'] ?? '') === $latest['id'], 'Commenti amministrativi non ordinati dal più recente.');
    $focusedPage = $store->page('approved', '', '/article', 1, 1, $parent['id']);
    check_comments($focusedPage['pagination']['current'] === 2 && ($focusedPage['items'][0]['id'] ?? '') === $parent['id'], 'Focus amministrativo sul commento non porta alla pagina corretta.');

    $limiterFile = $directory . '/rate-limit.json';
    $limiter = new CommentRateLimiter($limiterFile);
    check_comments($limiter->consume('127.0.0.1', 'one@example.test', 1000), 'Primo tentativo rate limit rifiutato.');
    check_comments($limiter->consume('127.0.0.1', 'one@example.test', 1000), 'Secondo tentativo rate limit rifiutato.');
    check_comments($limiter->consume('127.0.0.1', 'one@example.test', 1000), 'Terzo tentativo rate limit rifiutato.');
    check_comments(!$limiter->consume('127.0.0.1', 'one@example.test', 1000), 'Quarto tentativo rate limit accettato.');
    check_comments($limiter->consume('127.0.0.1', 'one@example.test', 4600), 'Rate limit non scaduto dopo la finestra.');

    $independentFile = $directory . '/independent-rate-limit.json';
    $independent = new CommentRateLimiter($independentFile, 3600, 3);
    check_comments($independent->consume('192.0.2.1', 'a@example.test', 1000), 'Primo tentativo per IP accettato.');
    check_comments($independent->consume('192.0.2.1', 'b@example.test', 1000), 'La rotazione email non dovrebbe consumare la quota IP.');
    check_comments($independent->consume('192.0.2.1', 'c@example.test', 1000), 'La terza richiesta dallo stesso IP è stata accettata.');
    check_comments(!$independent->consume('192.0.2.1', 'd@example.test', 1000), 'Cambiare email aggira il limite per IP.');
    check_comments($independent->consume('192.0.2.2', 'same@example.test', 1000), 'Primo tentativo per email accettato.');
    check_comments($independent->consume('192.0.2.3', 'same@example.test', 1000), 'La seconda richiesta per email è stata accettata.');
    check_comments($independent->consume('192.0.2.4', 'same@example.test', 1000), 'La terza richiesta per email è stata accettata.');
    check_comments(!$independent->consume('192.0.2.5', ' same@example.test ', 1000), 'Cambiare IP o spazi aggira il limite per email.');
    $independentData = json_decode((string) file_get_contents($independentFile), true, 512, JSON_THROW_ON_ERROR);
    check_comments(isset($independentData['ip:' . hash('sha256', '192.0.2.1')]) && isset($independentData['email:' . hash('sha256', 'a@example.test')]), 'I contatori indipendenti non sono persistiti con chiavi hashate.');

    $legacyFile = $directory . '/legacy-rate-limit.json';
    $legacyKey = hash('sha256', "192.0.2.4\0legacy@example.test");
    file_put_contents($legacyFile, json_encode([$legacyKey => [1000, 1001, 1002]], JSON_THROW_ON_ERROR));
    $legacyLimiter = new CommentRateLimiter($legacyFile, 3600, 3);
    check_comments(!$legacyLimiter->consume('192.0.2.4', 'legacy@example.test', 1002), 'Il formato 0.1.x non conserva la quota attiva della coppia.');
    file_put_contents($legacyFile, '{broken');
    check_comments(!$legacyLimiter->consume('192.0.2.4', 'legacy@example.test', 1002), 'JSON corrotto disabilita il rate limit invece di aprirlo.');

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
