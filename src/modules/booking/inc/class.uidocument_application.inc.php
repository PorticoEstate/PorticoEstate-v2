<?php
	phpgw::import_class('booking.uidocument');

	class booking_uidocument_application extends booking_uidocument
	{

		public function __construct($params = array())
		{
			parent::__construct($params);
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
			$owner_id = $this->filter_owner_id;

			if (!$owner_id)
			{
				return parent::query();
			}

			// Check if combining is enabled for applications
			$application_ui = CreateObject('booking.uiapplication');

			if (!$application_ui->get_combine_applications())
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

			$all_documents = array();
			// Use BookingFrontend DocumentService for cleaner, stateless queries
			foreach ($application_ids as $app_id)
			{
				try {
					$documentService = new \App\modules\booking\services\DocumentService(\App\modules\booking\models\Document::OWNER_APPLICATION);
					$documents = $documentService->getDocumentsForId($app_id);

					// Convert Document models to array format compatible with the old UI
					foreach ($documents as $document) {
						$docArray = $document->serialize();
						// Add the required fields for the UI
						$docArray['link'] = $this->get_owner_typed_link('download', array('id' => $docArray['id']));
						$docArray['option_edit'] = $this->get_owner_typed_link('edit', array('id' => $docArray['id']));
						$docArray['option_delete'] = $this->get_owner_typed_link('delete', array('id' => $docArray['id']));
						$all_documents[] = $docArray;
					}
				} catch (Exception $e) {
					// Log error but continue with other applications
					error_log("Error fetching documents for application {$app_id}: " . $e->getMessage());
				}
			}

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
				'start' => 0,
				'sort' => 'name',
				'dir' => 'asc',
				'recordsTotal' => count($unique_documents),
				'recordsFiltered' => count($unique_documents),
				'data' => $unique_documents
			);
		}
	}