<?php

declare(strict_types=1);

namespace VoidLabs\Comments;

use Grav\Common\Plugins;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CommentsApiController extends AbstractApiController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $query = trim(mb_substr((string) ($request->getQueryParams()['q'] ?? ''), 0, 200));
        return ApiResponse::create(['schema_version' => 1, 'query' => $query, 'comments' => $this->plugin()->pendingForAdmin($query), 'approved' => $this->plugin()->approvedForAdmin($query)]);
    }
    public function approve(ServerRequestInterface $request): ResponseInterface { return $this->action($request, 'approve'); }
    public function delete(ServerRequestInterface $request): ResponseInterface { return $this->action($request, 'delete'); }
    public function deleteApproved(ServerRequestInterface $request): ResponseInterface { return $this->action($request, 'delete-approved'); }
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $body = $this->getRequestBody($request);
        $id = trim((string) ($body['id'] ?? ''));
        $status = trim((string) ($body['status'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !in_array($status, ['pending', 'approved'], true)) throw new ValidationException('Comment identifier or status is invalid.');
        $changes = [];
        foreach (['author', 'email', 'body'] as $field) {
            if (array_key_exists($field, $body)) $changes[$field] = $body[$field];
        }
        if ($changes === []) throw new ValidationException('No comment fields to update.');
        try {
            $updated = $this->plugin()->updateComment($status, $id, $changes);
        } catch (\InvalidArgumentException $exception) {
            throw new ValidationException($exception->getMessage());
        }
        if (!$updated) throw new ApiException(404, 'Not Found', 'Comment not found.');
        return ApiResponse::create(['message' => 'Comment updated.', 'id' => $id]);
    }
    private function action(ServerRequestInterface $request, string $action): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');
        $id = trim((string) ($this->getRequestBody($request)['id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new ValidationException('Field "id" is invalid.');
        $updated = match ($action) {
            'approve' => $this->plugin()->approveComment($id),
            'delete-approved' => $this->plugin()->deleteApprovedComment($id),
            default => $this->plugin()->deletePendingComment($id),
        };
        if (!$updated) throw new ApiException(404, 'Not Found', 'Comment not found.');
        return ApiResponse::create(['message' => 'Comment ' . $action . 'd.', 'id' => $id]);
    }
    private function plugin(): \Grav\Plugin\VoidCommentsPlugin
    {
        $plugin = Plugins::getPlugin('void-comments');
        if (!$plugin instanceof \Grav\Plugin\VoidCommentsPlugin) throw new ApiException(503, 'Service Unavailable', 'Comments plugin is unavailable.');
        return $plugin;
    }
}
