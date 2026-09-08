<?php

namespace App\modules\sms\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use Slim\Csrf\Guard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SmsCommandViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;
	public function __construct() { $this->twig = new TwigHelper('sms'); $this->legacyView = new LegacyViewHelper(); }
	private function render(Request $request, Response $response, string $template, array $data): Response
	{
		$csrfName = (string) ($request->getAttribute('csrf_name') ?? '');
		$csrfValue = (string) ($request->getAttribute('csrf_value') ?? '');
		if ($csrfName === '' || $csrfValue === '') { $storage = null; $guard = new Guard(new \Slim\Psr7\Factory\ResponseFactory(), 'csrf', $storage, null, 200, 16, true); $request = $guard->appendNewTokenToRequest($request); $csrfName = (string) $request->getAttribute('csrf_name'); $csrfValue = (string) $request->getAttribute('csrf_value'); }
		$html = $this->legacyView->render($this->twig->render($template, array_merge($data, ['layout' => '@views/_bare.twig', 'csrf' => ['name' => $csrfName, 'value' => $csrfValue]])), ['sms', 'command']);
		$response->getBody()->write($html);
		return $response->withHeader('Content-Type', 'text/html');
	}
	private function deny(Response $response): Response { $response->getBody()->write(lang('Access not permitted')); return $response->withStatus(403)->withHeader('Content-Type', 'text/plain'); }
	public function index(Request $request, Response $response): Response { if (!Acl::getInstance()->check('.command', Acl::READ, 'sms')) return $this->deny($response); Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . lang('commands')]); return $this->render($request, $response, '@views/command/sms_command_list.twig', ['api_url' => \phpgw::link('/sms/commands'), 'add_url' => \phpgw::link('/sms/view/command/edit'), 'delete_url_template' => \phpgw::link('/sms/view/command/{id}/delete')]); }
	public function edit(Request $request, Response $response, array $args): Response { $id = (int) ($args['id'] ?? 0); if (!Acl::getInstance()->check($id ? '.command' : '.command', $id ? Acl::EDIT : Acl::ADD, 'sms')) return $this->deny($response); $item = $id ? (array) \CreateObject('sms.bocommand', true)->read_single_command($id) : []; return $this->render($request, $response, '@views/command/sms_command_edit.twig', ['api_url' => $id ? \phpgw::link('/sms/commands/' . $id) : \phpgw::link('/sms/commands'), 'list_url' => \phpgw::link('/sms/view/command'), 'command' => $item, 'types' => [['id' => 'php', 'name' => 'php code'], ['id' => 'shell', 'name' => 'Command or shell script']]]); }
	public function log(Request $request, Response $response): Response { if (!Acl::getInstance()->check('.command', Acl::READ, 'sms')) return $this->deny($response); return $this->render($request, $response, '@views/command/sms_command_log.twig', ['api_url' => \phpgw::link('/sms/commands/log')]); }
	public function delete(Request $request, Response $response, array $args): Response { if (!Acl::getInstance()->check('.command', Acl::DELETE, 'sms')) return $this->deny($response); return $this->render($request, $response, '@views/command/sms_command_delete.twig', ['api_url' => \phpgw::link('/sms/commands/' . (int) ($args['id'] ?? 0)), 'list_url' => \phpgw::link('/sms/view/command'), 'command_id' => (int) ($args['id'] ?? 0)]); }
}
