<?php

namespace App\modules\bookingfrontend\models;

use App\modules\bookingfrontend\models\helper\BaseScheduleEntity;

/**
 * @OA\Schema(
 *     schema="Allocation",
 *     type="object",
 *     title="Allocation",
 *     description="Allocation model"
 * )
 * @Exclude
 */
class Allocation extends BaseScheduleEntity
{
	/**
	 * @OA\Property(
	 *     property="type",
	 *     type="string",
	 *     description="Entity type identifier",
	 *     example="allocation"
	 * )
	 * @Expose
	 * @Default("allocation")
	 */
    public $type;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $organization_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $season_id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $id_string;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $additional_invoice_information;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $organization_name;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $organization_shortname;

    /**
     * Whether the CURRENT viewer may open the linked application, evaluated in
     * ScheduleEntityService with the same predicate the application page itself
     * gates on. Meaningless when application_id is null. Defaults false so any
     * path that never runs the check cannot claim a viewability it did not
     * evaluate.
     *
     * @OA\Property(type="boolean")
     * @Expose
     */
    public $can_view_application = false;
}