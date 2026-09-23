<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

require_once __DIR__ . '/src/CommentRequest.php';
require_once __DIR__ . '/src/CommentRateLimiter.php';
require_once __DIR__ . '/src/CommentStore.php';
require_once __DIR__ . '/src/NotificationTemplate.php';

final class VoidCommentsPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onFormPageHeaderProcessed' => ['onFormPageHeaderProcessed', 10],
            'onFormProcessed' => ['onFormProcessed', 10],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
        ];
    }

    public function autoload(): ClassLoader
    {
        $loader = new ClassLoader();
        $loader->addPsr4('VoidLabs\\Comments\\', __DIR__ . '/src');
        $loader->register();
        return $loader;
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $routes->get('/void-comments-admin', [\VoidLabs\Comments\CommentsApiController::class, 'index']);
        $routes->post('/void-comments-admin/approve', [\VoidLabs\Comments\CommentsApiController::class, 'approve']);
        $routes->post('/void-comments-admin/delete', [\VoidLabs\Comments\CommentsApiController::class, 'delete']);
        $routes->post('/void-comments-admin/delete-approved', [\VoidLabs\Comments\CommentsApiController::class, 'deleteApproved']);
        $routes->post('/void-comments-admin/update', [\VoidLabs\Comments\CommentsApiController::class, 'update']);
    }

    public function onApiSidebarItems(Event $event): void
    {
        $user = $event['user'] ?? null;
        if (!$user || !(bool) $user->get('access.api.super')) return;
        $items = $event['items'] ?? [];
        $items[] = ['id' => 'void-comments', 'plugin' => 'void-comments', 'label' => 'Commenti', 'icon' => 'fa-comments', 'route' => '/plugin/void-comments', 'priority' => 80, 'badgeEndpoint' => null];
        $event['items'] = $items;
    }

    public function onTwigSiteVariables(): void
    {
        $page = $this->grav['page'];
        $comments = [];
        $replyTarget = null;
        $enabled = $page && $this->commentsEnabled($page);
        if ($enabled) {
            $route = (string) $page->route();
            $comments = $this->store()->approvedForRoute($route);
            $replyTarget = $this->replyTarget($route);
            $this->grav['assets']->addJs('plugin://void-comments/assets/replies.js', ['group' => 'bottom']);
            $this->grav['assets']->addJs('plugin://void-comments/assets/comments-form.js', ['group' => 'bottom']);
            $this->grav['assets']->addCss('plugin://void-comments/assets/comments-form.css');
        }
        $this->grav['twig']->twig_vars['void_approved_comments'] = $comments;
        $this->grav['twig']->twig_vars['void_comment_reply_to'] = $replyTarget;
        $this->grav['twig']->twig_vars['void_comments_enabled'] = $enabled;
    }

    public function pendingForAdmin(string $query = ''): array { $store = $this->store(); $store->prune(); return $store->find('pending', $query); }
    public function approvedForAdmin(string $query = ''): array { return $this->store()->find('approved', $query); }
    public function pendingPageForAdmin(string $query = '', string $route = '', int $page = 1, int $perPage = 20, ?string $focusId = null): array
    {
        $store = $this->store();
        $store->prune();
        return $store->page('pending', $query, $route, $page, $perPage, $focusId);
    }
    public function approvedPageForAdmin(string $query = '', string $route = '', int $page = 1, int $perPage = 20, ?string $focusId = null): array
    {
        return $this->store()->page('approved', $query, $route, $page, $perPage, $focusId);
    }
    public function publicCommentUrl(array $comment): string
    {
        $base = rtrim((string) ($this->grav['base_url_absolute'] ?? $this->grav['base_url'] ?? $this->grav['base_url_relative'] ?? ''), '/');
        $route = '/' . ltrim((string) ($comment['route'] ?? ''), '/');
        $id = rawurlencode((string) ($comment['id'] ?? ''));
        return $base . $route . ($id === '' ? '' : '#comment-' . $id);
    }
    public function adminCommentUrl(array $comment): string
    {
        $base = rtrim((string) ($this->grav['base_url_absolute'] ?? $this->grav['base_url'] ?? ''), '/');
        return $base . '/admin/plugin/void-comments?route=' . rawurlencode((string) ($comment['route'] ?? '')) . '&focus=' . rawurlencode((string) ($comment['id'] ?? ''));
    }
    public function approveComment(string $id): bool { return $this->store()->approve($id); }
    public function deletePendingComment(string $id): bool { return $this->store()->deletePending($id); }
    public function deleteApprovedComment(string $id): bool { return $this->store()->deleteApproved($id); }
    public function updateComment(string $status, string $id, array $changes): bool { return $this->store()->update($status, $id, $changes); }

    public function onFormPageHeaderProcessed(Event $event): void
    {
        $page = $event['page']; $header = $event['header'];
        if (!$this->commentsEnabled($page) || isset($header->form)) return;
        $route = (string) $page->route();
        $fieldClasses = (array) $this->config->get('plugins.void-comments.field_outerclasses', []);
        $header->form = [
            'name' => 'void-comment', 'id' => 'void-comments-form', 'action' => $route, 'keep_alive' => true,
            'fields' => [
                'route' => ['type' => 'hidden', 'default' => $route], 'parent_id' => ['type' => 'hidden', 'default' => $this->replyTarget($route)['id'] ?? ''],
                'name' => ['type' => 'text', 'label' => 'Nome', 'outerclasses' => (string) ($fieldClasses['name'] ?? ''), 'autocomplete' => 'name', 'validate' => ['required' => true, 'max' => 120]],
                'email' => ['type' => 'email', 'label' => 'Email (non sarà pubblicata)', 'outerclasses' => (string) ($fieldClasses['email'] ?? ''), 'autocomplete' => 'email', 'validate' => ['required' => true, 'type' => 'email']],
                'message' => ['type' => 'textarea', 'label' => 'Commento', 'rows' => 7, 'validate' => ['required' => true, 'max' => 5000]],
                'captcha' => ['type' => 'captcha', 'provider' => 'cap', 'mode' => 'checkbox', 'captcha_not_validated' => 'Completa la verifica anti-spam prima di inviare il commento.', 'validate' => ['required' => true]],
                'website' => ['type' => 'honeypot'],
            ],
            'buttons' => ['submit' => ['type' => 'submit', 'value' => 'Invia il commento']],
            'process' => ['captcha' => true, 'void-comment' => true],
        ];
        $event->header = $header;
    }

    public function onFormProcessed(Event $event): void
    {
        if ($event['action'] !== 'void-comment') return;
        $event->stopPropagation(); $form = $event['form']; $page = $this->grav['page'];
        try {
            $allowedTemplates = $this->templates();
            $request = \VoidLabs\Comments\CommentRequest::fromArray([
                'route' => $form->value('route'), 'parent_id' => $form->value('parent_id'), 'name' => $form->value('name'),
                'email' => $form->value('email'), 'message' => $form->value('message'),
            ], (string) $this->grav['uri']->path(), $page ? (string) $page->template() : '', $allowedTemplates);
        } catch (\InvalidArgumentException $exception) { $form->setError($exception->getMessage()); return; }
        $store = $this->store();
        if ($request->parentId !== null && $store->approvedParentForRoute($request->parentId, $request->route) === null) {
            $form->setError('Il commento a cui stai rispondendo non è disponibile.'); return;
        }
        $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'); $directory = $this->storageDirectory();
        if (!(new \VoidLabs\Comments\CommentRateLimiter($directory . '/rate-limit.json'))->consume($address, $request->email)) {
            $form->setError('Sono stati inviati troppi commenti. Riprova più tardi.'); return;
        }
        try { $store->prune(); $record = $store->addPending($request, $address); }
        catch (\Throwable $exception) { $this->grav['log']->error('Comment persistence failed (' . $exception::class . ').'); $form->setError('Il commento non è stato salvato. Riprova più tardi.'); return; }
        $this->notifyModerator($record);
        $this->grav['messages']->add('Grazie. Il commento è stato ricevuto e sarà pubblicato dopo la moderazione.', 'success');
        $anchor = trim((string) $this->config->get('plugins.void-comments.success_anchor', 'commenti'), "#/ ");
        $this->grav->redirect($request->route . ($anchor === '' ? '' : '#' . $anchor));
    }

    /** @return list<string> */
    private function templates(): array { return array_values(array_filter(array_map('strval', (array) $this->config->get('plugins.void-comments.templates', [])))); }

    private function commentsEnabled(object $page): bool
    {
        return (bool) ($page->header()->void_comments ?? false) || in_array((string) $page->template(), $this->templates(), true);
    }

    private function notifyModerator(array $record): void
    {
        $recipient = trim((string) $this->config->get('plugins.void-comments.moderator_to', ''));
        if ($recipient === '') $recipient = trim((string) getenv('VOID_COMMENTS_MODERATOR_TO'));
        if ($recipient === false || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || !isset($this->grav['Email'])) {
            $this->grav['log']->notice('Void Comments pending; moderator email is not configured.'); return;
        }
        try {
            $template = (string) $this->config->get('plugins.void-comments.moderator_body', \VoidLabs\Comments\NotificationTemplate::DEFAULT_BODY);
            $body = \VoidLabs\Comments\NotificationTemplate::render($template, [
                'route' => (string) ($record['route'] ?? ''),
                'author' => (string) ($record['author'] ?? ''),
                'email' => (string) ($record['email'] ?? ''),
                'id' => (string) ($record['id'] ?? ''),
                'body' => (string) ($record['body'] ?? ''),
                'moderation_url' => $this->adminCommentUrl($record),
                'public_url' => $this->publicCommentUrl($record),
            ]);
            $message = $this->grav['Email']->buildMessage(['to' => $recipient, 'reply_to' => $record['email'], 'subject' => (string) $this->config->get('plugins.void-comments.moderator_subject', '[Blog] Nuovo commento da moderare'), 'body' => $body, 'content_type' => 'text/plain']);
            if ($this->grav['Email']->send($message) < 1) $this->grav['log']->warning('Comment moderation email was not sent.');
        } catch (\Throwable $exception) { $this->grav['log']->warning('Comment moderation email failed (' . $exception::class . ').'); }
    }

    private function storageDirectory(): string
    {
        $dataDirectory = $this->grav['locator']->findResource('user-data://', true);
        if (!is_string($dataDirectory) || $dataDirectory === '') throw new \RuntimeException('Directory dati del sito non disponibile.');
        return rtrim($dataDirectory, '/\\') . '/void-comments';
    }

    private function replyTarget(string $route): ?array
    {
        $id = isset($_GET['reply_to']) && is_string($_GET['reply_to']) ? trim($_GET['reply_to']) : '';
        if ($id === '' || !preg_match('/^(?:legacy-)?[0-9a-f-]{1,64}$/i', $id)) return null;
        return $this->store()->approvedParentForRoute($id, $route);
    }

    private function store(): \VoidLabs\Comments\CommentStore { return new \VoidLabs\Comments\CommentStore($this->storageDirectory()); }
}
