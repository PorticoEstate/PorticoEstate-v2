<?php

namespace App\modules\email\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;

class EmailComposeViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('email');
	}

	private function csrf(Request $request): array
	{
		$name = (string) ($request->getAttribute('csrf_name') ?? '');
		$value = (string) ($request->getAttribute('csrf_value') ?? '');
		if ($name === '' || $value === '')
		{
			$responseFactory = new \Slim\Psr7\Factory\ResponseFactory();
			$csrfStorage = null;
			$guard = new Guard($responseFactory, 'csrf', $csrfStorage, null, 200, 16, true);
			$request = $guard->appendNewTokenToRequest($request);
			$name = (string) $request->getAttribute('csrf_name');
			$value = (string) $request->getAttribute('csrf_value');
		}
		return ['name' => $name, 'value' => $value];
	}

	private function formValue($value): string
	{
		return htmlspecialchars_decode((string) $value, ENT_QUOTES | ENT_HTML5);
	}

	private function context(array $query): array
	{
		$context = [];
		foreach (['action', 'msgball', 'fldball', 'fwd_proc', 'sort', 'order', 'start'] as $key)
		{
			if (isset($query[$key])) $context[$key] = $query[$key];
		}
		return $context;
	}

	public function index(Request $request, Response $response): Response
	{
		$businessObject = \CreateObject('email.bocompose');
		$businessObject->compose();
		$compose = (array) ($businessObject->xi ?? []);
		$query = $request->getQueryParams();
		$context = $this->context($query);

		$returnParameters = [];
		$folderBall = (array) ($query['fldball'] ?? []);
		$messageBall = (array) ($query['msgball'] ?? []);
		if ($messageBall)
		{
			$returnParameters['fldball[acctnum]'] = $messageBall['acctnum'] ?? 0;
			$returnParameters['fldball[folder]'] = $messageBall['folder'] ?? 'INBOX';
		} else
		{
			$returnParameters['fldball[acctnum]'] = $folderBall['acctnum'] ?? 0;
			$returnParameters['fldball[folder]'] = $folderBall['folder'] ?? 'INBOX';
		}
		foreach (['sort', 'order', 'start', 'click_history'] as $key)
		{
			if (isset($query[$key])) $returnParameters[$key] = $query[$key];
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Compose')]);
		$html = $this->twig->render('@views/compose.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/email/api/send', [], true),
			'attachments_api_url' => \phpgw::link('/email/api/attachments', [], true),
			'attachment_upload_url' => \phpgw::link('/email/api/attachments', [], true),
			'attachment_delete_url' => \phpgw::link('/email/api/attachments/delete', [], true),
			'contacts_api_url' => \phpgw::link('/email/api/addressbook', [], true),
			'return_url' => \phpgw::link('/email/view/inbox', $returnParameters, true),
			'context' => $context,
			'csrf' => $this->csrf($request),
			'to' => $this->formValue($compose['to_box_value'] ?? ''),
			'cc' => $this->formValue($compose['cc_box_value'] ?? ''),
			'bcc' => $this->formValue($compose['bcc_box_value'] ?? ''),
			'subject' => $this->formValue($compose['subject'] ?? ''),
			'body' => $this->formValue($compose['body'] ?? ''),
			'has_signature' => !empty($compose['do_checkbox_sig']),
			'attach_signature' => !empty($compose['checkbox_sig_value']),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['email', 'compose']));
		return $response->withHeader('Content-Type', 'text/html');
	}

}
