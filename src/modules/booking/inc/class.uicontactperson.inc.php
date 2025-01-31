<?php
phpgw::import_class('booking.uicommon');

class booking_uicontactperson extends booking_uicommon
{

	public $public_functions = array(
		'index' => true,
		'show' => true,
		'edit' => true,
	);
	protected $module;

	var $fields;

	public function __construct()
	{
		parent::__construct();
		$this->bo = CreateObject('booking.bocontactperson');
		self::set_active_menu('booking::contacts');
		$this->module = "booking";
		$this->fields = array(
			'name' => 'string',
			'ssn' => 'string',
			'homepage' => 'url',
			'phone' => 'string',
			'email' => 'email',
			'description' => 'html',
		);
	}

	public function index()
	{
		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}

		$data = array(
			'form' => array(
				'toolbar' => array(
					'item' => array(
						array(
							'type' => 'text',
							'name' => 'query'
						),
						array(
							'type' => 'submit',
							'name' => 'search',
							'value' => lang('Search')
						),
					)
				),
			),
			'datatable' => array(
				'source' => self::link(array(
					'menuaction' => 'booking.uicontactperson.index',
					'phpgw_return_as' => 'json'
				)),
				'field' => array(
					array(
						'key' => 'name',
						'label' => lang('First name'),
						'formatter' => 'JqueryPortico.formatLink'
					),
					array(
						'key' => 'organization',
						'label' => lang('Organization')
					),
					array(
						'key' => 'phone',
						'label' => lang('Phone')
					),
					array(
						'key' => 'email',
						'label' => lang('Email')
					),
					array(
						'key' => 'link',
						'hidden' => true
					)
				)
			)
		);

		$data['datatable']['actions'][] = array();
		$data['datatable']['new_item'] = self::link(array('menuaction' => 'booking.uicontactperson.edit'));

		self::render_template_xsl('datatable2', $data);
	}

	public function query()
	{
		if ($id = Sanitizer::get_var('id', 'int'))
		{
			$person = $this->bo->read_single($id);
			return $this->jquery_results(array("totalResultsAvailable" => 1, "results" => $person));
		}

		$persons = $this->bo->read();
		array_walk($persons["results"], array($this, "_add_links"), "booking.uicontactperson.show");
		return $this->jquery_results($persons);
	}

	public function show()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$person = $this->bo->read_single($id);
		if (!$person)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}
		$person['contactpersons_link'] = self::link(array('menuaction' => 'booking.uicontactperson.index'));
		$person['edit_link'] = self::link(array(
			'menuaction' => 'booking.uicontactperson.edit',
			'id' => $person['id']
		));

		$data = array(
			'group' => $group
		);
		self::render_template_xsl('contactperson', array('person' => $person,));
	}

	public function edit()
	{
		$id = Sanitizer::get_var('id', 'int');
		if ($id)
		{
			$person = $this->bo->read_single($id);
			$person['id'] = $id;
			$person['contactpersons_link'] = self::link(array('menuaction' => 'booking.uicontactperson.index'));
			$person['edit_link'] = self::link(array(
				'menuaction' => 'booking.uicontactperson.edit',
				'id' => $person['id']
			));
			$person['cancel_link'] = self::link(array('menuaction' => 'booking.uicontactperson.index'));
		}
		else
		{
			$person = array();
		}

		$errors = array();
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$person = array_merge($person, extract_values($_POST, $this->fields));
			$errors = $this->bo->validate($person);
			if (!$errors)
			{
				if ($id)
				{
					$receipt = $this->bo->update($person);
				}
				else
				{
					$receipt = $this->bo->add($person);
				}
				self::redirect(array(
					'menuaction' => $this->module . '.uicontactperson.show',
					'id' => $receipt['id']
				));
			}
		}
		$this->flash_form_errors($errors);
		self::rich_text_editor('contact-field-description');

		self::add_template_file("contactperson_fields");
		self::render_template_xsl('contactperson_edit', array('person' => $person,));
	}
}
