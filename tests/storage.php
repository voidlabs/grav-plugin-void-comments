<?php
declare(strict_types=1);
namespace VoidLabs\Comments {
    // Inject short writes and disk failure into the real atomic writer.
    function fwrite($stream, string $data): int|false {
        if (($GLOBALS['fail_write'] ?? false)) return false;
        return \fwrite($stream, substr($data, 0, $GLOBALS['write_chunk'] ?? strlen($data)));
    }
}
namespace {
require_once dirname(__DIR__) . '/src/CommentStore.php';
require_once dirname(__DIR__) . '/src/CommentRequest.php';
require_once dirname(__DIR__) . '/src/CommentRateLimiter.php';
use VoidLabs\Comments\AtomicJson;
use VoidLabs\Comments\CommentStore;
use VoidLabs\Comments\CommentRequest;
use VoidLabs\Comments\CommentRateLimiter;

if (($argv[1] ?? '') === 'worker') {
    [$script, $worker, $kind, $dir, $id] = $argv;
    $store = new CommentStore($dir);
    $result = match ($kind) {
        'limit' => (new CommentRateLimiter($dir . '/limits.json', 3600, 7))->consume('127.0.0.1', 'test@example.test', 1000),
        'body' => $store->update('pending', $id, ['body' => 'Edited body']),
        'author' => $store->update('pending', $id, ['author' => 'Edited author']),
        'approve' => $store->approve($id),
        'prune' => $store->prune(new DateTimeImmutable('2030-01-01T00:00:00+00:00')),
    };
    echo json_encode($result);
    exit;
}
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
};
$dir = sys_get_temp_dir() . '/void-storage-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$spawn = static function (string $kind, string $dir, string $id = ''): array {
    $command = [PHP_BINARY];
    if ($ini = php_ini_loaded_file()) array_push($command, '-c', $ini);
    array_push($command, __FILE__, 'worker', $kind, $dir, $id);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start worker.');
    fclose($pipes[0]);
    return [$process, $pipes];
};
$join = static function (array $worker): mixed {
    [$process, $pipes] = $worker;
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException($errors);
    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
};
try {
    $file = $dir . '/atomic.json';
    $GLOBALS['write_chunk'] = 7;
    AtomicJson::write($file, ['text' => str_repeat('abc', 200)]);
    $assert(strlen(AtomicJson::read($file)['text']) === 600, 'Short writes truncated JSON.');
    $before = file_get_contents($file); $GLOBALS['fail_write'] = true;
    try { AtomicJson::write($file, ['new' => true]); throw new LogicException('Failure was ignored.'); }
    catch (RuntimeException) {}
    $GLOBALS['fail_write'] = false;
    $assert(file_get_contents($file) === $before && glob($file . '.tmp-*') === [], 'Failed write damaged original or leaked temp.');
    file_put_contents($dir . '/empty.json', '');
    $assert(!(new CommentRateLimiter($dir . '/empty.json'))->consume('a', 'b'), 'Empty limiter failed open.');
    $workers = [];
    for ($i = 0; $i < 16; ++$i) $workers[] = $spawn('limit', $dir);
    $assert(count(array_filter(array_map($join, $workers))) === 7, 'Concurrent rate limit exceeded or lost updates.');
    $store = new CommentStore($dir);
    $request = CommentRequest::fromArray(['route' => '/article', 'name' => 'Author', 'email' => 'test@example.test', 'message' => 'Body'], '/article', 'article', ['article']);
    $record = $store->addPending($request, '127.0.0.1'); $id = $record['id'];
    $lock = fopen($dir . '/.storage.lock', 'c+b'); flock($lock, LOCK_EX);
    $workers = [$spawn('author', $dir, $id), $spawn('body', $dir, $id)];
    usleep(200000); flock($lock, LOCK_UN); fclose($lock);
    $assert(array_map($join, $workers) === [true, true], 'Concurrent edits failed.');
    $changed = $store->pending()[0];
    $assert($changed['author'] === 'Edited author' && $changed['body'] === 'Edited body', 'Concurrent edits lost a field.');
    $preview = $store->prune(new DateTimeImmutable('2030-01-01T00:00:00+00:00'), true);
    $assert($preview['pending_deleted'] === 1 && count($store->pending()) === 1, 'Retention preview modified data.');
    // Model a crash between publishing approved and removing pending.
    $approved = $changed; $approved['body'] = 'Published edit'; unset($approved['technical']);
    AtomicJson::write($dir . '/approved/' . $id . '.json', $approved);
    $assert($store->pending() === [], 'Interrupted approval exposed duplicate pending.');
    $assert($store->approve($id) && $store->approved()[0]['body'] === 'Published edit', 'Approval retry overwrote published edit.');
    AtomicJson::write($dir . '/pending/' . $id . '.json', $record);
    $assert($store->deleteApproved($id) && !is_file($dir . '/pending/' . $id . '.json'), 'Delete left a resumable stale pending.');
    $record = $store->addPending($request, '127.0.0.1', new DateTimeImmutable('2020-01-01T00:00:00+00:00'));
    $workers = [$spawn('body', $dir, $record['id']), $spawn('prune', $dir, $record['id'])];
    array_map($join, $workers);
    $assert($store->pending() === [], 'Concurrent edit resurrected expired comment.');
    echo "OK: {$checks} storage checks, including 20 worker processes.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
}
