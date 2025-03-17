<?php

namespace App\modules\bookingfrontend\models;

use App\modules\bookingfrontend\models\helper\BaseScheduleEntity;

/**
 * @OA\Schema(
 *     schema="Booking",
 *     type="object",
 *     title="Booking",
 *     description="Booking model"
 * )
 * @Exclude
 */
class Booking extends BaseScheduleEntity
{
	/**
	 * @OA\Property(
	 *     property="type",
	 *     type="string",
	 *     description="Entity type identifier",
	 *     example="booking"
	 * )
	 * @Expose
	 * @Default("booking")
	 */
    public $type;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $group_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $allocation_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $season_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $activity_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $reminder;

    /**
     * @OA\Property(type="string")
	 * @Expose(when={
	 * *  "group_id=$user_group_id"
	 * * })
 */
    public $secret;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $sms_total;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $group_name;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $activity_name;
}