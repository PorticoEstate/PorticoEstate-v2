<?php

namespace App\modules\notes\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\security\Acl;
use Slim\Csrf\Guard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\services\Settings;

class NotesViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;
	private $menuSelection = 'notes';

	/**
	 * Set up the legacy view bridge and the Twig renderer for the notes app.
	 */
	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('notes');
	}

	/**
	 * Register/validate a legacy static JS asset for inclusion in the page.
	 */
	public static function add_javascript($app, $package, $name, $endOfPage = false, array $config = [])
	{
		return \phpgwapi_js::getInstance()->validate_file($package, str_replace('.js', '', $name), $app, $endOfPage, $config);
	}

	/**
	 * Build the DataTables i18n/localization strings used by the notes grid.
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
	 * Helper to load category list for dropdown options.
	 */
	private function getCategoriesList(bool $includeAll = true): array
	{
		$cats = \CreateObject('phpgwapi.categories', -1, 'notes');
		$categories = (array) $cats->return_sorted_array(0, false, '', '', '', true, 0, false);

		$list = [];
		if ($includeAll)
		{
			$list[] = ['id' => 0, 'name' => lang('All')];
		}
		else
		{
			$list[] = ['id' => 0, 'name' => lang('no category')];
		}

		foreach ($categories as $category)
		{
			$list[] = [
				'id' => (int) ($category['id'] ?? 0),
				'name' => (string) ($category['name'] ?? ''),
			];
		}

		return $list;
	}

	/**
	 * Render the list notes page.
	 */
	public function index(Request $request, Response $response): Response
	{
		$this->menuSelection = 'notes';
		Settings::getInstance()->update('flags', ['app_header' => lang('notes') . ': ' . lang('list notes')]);

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

		return $this->render($request, $response, '@views/list/notes_list.twig', [
			'api_url' => \phpgw::link('/notes/notes'),
			'add_url' => \phpgw::link('/notes/view/notes/add'),
			'view_url_template' => \phpgw::link('/notes/view/notes/__NOTE_ID__'),
			'edit_url_template' => \phpgw::link('/notes/view/notes/__NOTE_ID__/edit'),
			'delete_url_template' => \phpgw::link('/notes/view/notes/__NOTE_ID__/delete'),
			'jquery_phpgw_i18n' => $this->getDatatableI18n(),
			'categories' => $this->getCategoriesList(true),
			'filters' => [
				['id' => 'none', 'name' => lang('All')],
				['id' => 'yours', 'name' => lang('Yours')],
				['id' => 'private', 'name' => lang('Private')],
			],
		]);
	}

	/**
	 * Render the add note form.
	 */
	public function add(Request $request, Response $response): Response
	{
		$this->menuSelection = 'new';
		Settings::getInstance()->update('flags', ['app_header' => lang('notes') . ': ' . lang('add note')]);

		\phpgw::import_class('phpgwapi.jquery');
		\phpgwapi_jquery::load_widget('select2');

		return $this->render($request, $response, '@views/edit/notes_edit.twig', [
			'mode' => 'add',
			'api_url' => \phpgw::link('/notes/notes'),
			'list_url' => \phpgw::link('/notes/view/notes'),
			'categories' => $this->getCategoriesList(false),
		]);
	}

	/**
	 * Render the edit note form.
	 */
	public function edit(Request $request, Response $response, array $args): Response
	{
		$this->menuSelection = 'notes';
		$id = (int) ($args['id'] ?? 0);
		Settings::getInstance()->update('flags', ['app_header' => lang('notes') . ': ' . lang('edit note')]);

		\phpgw::import_class('phpgwapi.jquery');
		\phpgwapi_jquery::load_widget('select2');

		return $this->render($request, $response, '@views/edit/notes_edit.twig', [
			'mode' => 'edit',
			'note_id' => $id,
			'api_url' => \phpgw::link('/notes/notes/' . $id),
			'list_url' => \phpgw::link('/notes/view/notes'),
			'categories' => $this->getCategoriesList(false),
		]);
	}

	/**
	 * Render the read/view page for a single note.
	 */
	public function view(Request $request, Response $response, array $args): Response
	{
		$this->menuSelection = 'notes';
		$id = (int) ($args['id'] ?? 0);
		Settings::getInstance()->update('flags', ['app_header' => lang('notes') . ': ' . lang('view note')]);

		return $this->render($request, $response, '@views/view/notes_view.twig', [
			'note_id' => $id,
			'api_url' => \phpgw::link('/notes/notes/' . $id),
			'list_url' => \phpgw::link('/notes/view/notes'),
			'edit_url' => \phpgw::link('/notes/view/notes/' . $id . '/edit'),
			'delete_url' => \phpgw::link('/notes/view/notes/' . $id . '/delete'),
		]);
	}

	/**
	 * Render the delete confirmation page for a note.
	 */
	public function delete(Request $request, Response $response, array $args): Response
	{
		$this->menuSelection = 'notes';
		$id = (int) ($args['id'] ?? 0);
		Settings::getInstance()->update('flags', ['app_header' => lang('notes') . ': ' . lang('delete note')]);

		return $this->render($request, $response, '@views/delete/notes_delete.twig', [
			'note_id' => $id,
			'api_url' => \phpgw::link('/notes/notes/' . $id),
			'list_url' => \phpgw::link('/notes/view/notes'),
			'view_url' => \phpgw::link('/notes/view/notes/' . $id),
		]);
	}

	/**
	 * Render a Twig template through the legacy view wrapper, ensuring a CSRF token is present.
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
		])), ['notes', $this->menuSelection]);

		$response->getBody()->write($html);
		return $response->withHeader('Content-Type', 'text/html');
	}
}
