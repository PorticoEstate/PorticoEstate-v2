<?php

namespace App\modules\messenger\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\security\Acl;
use Slim\Csrf\Guard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\services\Settings;

class MessengerViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;
	private $menuSelection = 'messenger';

	/**
	 * Set up the legacy view bridge and the Twig renderer for the messenger app.
	 */
	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('messenger');
	}

	/**
	 * Register/validate a legacy static JS asset for inclusion in the page.
	 *
	 * @param string $app Application name the asset belongs to
	 * @param string $package Asset package/group name
	 * @param string $name File name of the asset (with or without .js extension)
	 * @param bool $endOfPage Whether to load the asset at the end of the page
	 * @param array $config Extra loader options, e.g. ['combine' => true]
	 * @return mixed Return value of phpgwapi_js::validate_file()
	 */
	public static function add_javascript($app, $package, $name, $endOfPage = false, array $config = [])
	{
		return \phpgwapi_js::getInstance()->validate_file($package, str_replace('.js', '', $name), $app, $endOfPage, $config);
	}

	/**
	 * Build the DataTables i18n/localization strings used by the inbox grid.
	 *
	 * @return array Nested array of DataTables option => localized value(s)
	 */
	private function getDatatableI18n(): array
	{
		return [
			'datatable' => [
				'emptyTable' => json_encode(lang('No data available in table')),
				'info' => json_encode(lang('Showing _START_ to _END_ of _TOTAL_ entries')),
				'infoEmpty' => json_encode(lang('Showing 0 to 0 of 0 entries')),
				'infoFiltered' => json_encode(lang('(filtered from _MAX_ total entries)')),
				'loadingRecords' => json_encode(lang('Loading...')),
				'processing' => json_encode(lang('Processing...')),
				'search' => json_encode(lang('Search')),
				'zeroRecords' => json_encode(lang('No matching records found')),
				'paginate' => json_encode([
					'first' => lang('first'),
					'last' => lang('last'),
					'next' => lang('next'),
					'previous' => lang('prev'),
				]),
				'aria' => json_encode([
					'sortAscending' => lang(': activate to sort column ascending'),
					'sortDescending' => lang(': activate to sort column descending'),
				]),
				'select' => json_encode([
					'rows' => ['0' => '', '_' => '%d ' . lang('rows selected')],
				]),
			],
			'lengthmenu' => ['_' => json_encode([[10, 25, 50], [10, 25, 50]])],
			'csv_download' => ['_' => json_encode([
				'show_button' => false,
				'title' => lang('download visible data'),
			])],
		];
	}

	/**
	 * Render the inbox page, registering the DataTables assets it depends on.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @return Response
	 */
	public function inbox(Request $request, Response $response): Response
	{
		$this->menuSelection = 'inbox';
		Settings::getInstance()->update('flags', ['app_header' => 'messenger::inbox']);
		\phpgw::import_class('phpgwapi.jquery');
		\phpgw::import_class('phpgwapi.css');
		\phpgw::import_class('phpgwapi.js');
		\phpgwapi_jquery::load_widget('core');
		self::add_javascript('phpgwapi', 'jquery', 'common.js', false, ['combine' => true]);
		foreach ([
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net/js/dataTables.min.js',
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-dt/js/dataTables.dataTables.min.js',
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-buttons/js/dataTables.buttons.min.js',
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-buttons-dt/js/buttons.dataTables.min.js',
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-responsive/js/dataTables.responsive.min.js',
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-responsive-dt/js/responsive.dataTables.min.js',
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-select/js/dataTables.select.min.js',
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-select-dt/js/select.dataTables.min.js',
			'phpgwapi/js/DataTables3/plugins/dataTables.inputPaging.js',
		] as $asset)
		{
			\phpgwapi_js::getInstance()->add_external_file($asset, false, ['combine' => false]);
		}
		self::add_javascript('phpgwapi', 'jquery', 'editable/jquery.jeditable.min.js', false, ['combine' => true]);
		self::add_javascript('phpgwapi', 'jquery', 'editable/jquery.dataTables.editable.js', false, ['combine' => true]);
		foreach ([
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-dt/css/dataTables.dataTables.min.css',
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-buttons-dt/css/buttons.dataTables.min.css',
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-responsive-dt/css/responsive.dataTables.min.css',
			'phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-select-dt/css/select.dataTables.min.css',
			'phpgwapi/js/DataTables3/plugins/dataTables.inputPaging.min.css',
		] as $asset)
		{
			\phpgwapi_css::getInstance()->add_external_file($asset);
		}

		return $this->render($request, $response, '@views/messenger/inbox/messenger_inbox.twig', [
			'api_url' => \phpgw::link('/messenger/messages'),
			'compose_url' => \phpgw::link('/messenger/view/compose'),
			'view_url' => \phpgw::link('/messenger/view/messages/__MESSAGE_ID__'),
			'delete_url' => \phpgw::link('/messenger/view/messages/__MESSAGE_ID__/delete'),
			'jquery_phpgw_i18n' => $this->getDatatableI18n(),
			'statuses' => [
				['id' => '', 'name' => lang('All')],
				['id' => 'N', 'name' => lang('New')],
				['id' => 'R', 'name' => lang('Replied')],
				['id' => 'O', 'name' => lang('Old')],
				['id' => 'F', 'name' => lang('Forwarded')],
			],
		]);
	}

	/**
	 * Render the compose (new message) form.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @return Response
	 */
	public function compose(Request $request, Response $response): Response
	{
		\phpgw::import_class('phpgwapi.jquery');
		\phpgwapi_jquery::load_widget('select2');
		$this->menuSelection = 'compose';
		return $this->render($request, $response, '@views/messenger/compose/messenger_compose.twig', [
			'mode' => 'compose',
			'api_url' => \phpgw::link('/messenger/messages'),
			'users_url' => \phpgw::link('/messenger/messages/users'),
		]);
	}

	/**
	 * Render the compose-to-groups form, if the current user has permission.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @return Response 403 plain-text response when access is denied
	 */
	public function composeGroups(Request $request, Response $response): Response
	{
		$this->menuSelection = 'compose_groups';
		if (!Acl::getInstance()->check('.compose_groups', Acl::ADD, 'messenger'))
		{
			$response->getBody()->write(lang('Access not permitted'));
			return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
		}

		\phpgw::import_class('phpgwapi.jquery');
		\phpgwapi_jquery::load_widget('select2');

		return $this->render($request, $response, '@views/messenger/compose_groups/messenger_compose_groups.twig', [
			'api_url' => \phpgw::link('/messenger/messages/groups'),
			'action_url' => \phpgw::link('/messenger/messages/groups'),
			'inbox_url' => \phpgw::link('/messenger/view/inbox'),
		]);
	}

	/**
	 * Render the compose-global-message form, if the current user has permission.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @return Response 403 plain-text response when access is denied
	 */
	public function composeGlobal(Request $request, Response $response): Response
	{
		$this->menuSelection = 'compose_global';
		if (!Acl::getInstance()->check('.compose_global', Acl::ADD, 'messenger') && !Acl::getInstance()->check('run', Acl::ADD, 'admin'))
		{
			$response->getBody()->write(lang('Access not permitted'));
			return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
		}

		return $this->render($request, $response, '@views/messenger/compose_global/messenger_compose_global.twig', [
			'api_url' => \phpgw::link('/messenger/messages/global'),
			'inbox_url' => \phpgw::link('/messenger/view/inbox'),
		]);
	}

	/**
	 * Render the message-view page for a single message.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args Route arguments, expects 'id'
	 * @return Response
	 */
	public function read(Request $request, Response $response, array $args): Response
	{
		$this->menuSelection = 'inbox';
		$id = (int) ($args['id'] ?? 0);
		return $this->render($request, $response, '@views/messenger/view/messenger_view.twig', [
			'mode' => 'read',
			'api_url' => \phpgw::link('/messenger/messages/' . $id),
			'inbox_url' => \phpgw::link('/messenger/view/inbox'),
			'compose_url' => \phpgw::link('/messenger/view/compose'),
			'reply_url' => \phpgw::link('/messenger/view/messages/' . $id . '/reply'),
			'forward_url' => \phpgw::link('/messenger/view/messages/' . $id . '/forward'),
			'delete_url' => \phpgw::link('/messenger/view/messages/' . $id . '/delete'),
		]);
	}

	/**
	 * Render the reply form for a message.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args Route arguments, expects 'id'
	 * @return Response
	 */
	public function reply(Request $request, Response $response, array $args): Response
	{
		return $this->messageForm($request, $response, $args, 'reply');
	}

	/**
	 * Render the forward form for a message.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args Route arguments, expects 'id'
	 * @return Response
	 */
	public function forward(Request $request, Response $response, array $args): Response
	{
		return $this->messageForm($request, $response, $args, 'forward');
	}

	/**
	 * Render the delete-confirmation page for a message.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args Route arguments, expects 'id'
	 * @return Response
	 */
	public function delete(Request $request, Response $response, array $args): Response
	{
		$id = (int) ($args['id'] ?? 0);
		return $this->render($request, $response, '@views/messenger/delete/messenger_delete.twig', [
			'api_url' => \phpgw::link('/messenger/messages'),
			'message_url' => \phpgw::link('/messenger/view/messages/' . $id),
			'inbox_url' => \phpgw::link('/messenger/view/inbox'),
			'message_id' => $id,
		]);
	}

	/**
	 * Shared renderer for the reply/forward form, keyed by $mode.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args Route arguments, expects 'id'
	 * @param string $mode Either 'reply' or 'forward'
	 * @return Response
	 */
	private function messageForm(Request $request, Response $response, array $args, string $mode): Response
	{
		$this->menuSelection = 'compose';
		$id = (int) ($args['id'] ?? 0);
		if ($mode === 'forward')
		{
			\phpgw::import_class('phpgwapi.jquery');
			\phpgwapi_jquery::load_widget('select2');
		}

		return $this->render($request, $response, '@views/messenger/' . $mode . '/messenger_' . $mode . '.twig', [
			'mode' => $mode,
			'api_url' => \phpgw::link('/messenger/messages/' . $id),
			'action_url' => \phpgw::link('/messenger/messages/' . $id . '/' . $mode),
			'users_url' => \phpgw::link('/messenger/messages/users'),
			'inbox_url' => \phpgw::link('/messenger/view/inbox'),
		]);
	}

	/**
	 * Render a Twig template through the legacy view wrapper, ensuring a CSRF token is present.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param string $template Twig template path
	 * @param array $data Template variables
	 * @return Response HTML response with the rendered page
	 */
	private function render(Request $request, Response $response, string $template, array $data): Response
	{
		$csrfName = (string) ($request->getAttribute('csrf_name') ?? '');
		$csrfValue = (string) ($request->getAttribute('csrf_value') ?? '');
		if ($csrfName === '' || $csrfValue === '')
		{
			$csrfStorage = null;
			$csrfGuard = new Guard(new \Slim\Psr7\Factory\ResponseFactory(), 'csrf', $csrfStorage, null, 200, 16, true);
			$request = $csrfGuard->appendNewTokenToRequest($request);
			$csrfName = (string) $request->getAttribute('csrf_name');
			$csrfValue = (string) $request->getAttribute('csrf_value');
		}

		$html = $this->legacyView->render($this->twig->render($template, array_merge($data, [
			'layout' => '@views/_bare.twig',
			'csrf' => [
				'name' => $csrfName,
				'value' => $csrfValue,
			],
		])), ['messenger', $this->menuSelection]);

		$response->getBody()->write($html);
		return $response->withHeader('Content-Type', 'text/html');
	}
}