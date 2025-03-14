<?php

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('booking.uicommon');

phpgw::import_class('booking.uidocument_building');
phpgw::import_class('booking.uipermission_building');

//	phpgw::import_class('phpgwapi.uicommon_jquery');

class booking_uidelegate extends booking_uicommon
{

	public $public_functions = array(
		'index' => true,
		'query' => true,
		'show' => true,
		'edit' => true,
		'delete' => true,
		'toggle_show_inactive' => true,
	);
	protected $module;

	var $activity_bo, $display_name;
	public function __construct()
	{
		parent::__construct();
		$this->bo = CreateObject('booking.bodelegate');
		$this->activity_bo = CreateObject('booking.boactivity');
		self::set_active_menu('booking::organizations::delegates');

		$this->module = "booking";
		$this->display_name = lang('delegate');
		Settings::getInstance()->update('flags', ['app_header' => lang('booking') . "::{$this->display_name}"]);
	}

	public function link_to_parent_params($action = 'show', $params = array())
	{
		return array_merge(array(
			'menuaction' => sprintf($this->module . '.ui%s.%s', $this->get_current_parent_type(), $action),
			'id' => $this->get_parent_id()
		), $params);
	}

	public function link_to_parent($action = 'show', $params = array())
	{
		return $this->link($this->link_to_parent_params($action, $params));
	}

	public function get_current_parent_type()
	{
		if (!$this->is_inline())
		{
			return null;
		}
		$parts = explode('_', key($a = $this->get_inline_params()));
		return $parts[1];
	}

	public function get_parent_id()
	{
		$inlineParams = $this->get_inline_params();
		return $inlineParams['filter_organization_id'];
	}

	public function get_parent_if_inline()
	{
		if (!$this->is_inline())
			return null;
		return CreateObject('booking.bo' . $this->get_current_parent_type())->read_single($this->get_parent_id());
	}

	public function redirect_to_parent_if_inline()
	{
		if ($this->is_inline())
		{
			self::redirect($this->link_to_parent_params());
		}

		return false;
	}

	public function link_to($action, $params = array())
	{
		return $this->link($this->link_to_params($action, $params));
	}

	public function link_to_params($action, $params = array())
	{
		if (isset($params['ui']))
		{
			$ui = $params['ui'];
			unset($params['ui']);
		}
		else
		{
			$ui = 'delegate';
			$this->apply_inline_params($params);
		}

		$action = sprintf($this->module . '.ui%s.%s', $ui, $action);
		return array_merge(array('menuaction' => $action), $params);
	}

	public function apply_inline_params(&$params)
	{
		if ($this->is_inline())
		{
			$params['filter_organization_id'] = intval(Sanitizer::get_var('filter_organization_id'));
		}
		return $params;
	}

	public function get_inline_params()
	{
		return array('filter_organization_id' => Sanitizer::get_var('filter_organization_id', 'int', 'REQUEST'));
	}

	public function is_inline()
	{
		return false != Sanitizer::get_var('filter_organization_id', 'int', 'REQUEST');
	}

	public function index()
	{
		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}

		$data = array(
			'datatable_name'	=> $this->display_name,
			'form' => array(
				'toolbar' => array(
					'item' => array(
						//							array(
						//								'type' => 'link',
						//								'value' => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
						//								'href' => self::link(array('menuaction' => $this->url_prefix . '.toggle_show_inactive'))
						//							),
					)
				),
			),
			'datatable' => array(
				'source' => self::link(array(
					'menuaction' => $this->module . '.uidelegate.index',
					'phpgw_return_as' => 'json'
				)),
				'field' => array(
					array(
						'key' => 'id',
						'label' => lang('id')
					),
					array(
						'key' => 'organization_name',
						'label' => lang('Organization')
					),
					array(
						'key' => 'name',
						'label' => lang('delegate'),
						'formatter' => 'JqueryPortico.formatLink'
					),
					array(
						'key' => 'phone',
						'label' => lang('Phone'),
					),
					array(
						'key' => 'email',
						'label' => lang('Email'),
					),
					array(
						'key' => 'active',
						'label' => lang('Active'),
					),
					array(
						'key' => 'link',
						'hidden' => true
					)
				)
			)
		);
		$data['datatable']['new_item'] = self::link(array('menuaction' => $this->module . '.uidelegate.edit'));
		$parameters = array(
			'parameter' => array(
				array(
					'name' => 'id',
					'source' => 'id'
				),
			)
		);
		$data['datatable']['actions'][] = array(
			'my_name' => 'delete',
			'statustext' => lang('delete'),
			'text' => lang('delete'),
			'confirm_msg' => lang('do you really want to delete this delegate'),
			'action' => phpgw::link('/index.php', array(
				'menuaction' => 'booking.uidelegate.delete'
			)),
			'parameters' => json_encode($parameters)
		);

