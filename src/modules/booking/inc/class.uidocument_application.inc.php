<?php
	phpgw::import_class('booking.uidocument');

	class booking_uidocument_application extends booking_uidocument
	{

		public function __construct()
		{
			parent::__construct();
			self::set_active_menu('booking::application::documents');
		}

		protected function get_owner_pathway( array $forDocumentData )
		{
			return array(
				array('text' => 'objects_plural_name', 'href' => 'objects_plural_href'),
				array('text' => 'object_singular_name', 'href' => 'object_singular_name'),
			);
		}

		public function index()
		{
			if (Sanitizer::get_var('phpgw_return_as') == 'json')
			{
				return $this->query_combined();
			}

			return parent::index();
		}

		public function query_combined()
		{
			$owner_id = Sanitizer::get_var('filter_owner_id', 'int');

			if (!$owner_id)
			{
				return parent::query();
			}

			// Check if combining is enabled for applications
			$application_ui = CreateObject('booking.uiapplication');

			if (!$application_ui->combine_applications)
			{
				return parent::query();
			}

			// Get related applications for combined display
			$application_bo = CreateObject('booking.boapplication');
			$related_info = $application_bo->so->get_related_applications($owner_id);
			$application_ids = $related_info['application_ids'];

			if (empty($application_ids))
			{
				return parent::query();
			}

			// Get documents from all related applications
			$all_documents = array();
			foreach ($application_ids as $app_id)
			{
				// Temporarily set the owner_id to each application ID
				$_GET['filter_owner_id'] = $app_id;
				$documents = parent::query();
				if (!empty($documents['results']))
				{
					$all_documents = array_merge($all_documents, $documents['results']);
				}
			}

			// Restore original owner_id
			$_GET['filter_owner_id'] = $owner_id;

			// Remove duplicates based on document ID
			$unique_documents = array();
			$seen_ids = array();
			foreach ($all_documents as $document)
			{
				if (!in_array($document['id'], $seen_ids))
				{
					$unique_documents[] = $document;
					$seen_ids[] = $document['id'];
				}
			}

			return array(
				'results' => $unique_documents,
				'total_records' => count($unique_documents)
			);
		}
	}