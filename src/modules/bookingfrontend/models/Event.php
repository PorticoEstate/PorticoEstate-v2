<?php

namespace App\modules\bookingfrontend\models;

use App\modules\bookingfrontend\models\helper\BaseScheduleEntity;

/**
 * @OA\Schema(
 *     schema="Event",
 *     type="object",
 *     title="Event",
 *     description="Event model"
 * )
 * @Exclude
 */
class Event extends BaseScheduleEntity
{
	/**
	 * @OA\Property(
	 *     property="type",
	 *     type="string",
	 *     description="Entity type identifier",
	 *     example="event"
	 * )
	 * @Expose
	 * @Default("event")
	 */
	public $type;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $activity_id;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *  "is_public=1",
	 *  "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 */
	public $description;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *  "is_public=1",
	 *  "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 */
	public $contact_name;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *  "is_public=1",
	 *  "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 */
	public $contact_email;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *  "is_public=1",
	 *  "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 */
	public $contact_phone;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $reminder;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *  "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 */
	public $secret;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *    "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *    "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 *   }
	 */
	public $customer_identifier_type;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *   "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *   "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 *  }
	 * @Short
	 */
	public $customer_organization_number;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 * "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 * "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 */
	public $customer_ssn;

	/**
	 * @OA\Property(type="integer")
	 */
	public $customer_internal;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $is_public;

	/**
	 * @OA\Property(type="integer")
	 * @Expose(when={
	 *  "is_public=1",
	 *  "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 * @Short
	 */
	public $customer_organization_id;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *  "is_public=1",
	 *  "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 * @Short
	 */
	public $customer_organization_name;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $id_string;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 * @Short
	 */
	public $building_id;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *  "is_public=1",
	 *  "customer_identifier_type=ssn && customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number && customer_organization_number=$organization_number"
	 * })
	 * @Default(t"bookingfrontend.private event")
	 * @EscapeString(mode="default")
	 * @Short
	 */
	public $name;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *  "is_public=1",
	 *  "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 * @EscapeString(mode="default")
	 * @Short
	 */
	public $organizer;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *  "is_public=1",
	 *  "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 */
	public $homepage;

	/**
	 * @OA\Property(type="string")
	 * @Expose(when={
	 *  "is_public=1",
	 *  "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 */
	public $equipment;

	/**
	 * @OA\Property(type="integer")
	 */
	public $access_requested;

	/**
	 * @OA\Property(type="integer")
	 * @Expose(when={
	 *  "is_public=1",
	 *  "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
	 *  "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
	 * })
	 */
	public $participant_limit;
}