<?php
	phpgw::import_class('booking.uidocument');

	class booking_uidocument_resource extends booking_uidocument
	{

		public function __construct()
		{
			parent::__construct();
			self::set_active_menu('booking::buildings::resources::documents');
		}

		protected function get_owner_pathway( array $forDocumentData )
		{
			return array(
				array('text' => 'objects_plural_name', 'href' => 'objects_plural_href'),
				array('text' => 'object_singular_name', 'href' => 'object_singular_name'),
			);
		}

		/**
		 * Override parent add() to include cache invalidation
		 */
		public function add()
		{
			$errors = array();
			$document = array();

			if ($_SERVER['REQUEST_METHOD'] == 'POST')
			{
				$document = extract_values($_POST, $this->fields);
				$document['files'] = $this->get_files_from_post();
				$errors = $this->bo->validate($document);
				if (!$errors)
				{
					try
					{
						$receipt = $this->bo->add($document);

						// Invalidate Next.js caches (client-side via WebSocket)
						if (class_exists('\App\modules\bookingfrontend\services\CacheService'))
						{
							$cache = new \App\modules\bookingfrontend\services\CacheService();
							// owner_id is the resource_id for resource documents
							$resourceId = isset($document['owner_id']) ? (int)$document['owner_id'] : null;
							if ($resourceId)
							{
								$cache->invalidateResourceDocuments($resourceId);
							}
						}

						$this->redirect_to_parent_if_inline();
						self::redirect($this->get_owner_typed_link_params('index'));
					}
					catch (booking_unauthorized_exception $e)
					{
						$errors['global'] = lang('Could not add object due to insufficient permissions');
					}
				}
			}

			self::add_javascript('booking', 'base', 'focal-point-picker.js');
			self::add_javascript('booking', 'base', 'document.js');
			phpgwapi_jquery::load_widget('autocomplete');

			$this->add_default_display_data($document);

			if (is_array($parentData = $this->get_parent_if_inline()))
			{
				$document['owner_id'] = $parentData['id'];
				$document['owner_name'] = $parentData['name'];
			}

			$this->flash_form_errors($errors);

			$tabs = array();
			$tabs['generic'] = array('label' => lang('Document New'), 'link' => '#document');
			$active_tab = 'generic';

			$document['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
			$document['validator'] = phpgwapi_jquery::formvalidator_generate(array(
				'location',
				'date', 'security', 'file'
			));

			self::render_template_xsl('document_form', array('document' => $document));
		}

		/**
		 * Override parent edit() to include cache invalidation
		 */
		public function edit()
		{
			$id = $this->id;
			if (!$id)
			{
				phpgw::no_access('booking', lang('missing id'));
			}
			$document = $this->bo->read_single($id);
			if (!$document)
			{
				phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
			}

			$errors = array();
			if ($_SERVER['REQUEST_METHOD'] == 'POST')
			{
				$document = array_merge($document, extract_values($_POST, $this->fields));

				$errors = $this->bo->validate($document);
				if (!$errors)
				{
					try
					{
						$receipt = $this->bo->update($document);

						// Invalidate Next.js caches (client-side via WebSocket)
						if (class_exists('\App\modules\bookingfrontend\services\CacheService'))
						{
							$cache = new \App\modules\bookingfrontend\services\CacheService();
							// owner_id is the resource_id for resource documents
							$resourceId = isset($document['owner_id']) ? (int)$document['owner_id'] : null;
							if ($resourceId)
							{
								$cache->invalidateResourceDocuments($resourceId);
							}
						}

						$this->redirect_to_parent_if_inline();
						self::redirect($this->get_owner_typed_link_params('index'));
					}
					catch (booking_unauthorized_exception $e)
					{
						$errors['global'] = lang('Could not update object due to insufficient permissions');
					}
				}
			}

			self::add_javascript('booking', 'base', 'focal-point-picker.js');
			self::add_javascript('booking', 'base', 'document.js');
			phpgwapi_jquery::load_widget('autocomplete');

			$this->add_default_display_data($document);

			$this->flash_form_errors($errors);

			$tabs = array();
			$tabs['generic'] = array('label' => lang('Document Edit'), 'link' => '#document');
			$active_tab = 'generic';

			$document['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
			$document['validator'] = phpgwapi_jquery::formvalidator_generate(array(
				'location',
				'date', 'security', 'file'
			));

			self::render_template_xsl('document_form', array('document' => $document));
		}
	}