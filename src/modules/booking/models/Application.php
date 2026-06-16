<?php

namespace App\modules\booking\models;

use App\traits\SerializableTrait;

/**
 * Application model — maps to bb_application table columns only.
 *
 * Sub-resources (dates, resources, comments, orders, etc.) are fetched
 * via separate REST endpoints and are NOT properties on this model.
 *
 * Properties annotated with @ExposeAcl are only visible when the current user
 * passes the ACL check — admins see everything, public users see only @Expose fields.
 *
 * @Exclude
 */
class Application
{
	use SerializableTrait;

	// ── Table columns ──────────────────────────────────────────────────

	/** @Expose @Short */
	public int $id;

	/** @Expose */
	public string $id_string;

	/** @Expose */
	public int $active;

	/** @Expose */
	public int $display_in_dashboard;

	/** @Expose @Short */
	public string $type;

	/** @Expose @Short */
	public string $status;

	/** @Exclude — bearer token for frontend access, never expose via admin API */
	public string $secret;

	/** @Expose @Short */
	public $created;

	/** @Expose */
	public $modified;

	/**
	 * @Expose
	 * @Short
	 * @EscapeString(mode="default")
	 */
	public $building_name;

	/** @Expose @Short */
	public $building_id;

	/** @Expose */
	public $frontend_modified;

	/** @Expose */
	public $owner_id;

	/** @Expose */
	public $activity_id;

	/** @Expose */
	public $customer_identifier_type;

	/**
	 * @Expose(when={"customer_ssn=$user_ssn"})
	 * @Short
	 * @ExposeAcl(location=".application", permission=1, app="booking")
	 */
	public $customer_ssn;

	/** @Expose @Short */
	public $customer_organization_number;

	/**
	 * @Expose
	 * @Short
	 * @EscapeString(mode="default")
	 */
	public $name;

	/** @Expose */
	public $organizer;

	/** @Expose */
	public $homepage;

	/** @Expose */
	public $description;

	/** @Expose */
	public $equipment;

	/** @Expose @Short */
	public $contact_name;

	/** @Expose */
	public $contact_email;

	/** @Expose */
	public $contact_phone;

	/** @Expose */
	public $responsible_street;

	/** @Expose */
	public $responsible_zip_code;

	/** @Expose */
	public $responsible_city;

	/** @Expose */
	public $session_id;

	/** @Expose */
	public $agreement_requirements;

	/** @Expose */
	public $external_archive_key;

	/** @Expose */
	public $customer_organization_name;

	/** @Expose */
	public $customer_organization_id;

	/** @Expose */
	public $recurring_info;

	// ── Admin-only table column ────────────────────────────────────────

	/**
	 * @ExposeAcl(location=".application", permission=4, app="booking")
	 */
	public $case_officer_id;

	/**
	 * @ExposeAcl(location=".application", permission=1, app="booking")
	 * @ParseInt
	 */
	public $parent_id;

	// ── Computed fields (populated by controller/service layer) ────────

	/**
	 * Case officer display name.
	 * @ExposeAcl(location=".application", permission=1, app="booking")
	 */
	public $case_officer_name;

	/**
	 * Whether the current user IS the assigned case officer.
	 * @ExposeAcl(location=".application", permission=1, app="booking")
	 * @ParseBool
	 */
	public $case_officer_is_current_user;

	/**
	 * Number of associated allocations/bookings/events.
	 * @ExposeAcl(location=".application", permission=1, app="booking")
	 * @ParseInt
	 */
	public $num_associations;

	/**
	 * Count of related applications in this combined group.
	 * @ExposeAcl(location=".application", permission=1, app="booking")
	 * @ParseInt
	 */
	public $related_application_count;

	/**
	 * Activity name resolved from activity_id.
	 * @Expose
	 */
	public $activity_name;


	public function __construct(array $data = [])
	{
		if (!empty($data)) {
			$this->populate($data);
		}
	}

	public function populate(array $data): void
	{
		foreach ($data as $key => $value) {
			if (property_exists($this, $key)) {
				$this->$key = $value;
			}
		}
	}
}
