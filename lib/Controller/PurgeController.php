<?php
declare(strict_types=1);

namespace OCA\RetentionLock\Controller;

use OCA\RetentionLock\Service\PurgeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class PurgeController extends Controller {
    public function __construct(IRequest $request, private PurgeService $purges, private LoggerInterface $logger) {
        parent::__construct('retention_lock', $request);
    }

    // Nextcloud's default middleware requires login, administrator status and
    // CSRF validation. Do not add NoAdminRequired/NoCSRFRequired/PublicPage.
    public function trash(string $ownerUid): JSONResponse {
        try {
            return new JSONResponse(['items' => $this->purges->listTrash($ownerUid)]);
        } catch (\Throwable $error) {
            $this->logger->error('Retention trash listing failed', ['error_class' => get_class($error)]);
            return new JSONResponse(['error' => 'Trash listing unavailable.'], 409);
        }
    }

    public function operation(string $operationId): JSONResponse {
        try {
            return new JSONResponse($this->purges->operation($operationId));
        } catch (\Throwable $error) {
            $this->logger->error('Retention operation lookup failed', ['error_class' => get_class($error)]);
            return new JSONResponse(['error' => 'Operation unavailable.'], 404);
        }
    }

    public function create(string $operationId, string $ownerUid, int $trashId, int $deletedAt, string $reason, string $expectedEtag): JSONResponse {
        try {
            $state = $this->purges->purge($operationId, $ownerUid, $trashId, $deletedAt, $reason, $expectedEtag);
            return new JSONResponse(['operationId' => $operationId, 'state' => $state],
                $state === 'completed' ? 200 : 409);
        } catch (\Throwable $error) {
            // Backend/DB messages can contain paths, query parameters or secrets.
            $this->logger->error('Retention purge failed', ['error_class' => get_class($error)]);
            return new JSONResponse(['error' => 'Purge not completed; inspect the operation record before retrying.'], 409);
        }
    }
}