		$data['datatable']['actions'][] = array(
			'my_name'	 => 'toggle_inactive',
			'className'	 => 'save',
			'type'		 => 'custom',
			'statustext' => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
			'text'		 => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
			'custom_code'	 => 'window.open("' . self::link(array('menuaction' => $this->url_prefix . '.toggle_show_inactive')) . '", "_self");',
		);
		self::render_template_xsl('datatable2', $data);
	}

	public function query()
	{
		$delegates = $this->bo->read();

		$lang_yes = lang('yes');
		$lang_no = lang('no');

		array_walk($delegates["results"], array($this, "_add_links"), $this->module . ".uidelegate.show");
		foreach ($delegates["results"] as &$delegate)
		{
			$delegate['active'] = $delegate['active'] == 1 ? $lang_yes : $lang_no;
		}
		$results = $this->jquery_results($delegates);

		if (is_array($parent_entity = $this->get_parent_if_inline()))
		{
			if ($this->bo->allow_create(array($this->get_current_parent_type() . '_id' => $parent_entity['id'])))
			{
				$results['Actions']['add'] = array('text' => lang('Add Group'), 'href' => $this->link_to('edit'));
			}
		}

		return $results;
	}

	public function edit()
	{
		$id = Sanitizer::get_var('id', 'int');


		if ($id)
		{
			$delegate = $this->bo->read_single($id);
			$delegate['id'] = $id;
			$delegate['organization_link'] = $this->link_to('show', array(
				'ui' => 'organization',
				'id' => $delegate['organization_id']
			));

			$delegate['cancel_link'] = $this->link_to('show', array('id' => $id));

			if ($this->is_inline())
			{
				$delegate['cancel_link'] = $this->link_to_parent();
			}
		}
		else
		{
			$delegate = array();
			$delegate['cancel_link'] = $this->link_to('index', array('ui' => 'organization'));

			$organization_id = Sanitizer::get_var('organization_id', 'int');
			if ($organization_id)
			{
				$delegate['organization_link'] = self::link(array(
					'menuaction' => $this->module . '.uiorganization.show',
					'id' => $organization_id
				));
				$delegate['cancel_link'] = $delegate['organization_link'];
				$delegate['organization_id'] = $organization_id;
				$organization = CreateObject('booking.boorganization')->read_single($organization_id);
				$delegate['organization_name'] = $organization['name'];
			}

			if ($this->is_inline())
			{
				$delegate['organization_link'] = $this->link_to_parent();
				$delegate['cancel_link'] = $this->link_to_parent();
				$this->apply_inline_params($delegate);
			}
		}

		$delegate['organizations_link'] = $this->link_to('index', array('ui' => 'organization'));

		$errors = array();
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$delegate = array_merge($delegate, extract_values($_POST, array(
				'name' => 'string',
				'ssn' => 'string',
				'email' => 'string',
				'phone' => 'string',
				'organization_id' => 'string',
				'organization_name' => 'string',
				'active' => 'int',
			)));
			if (!isset($delegate["active"]))
			{
				$delegate['active'] = '1';
			}

			$errors = $this->bo->validate($delegate);
			if (strlen($_POST['name']) > 50)
			{
				$errors['name'] = lang('Lengt of name is to long, max %1 characters long', 50);
			}
			if (strlen($_POST['shortname']) > 11)
			{
				$errors['shortname'] = lang('Lengt of shortname is to long, max 11 characters long');
			}
			if (!$errors)
			{
				if (empty($delegate['ssn']))
				{
					$_delegate = $this->bo->read_single($id);
					$delegate['ssn'] = $_delegate['ssn'];
				}
				else if (!preg_match('/^{(.*)}(.*)$/', $delegate['ssn'], $m) || count($m) != 3) //full string, algorhythm, hash
				{
					$hash = sha1($delegate['ssn']);
					$delegate['ssn'] =  '{SHA1}' . base64_encode($hash);
				}

				if ($id)
				{
					$receipt = $this->bo->update($delegate);
				}
				else
				{
					$receipt = $this->bo->add($delegate);
				}

				$this->redirect_to_parent_if_inline();
				self::redirect($this->link_to_params('show', array('id' => $receipt['id'])));
			}
		}
		$this->flash_form_errors($errors);

		if (is_array($parent_entity = $this->get_parent_if_inline()))
		{
			$delegate[$this->get_current_parent_type() . '_id'] = $parent_entity['id'];
			$delegate[$this->get_current_parent_type() . '_name'] = $parent_entity['name'];
		}

		phpgwapi_jquery::load_widget('autocomplete');

		$tabs = array();
		$tab_text = ($id) ? 'Edit delegate Edit' : 'New delegate';
		$tabs['generic'] = array('label' => lang($tab_text), 'link' => '#delegate_edit');
		$active_tab = 'generic';
		$delegate['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$delegate['validator'] = phpgwapi_jquery::formvalidator_generate(array(
			'location',
			'date', 'security', 'file'
		));

		$delegate['ssn'] = ''; //secret

		self::render_template_xsl('delegate_edit', array('delegate' => $delegate, 'module' => $this->module));
	}

	public function show()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$delegate = $this->bo->read_single($id);
		if (!$delegate)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}
		$delegate['organizations_link'] = self::link(array('menuaction' => $this->module . '.uiorganization.index'));
		$delegate['organization_link'] = self::link(array(
			'menuaction' => $this->module . '.uiorganization.show',
			'id' => $delegate['organization_id']
		));
		$delegate['edit_link'] = self::link(array(
			'menuaction' => $this->module . '.uidelegate.edit',
			'id' => $delegate['id']
		));
		$delegate['cancel_link'] = self::link(array('menuaction' => $this->module . '.uidelegate.index'));

		$data = array(
			'delegate' => $delegate
		);
		$loggedin = (int)true; // FIXME: Some sort of authentication!
		$edit_self_link = self::link(array(
			'menuaction' => 'bookingfrontend.uidelegate.edit',
			'id' => $delegate['id']
		));

		$tabs = array();
		$tabs['generic'] = array('label' => lang('delegate'), 'link' => '#delegate');
		$active_tab = 'generic';

		$delegate['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);

		self::render_template_xsl('delegate', array(
			'delegate' => $delegate, 'loggedin' => $loggedin,
			'edit_self_link' => $edit_self_link
		));
	}

	public function delete()
	{
		$id = Sanitizer::get_var('id', 'int');
		if ($this->bo->delete($id))
		{
			return lang('delegate %1 has been deleted', $id);
		}
		else
		{
			return lang('delete failed');
		}
	}
}
