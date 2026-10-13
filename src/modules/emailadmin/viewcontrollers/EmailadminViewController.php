<?php

namespace App\modules\emailadmin\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;

class EmailadminViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('emailadmin');
	}

	private function isAdmin(): bool
	{
		$user = Settings::getInstance()->get('user');
		return !empty($user['apps']['admin']);
	}

	private function csrf(Request $request): array
	{
		$name = (string) ($request->getAttribute('csrf_name') ?? '');
		$value = (string) ($request->getAttribute('csrf_value') ?? '');
		if ($name === '' || $value === '')
		{
			$csrfStorage = null;
			$guard = new Guard(new \Slim\Psr7\Factory\ResponseFactory(), 'csrf', $csrfStorage, null, 200, 16, true);
			$request = $guard->appendNewTokenToRequest($request);
			$name = (string) $request->getAttribute('csrf_name');
			$value = (string) $request->getAttribute('csrf_value');
		}
		return ['name' => $name, 'value' => $value];
	}

	private function options(): array
	{
		$bo = \CreateObject('emailadmin.emailadmin_bo', true);
		$accounts_obj = new Accounts();
		$apps = [];
		foreach ((array) (Settings::getInstance()->get('apps') ?: []) as $app => $details)
		{
			if (in_array($app, ['calendar', 'felamimail'], true))
			{
				$apps[] = ['id' => $app, 'name' => (string) ($details['title'] ?? lang($app))];
			}
		}
		usort($apps, static fn($left, $right) => strcasecmp($left['name'], $right['name']));

		$groups = [['id' => 0, 'name' => lang('any group')]];
		$users = [['id' => 0, 'name' => lang('any user')]];
		foreach ((array) $accounts_obj->get_list('groups') as $group)
		{
			$groups[] = ['id' => (int) $group->id, 'name' => (string) $group->lid];
		}
		foreach ((array) $accounts_obj->get_list('accounts') as $user)
		{
			$users[] = ['id' => (int) $user->id, 'name' => (string) $user->lid];
		}
		usort($groups, static fn($left, $right) => $left['id'] === 0 ? ($right['id'] === 0 ? 0 : -1) : ($right['id'] === 0 ? 1 : strcasecmp($left['name'], $right['name'])));
		usort($users, static fn($left, $right) => $left['id'] === 0 ? ($right['id'] === 0 ? 0 : -1) : ($right['id'] === 0 ? 1 : strcasecmp($left['name'], $right['name'])));

		$smtp = [];
		foreach ($bo->getSMTPServerTypes() as $id => $name)
		{
			$smtp[(string) $id] = ['name' => $name, 'fields' => $bo->getFieldNames($id, 'smtp')];
		}
		$imap = [];
		foreach ($bo->getIMAPServerTypes() as $id => $type)
		{
			$imap[(string) $id] = ['name' => $type['description'], 'fields' => $bo->getFieldNames($id, 'imap')];
		}
		return compact('apps', 'groups', 'users', 'smtp', 'imap');
	}

	public function index(Request $request, Response $response): Response
	{
		if (!$this->isAdmin()) return $response->withStatus(403);
		Settings::getInstance()->update('flags', ['app_header' => lang('Email administration') . ': ' . lang('profiles')]);
		$html = $this->twig->render('@views/profiles/emailadmin_list.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/emailadmin/profiles'),
			'add_url' => \phpgw::link('/emailadmin/view/profiles/add'),
			'edit_url_template' => \phpgw::link('/emailadmin/view/profiles/__PROFILE_ID__/edit'),
			'csrf' => $this->csrf($request),
		]);
		$response->getBody()->write($this->legacyView->render($html, ['admin', 'emailadmin'], 'admin::emailadmin'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function add(Request $request, Response $response): Response
	{
		return $this->editor($request, $response, 0);
	}

	public function edit(Request $request, Response $response, array $args): Response
	{
		return $this->editor($request, $response, (int) ($args['id'] ?? 0));
	}

	public function editUserData(Request $request, Response $response, array $args): Response
	{
		if (!$this->isAdmin()) return $response->withStatus(403);
		$id = (int) ($args['id'] ?? 0);
		$accountName = $id > 0 ? (new Accounts())->id2name($id) : false;
		if (!$accountName) return $response->withStatus(404);

		$title = lang('edit email settings');
		Settings::getInstance()->update('flags', ['app_header' => lang('Email administration') . ': ' . $title]);
		$html = $this->twig->render('@views/users/emailadmin_user_edit.twig', [
			'layout' => '@views/_bare.twig',
			'account_id' => $id,
			'account_name' => (string) $accountName,
			'api_url' => \phpgw::link('/emailadmin/users/' . $id),
			'back_url' => \phpgw::link('/admin/accounts.php'),
			'csrf' => $this->csrf($request),
		]);
		$response->getBody()->write($this->legacyView->render($html, ['admin', 'emailadmin'], 'admin::emailadmin'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	private function editor(Request $request, Response $response, int $id): Response
	{
		if (!$this->isAdmin()) return $response->withStatus(403);
		$title = $id ? lang('edit profile') : lang('add profile');
		Settings::getInstance()->update('flags', ['app_header' => lang('Email administration') . ': ' . $title]);
		$html = $this->twig->render('@views/profiles/emailadmin_edit.twig', array_merge($this->options(), [
			'layout' => '@views/_bare.twig',
			'profile_id' => $id,
			'api_url' => \phpgw::link('/emailadmin/profiles'),
			'list_url' => \phpgw::link('/emailadmin/view/profiles'),
			'user_edit_url_template' => \phpgw::link('/emailadmin/view/users/__USER_ID__/edit'),
			'csrf' => $this->csrf($request),
		]));
		$response->getBody()->write($this->legacyView->render($html, ['admin', 'emailadmin'], 'admin::emailadmin'));
		return $response->withHeader('Content-Type', 'text/html');
	}
}