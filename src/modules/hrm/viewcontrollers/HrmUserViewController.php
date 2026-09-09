<?php

namespace App\modules\hrm\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HrmUserViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('hrm');
	}

	public function index(Request $request, Response $response): Response
	{
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('user') . ': ' . lang('list user')]);
		$user = Settings::getInstance()->get('user');
		$rowsPerPage = isset($user['preferences']['common']['maxmatchs']) && (int) $user['preferences']['common']['maxmatchs'] > 0
			? (int) $user['preferences']['common']['maxmatchs']
			: 10;

		$html = $this->twig->render('@views/user/hrm_user_list.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/hrm/users'),
			'training_url_template' => \phpgw::link('/hrm/view/users/__USER_ID__/training'),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => [$rowsPerPage, $rowsPerPage * 2, $rowsPerPage * 3],
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'user'], 'hrm::user'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function training(Request $request, Response $response, array $args): Response
	{
		$userId = (int) ($args['id'] ?? 0);
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('Training')]);
		$html = $this->twig->render('@views/user/hrm_user_training.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/hrm/users/' . $userId . '/training'),
			'list_url' => \phpgw::link('/hrm/view/users'),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'user', 'training'], 'hrm::user'));
		return $response->withHeader('Content-Type', 'text/html');
	}
}
