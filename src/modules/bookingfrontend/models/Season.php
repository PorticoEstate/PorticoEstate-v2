<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="SeasonBoundary",
 *     type="object",
 *     @OA\Property(property="from_", type="string", example="09:00:00"),
 *     @OA\Property(property="to_", type="string", example="17:00:00"),
 *     @OA\Property(property="wday", type="integer", example=1, description="1=Monday through 7=Sunday")
 * )
 */


/**
 * @OA\Schema(
 *     schema="Season",
 *     type="object",
 *     title="Season",
 *     description="Season model"
 * )
 * @Exclude
 */
class Season
{
	use SerializableTrait;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $id;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $name;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $building_id;

	/**
	 * @OA\Property(type="string", format="date-time")
	 * @Expose
	 * @Timestamp(format="c")
	 */
	public $from_;

	/**
	 * @OA\Property(type="string", format="date-time")
	 * @Expose
	 * @Timestamp(format="c")
	 */
	public $to_;


	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $active;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $status;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $wday;


	/**
	 * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/Resource"))
	 * @Expose
	 * @SerializeAs(type="array", of="App\modules\bookingfrontend\models\Resource", short=true)
	 */
	public $resources;


	/**
	 * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/SeasonBoundary"))
	 * @Expose
	 */
	public $boundaries;

	public function __construct(array $data = [])
	{
		if (!empty($data))
		{
			$this->populate($data);
		}
	}

	public function populate(array $data)
	{
		foreach ($data as $key => $value)
		{
			if (property_exists($this, $key))
			{
				$this->$key = $value;
			}
		}
	}
}