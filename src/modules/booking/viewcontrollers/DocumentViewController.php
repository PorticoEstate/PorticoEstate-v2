<?php

namespace App\modules\booking\viewcontrollers;

use App\modules\booking\authorization\DocumentBuildingAuthConfig;
use App\modules\booking\authorization\EntityAuthConfig;
use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\booking\models\Document;
use App\modules\booking\repositories\PermissionRepository;
use App\modules\phpgwapi\services\AuthorizationService;
use App\modules\booking\services\DocumentService;
use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class DocumentViewController
{
	protected DocumentService $documentService;
	protected AuthorizationService $authService;
	protected EntityAuthConfig $authConfig;
	protected TwigHelper $twig;
	protected LegacyViewHelper $legacyView;

	public function __construct(string $ownerType = Document::OWNER_BUILDING)
	{
		$this->documentService = new DocumentService($ownerType);
		$this->authService = new AuthorizationService(new PermissionRepository());
		$this->authConfig = new DocumentBuildingAuthConfig();
		// LegacyViewHelper must be created BEFORE TwigHelper so that template_set
		// is resolved from user prefs before the DesignSystem singleton is created.
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('booking');
	}

	public function list(Request $request, Response $response): Response
	{
		try {
			if ($this->authService->authorize($this->authConfig, 'read') === false) {
				return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
			}

			$canCreate = $this->authService->authorize($this->authConfig, 'create') !== false;
			$canWrite = $this->authService->authorize($this->authConfig, 'write') !== false;
			$canDelete = $this->authService->authorize($this->authConfig, 'delete') !== false;

			$componentHtml = $this->twig->render('@views/documents/list/document_list.twig', [
				'layout' => '@views/_bare.twig',
				'can_create' => $canCreate,
				'can_write' => $canWrite,
				'can_delete' => $canDelete,
			]);

			$html = $this->legacyView->render($componentHtml, ['booking', 'buildings', 'documents']);

			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading document list: ' . $e->getMessage()],
				500
			);
		}
	}

	public function edit(Request $request, Response $response, array $args): Response
	{
		$documentId = (int)$args['id'];

		try {
			$document = $this->documentService->getDocumentById($documentId);

			if (!$document) {
				return ResponseHelper::sendErrorResponse(['error' => 'Document not found'], 404);
			}

			$ownerId = $document->owner_id;

			if ($this->authService->authorize($this->authConfig, 'write', [
				'id' => $documentId,
				'owner_id' => $ownerId,
			]) === false) {
				return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
			}

			$componentHtml = $this->twig->render('@views/documents/edit/document_edit.twig', [
				'document' => $document->serialize(),
				'owner_id' => $ownerId,
				'building_name' => $this->getBuildingName($ownerId),
				'layout' => '@views/_bare.twig',
			]);

			$html = $this->legacyView->render($componentHtml, ['booking', 'buildings', 'documents']);

			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading document editor: ' . $e->getMessage()],
				500
			);
		}
	}

	private function getBuildingName(int $buildingId): string
	{
		$db = \App\Database\Db::getInstance();
		$stmt = $db->prepare("SELECT name FROM bb_building WHERE id = :id");
		$stmt->execute([':id' => $buildingId]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row ? $row['name'] : '';
	}
}
