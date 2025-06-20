<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;
use OpenApi\Annotations as OA;

/**
 * @ORM\Entity
 * @ORM\Table(name="bb_resource")
 * @OA\Schema(
 *      schema="Resource",
 *      type="object",
 *      title="Resource",
 *      description="Resource model",
 * )
 * @Exclude
 */
class Resource
{
    use SerializableTrait;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Expose
     * @Short
     * @OA\Property(description="Unique identifier for the resource", type="integer")
     */
    public $id;

    /**
     * @ORM\Column(type="string", length=150)
     * @Expose
     * @Short
	 * @EscapeString(mode="default")
	 * @OA\Property(description="Name of the resource", type="string", maxLength=150)
     */
    public $name;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Activity ID associated with the resource", type="integer", nullable=true)
     */
    public $activity_id;

    /**
     * @ORM\Column(type="integer")
     * @Expose
     * @Short
     * @OA\Property(description="Whether the resource is active", type="integer")
     */
    public $active;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Sort order of the resource", type="integer", nullable=true)
     */
    public $sort;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @Expose
     * @OA\Property(description="IDs of organizations associated with the resource", type="string", maxLength=50, nullable=true)
     */
    public $organizations_ids;

    /**
     * @ORM\Column(type="json", nullable=true)
     * @Expose
     * @OA\Property(description="JSON representation of the resource", type="object", nullable=true)
     */
    public $json_representation;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
	 * @Short
     * @OA\Property(description="Resource category ID", type="integer", nullable=true)
     */
    public $rescategory_id;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Expose
     * @OA\Property(description="Opening hours of the resource", type="string", nullable=true)
     */
    public $opening_hours;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Expose
     * @OA\Property(description="Contact information for the resource", type="string", nullable=true)
     */
    public $contact_info;

    /**
     * @ORM\Column(type="bigint", nullable=true)
     * @Expose
     * @OA\Property(description="Direct booking information", type="integer", format="int64", nullable=true)
     */
    public $direct_booking;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Default booking length in days", type="integer", nullable=true)
     */
    public $booking_day_default_lenght;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Default start day of week for booking", type="integer", nullable=true)
     */
    public $booking_dow_default_start;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Default start time for booking", type="integer", nullable=true)
     */
    public $booking_time_default_start;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Default end time for booking", type="integer", nullable=true)
     */
    public $booking_time_default_end;

    /**
     * @ORM\Column(type="smallint", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Whether simple booking is enabled", type="integer", nullable=true)
     */
    public $simple_booking;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Direct booking season ID", type="integer", nullable=true)
     */
    public $direct_booking_season_id;

    /**
     * @ORM\Column(type="bigint", nullable=true)
     * @Expose
     * @OA\Property(description="Start date for simple booking", type="integer", format="int64", nullable=true)
     */
    public $simple_booking_start_date;


	/**
	 * @ORM\Column(type="bigint", nullable=true)
	 * @Expose
	 * @OA\Property(description="End date for simple booking", type="integer", format="int64", nullable=true)
	 */
	public $simple_booking_end_date;

	/**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Booking month horizon", type="integer", nullable=true)
     */
    public $booking_month_horizon;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Booking day horizon", type="integer", nullable=true)
     */
    public $booking_day_horizon;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Capacity of the resource", type="integer", nullable=true)
     */
    public $capacity;

    /**
     * @ORM\Transient
     * @Expose
	 * @Short
     * @OA\Property(description="Current participant limit of the resource", type="integer", nullable=true)
     */
    public $participant_limit;

    /**
     * @ORM\Column(type="integer")
     * @Expose
	 * @Short
	 * @ParseBool
     * @OA\Property(description="Whether the calendar is deactivated", type="integer")
     */
    public $deactivate_calendar;

    /**
     * @ORM\Column(type="integer")
     * @Expose
	 * @ParseBool
     * @Short
     * @OA\Property(description="Whether the application is deactivated", type="integer")
     */
    public $deactivate_application;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Booking time in minutes", type="integer", nullable=true)
     */
    public $booking_time_minutes;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Booking limit number", type="integer", nullable=true)
     */
    public $booking_limit_number;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Booking limit number horizon", type="integer", nullable=true)
     */
    public $booking_limit_number_horizont;

    /**
     * @ORM\Column(type="smallint", nullable=true)
     * @Expose
	 * @ParseBool
	 * @OA\Property(description="Whether the resource is hidden in frontend", type="integer", nullable=true)
     */
    public $hidden_in_frontend;

    /**
     * @ORM\Column(type="smallint", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Whether prepayment is activated", type="integer", nullable=true)
     */
    public $activate_prepayment;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @OA\Property(description="Booking buffer deadline", type="integer", nullable=true)
     */
    public $booking_buffer_deadline;

    /**
     * @ORM\Column(type="json", nullable=true)
     * @Expose
     * @OA\Property(description="Description in JSON format", type="object", nullable=true)
     */
    public $description_json;

    /**
     * @ORM\Column(type="smallint", nullable=true)
     * @Expose
     * @OA\Property(description="Whether to deny application if resource is already booked", type="integer", nullable=true)
     */
    public $deny_application_if_booked;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="ID of the building this resource belongs to", type="integer", nullable=true)
     */
    public $building_id;

    public function __construct($data = [])
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

    public function toArray()
    {
        $array = [];
        foreach (get_object_vars($this) as $key => $value)
        {
            $array[$key] = $value;
        }
        return $array;
    }

}