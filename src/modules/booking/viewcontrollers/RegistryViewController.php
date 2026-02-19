<?php

namespace App\modules\booking\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use App\modules\booking\models\BookingGenericRegistry;
use App\modules\booking\viewcontrollers\RegistryMenuConfig;
use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class RegistryViewController
{
	protected Acl $acl;
	protected TwigHelper $twig;
	protected LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->acl = Acl::getInstance();
		// LegacyViewHelper must be created BEFORE TwigHelper so that template_set
		// is resolved from user prefs before the DesignSystem singleton is created.
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('booking');
	}

	public function index(Request $request, Response $response, array $args): Response
	{
		$type = $args['type'] ?? '';

		try {
			$config = $this->getValidatedConfig($type);
			if (!$config) {
				return ResponseHelper::sendErrorResponse(['error' => "Registry type '{$type}' not found"], 404);
			}

			if (!$this->acl->check($config['acl_location'], Acl::READ, $config['acl_app'])) {
				return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
			}

			$permissions = $this->getPermissions($config);
			$displayName = $this->getDisplayName($type, $config);
			$menuSelection = $this->getMenuSelection($type, $config);
			$this->setAppHeader($displayName);

			$componentHtml = $this->twig->render('@views/registry/list/registry_list.twig', [
				'layout' => '@views/_bare.twig',
				'registry_type' => $type,
				'registry_name' => $displayName,
				'permissions' => $permissions,
			]);

			$html = $this->legacyView->render($componentHtml, 'booking', $menuSelection);

			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading registry list: ' . $e->getMessage()],
				500
			);
		}
	}

	public function edit(Request $request, Response $response, array $args): Response
	{
		$type = $args['type'] ?? '';
		$id = isset($args['id']) ? (int)$args['id'] : null;
		$isNew = ($id === null);

		try {
			$config = $this->getValidatedConfig($type);
			if (!$config) {
				return ResponseHelper::sendErrorResponse(['error' => "Registry type '{$type}' not found"], 404);
			}

			$requiredPermission = $isNew ? Acl::ADD : Acl::EDIT;
			if (!$this->acl->check($config['acl_location'], $requiredPermission, $config['acl_app'])) {
				return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
			}

			$displayName = $this->getDisplayName($type, $config);
			$menuSelection = $this->getMenuSelection($type, $config);
			$actionLabel = $isNew ? lang('add') : lang('Edit');
			$this->setAppHeader($displayName, $actionLabel);

			$componentHtml = $this->twig->render('@views/registry/edit/registry_edit.twig', [
				'layout' => '@views/_bare.twig',
				'registry_type' => $type,
				'registry_name' => $displayName,
				'item_id' => $id,
				'is_new' => $isNew,
			]);

			$html = $this->legacyView->render($componentHtml, 'booking', $menuSelection);

			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading registry form: ' . $e->getMessage()],
				500
			);
		}
	}

	private function getValidatedConfig(string $type): ?array
	{
		if (!$type || !in_array($type, BookingGenericRegistry::getAvailableTypes())) {
			return null;
		}
		return BookingGenericRegistry::getRegistryConfig($type);
	}

	private function getPermissions(array $config): array
	{
		$location = $config['acl_location'] ?? '.admin';
		$app = $config['acl_app'] ?? 'booking';
		return [
			'read' => $this->acl->check($location, Acl::READ, $app),
			'create' => $this->acl->check($location, Acl::ADD, $app),
			'write' => $this->acl->check($location, Acl::EDIT, $app),
			'delete' => $this->acl->check($location, Acl::DELETE, $app),
		];
	}

	private function getMenuSelection(string $type, array $config): string
	{
		$entry = RegistryMenuConfig::get($type);
		if ($entry) {
			return $entry['menu_selection'];
		}
		if (!empty($config['menu_selection'])) {
			return $config['menu_selection'];
		}
		return 'booking::settings';
	}

	private function getDisplayName(string $type, array $config): string
	{
		$entry = RegistryMenuConfig::get($type);
		if ($entry) {
			return lang($entry['text_key']);
		}
		return $config['name'] ?? ucfirst(str_replace('_', ' ', $type));
	}

	private function setAppHeader(string $displayName, string $action = ''): void
	{
		$flags = Settings::getInstance()->get('flags');
		$header = lang('booking') . '::' . $displayName;
		if ($action) {
			$header .= '::' . $action;
		}
		$flags['app_header'] = $header;
		Settings::getInstance()->set('flags', $flags);
	}
}
