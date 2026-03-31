<?php
phpgw::import_class('booking.socommon');


/**
 * resource_activity_entityform is used for managing the entity forms for resource activities.
 * The table bb_resource_activity_entityform contains the following fields:
 * - id: the unique identifier for the entity form (int)
 * - name: the name of the entity form (varchar)
 * - active: whether the entity form is active (checkbox)
 * - resource_id: the ID of the resource associated with the entity form (int)
 * - entityform_location_id: the location_id of entity_category as the location where the entity form attributes are defined (int)
 * - activity_id: the ID's of the activities associated with the entity form (int), stored as a comma-separated string (,23,45,)
 * The entity forms are used to define the forms that are displayed for resource activities in the application.

 */




class booking_soresource_activity_entityform extends booking_socommon
{

	function __construct()
	{
		parent::__construct(
			'bb_resource_activity_entityform',
			array(
				'id' => array('type' => 'int'),
				'active' => array('type' => 'int', 'required' => true),
				'name' => array('type' => 'string', 'query' => true, 'required' => true),
				'building_id' => array('type' => 'int', 'required' => true),
				'resources' => array('type' => 'json', 'required' => true),
				'activities' => array('type' => 'json', 'required' => true),
				'location_id' => array('type' => 'int', 'required' => true),
			)
		);
		$this->account = $this->userSettings['account_id'];
	}

	protected function preValidate(&$entity)
	{
	}

	/**
	 * I want to check if there are any other entity forms with the same location_id,
	 * activities, and resources, and if so, return an error.
	 * Overlap in resources and activities is not allowed for the same location_id, as this would cause confusion in the application.
	 * Check for both add and edit scenarios, for edit scenario, exclude the current entity form from the check.
	 **/

	protected function doValidate($entity, booking_errorstack $errors)
	{
		$resources   = (array)($entity['resources'] ?? array());
		$location_id = $entity['location_id'];
		$activities  = (array)($entity['activities'] ?? array());
		$id          = isset($entity['id']) ? (int)$entity['id'] : null;

		if (empty($location_id) || empty($resources) || empty($activities))
		{
			return;
		}

		$filters = array('location_id' => (int)$location_id);

		if ($id)
		{
			$filters['where'] = array('%%table%%.id != ' . $id);
		}

		$existing = $this->read(array(
			'filters' => $filters,
			'results' => -1,
		));

		foreach ($existing['results'] as $existing_form)
		{
			$existing_resources  = (array)($existing_form['resources'] ?? array());
			$existing_activities = (array)($existing_form['activities'] ?? array());

			$resource_overlap  = array_intersect($resources, $existing_resources);
			$activity_overlap  = array_intersect($activities, $existing_activities);

			if (!empty($resource_overlap) && !empty($activity_overlap))
			{
				$errors['resources']   = lang('One or more resources are already used in another entity form with the same location and overlapping activities');
				$errors['activities']  = lang('One or more activities are already used in another entity form with the same location and overlapping resources');
				return;
			}
		}
	}


	/**
	 * Get the entity form location_id for a given resource and activity
	 *
	 * Retrieves the location_id of an active entity form that matches the given resource and activity.
	 * The query checks for active entity forms that have overlapping resources and activities with the given
	 * resource_id and activity_id, and returns the location_id of the first matching entity form it finds.
	 *
	 * The resources and activities are stored as JSON arrays in the database, so the query uses the PostgreSQL
	 * @> (contains) operator to check for overlap. The resource_id and activity_id are converted to JSON arrays
	 * with a single element for the query.
	 *
	 * @param int $resource_id The ID of the resource to search for
	 * @param int $activity_id The ID of the activity to search for
	 *
	 * @return int|null The location_id of the matching entity form, or null if no matching entity form is found
	 *
	 * @note This method assumes that there should be at most one active entity form for a given combination
	 *       of resource and activity, as enforced by the validation in doValidate().
	 */
	public function get_entity_form_by_resource_and_activity($resource_id, $activity_id)
	{
		$sql = "SELECT location_id FROM public.bb_resource_activity_entityform "
			. "WHERE active = 1 "
			. "AND resources @> :resource_json::jsonb "
			. "AND activities @> :activity_json::jsonb "
			. "LIMIT 1";

		$stmt = $this->db->prepare($sql);
		$stmt->execute(array(
			':resource_json' => json_encode(array((string)$resource_id)),
			':activity_json' => json_encode(array((string)$activity_id)),
		));

		$location_id = $stmt->fetchColumn();
		return $location_id !== false ? $location_id : null;
	}
}
