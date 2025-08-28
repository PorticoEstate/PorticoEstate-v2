<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;
use OpenApi\Annotations as OA;


/**
 * @ORM\Entity
 * @ORM\Table(name="bb_building")
 * @OA\Schema(
 *      schema="Building",
 *      type="object",
 *      title="Building",
 *      description="Building model",
 * )
 * @Exclude
 */
class Building
{
    use SerializableTrait;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Expose
     * @Short
     * @OA\Property(
     *      description="Unique identifier for the building",
     *      type="integer"
     * )
     */
    public $id;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(
     *      description="Town ID associated with the building",
     *      type="integer",
     *      nullable=true
     * )
     */
    public $town_id;

    /**
     * @ORM\Column(type="string", length=150)
     * @Expose
     * @Short
     * @EscapeString(mode="default")
     * @OA\Property(
     *      description="Name of the building",
     *      type="string",
     *      maxLength=150
     * )
     */
    public $name;

    /**
     * @ORM\Column(type="text")
     * @Expose
     * @OA\Property(
     *      description="Homepage of the building",
     *      type="string",
     *      nullable=true
     * )
     */
    public $homepage;

    /**
     * @ORM\Column(type="string", length=50)
     * @Expose
     * @OA\Property(
     *      description="Contact phone number",
     *      type="string",
     *      maxLength=50
     * )
     */
    public $phone;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @OA\Property(
     *      description="Contact email",
     *      type="string",
     *      maxLength=255,
     *      nullable=true
     * )
     */
    public $email;

    /**
     * @ORM\Column(type="integer", options={"default" : 1})
     * @Expose
     * @OA\Property(
     *      description="Status of the building, 1 for active, 0 for inactive",
     *      type="integer"
     * )
     */
    public $active;

    /**
     * @ORM\Column(type="string", length=255)
     * @Expose
     * @Short
     * @OA\Property(
     *      description="Street address of the building",
     *      type="string",
     *      maxLength=255
     * )
     */
    public $street;

    /**
     * @ORM\Column(type="string", length=255)
     * @Expose
     * @Short
     * @OA\Property(
     *      description="Zip code of the building",
     *      type="string",
     *      maxLength=255
     * )
     */
    public $zip_code;

    /**
     * @ORM\Column(type="string", length=255)
     * @Expose
     * @Short
     * @OA\Property(
     *      description="City where the building is located",
     *      type="string",
     *      maxLength=255
     * )
     */
    public $city;

    /**
     * @ORM\Column(type="string", length=255)
     * @Expose
     * @Short
     * @OA\Property(
     *      description="District or part of town",
     *      type="string",
     *      maxLength=255
     * )
     */
    public $district;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(
     *      description="Location code of the building",
     *      type="string",
     *      nullable=true
     * )
     */
    public $location_code;

    /**
     * @ORM\Column(type="integer", options={"default" : 0})
     * @Expose
     * @Short
	 * @ParseBool
	 * @OA\Property(
     *      description="Whether the calendar is deactivated, 0 or 1",
     *      type="integer"
     * )
     */
    public $deactivate_calendar;

    /**
     * @ORM\Column(type="integer", options={"default" : 0})
	 * @Expose
	 * @ParseBool
     * @Short
     * @OA\Property(
     *      description="Whether applications are deactivated, 0 or 1",
     *      type="integer"
     * )
     */
    public $deactivate_application;

    /**
     * @ORM\Column(type="integer", options={"default" : 0})
     * @Expose
     * @Short
     * @OA\Property(
     *      description="Whether sending messages is deactivated, 0 or 1",
     *      type="integer"
     * )
     */
    public $deactivate_sendmessage;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @OA\Property(
     *      description="Name of the inspector",
     *      type="string",
     *      maxLength=50,
     *      nullable=true
     * )
     */
    public $tilsyn_name;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @OA\Property(
     *      description="Email of the inspector",
     *      type="string",
     *      maxLength=255,
     *      nullable=true
     * )
     */
    public $tilsyn_email;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @OA\Property(
     *      description="Phone number of the inspector",
     *      type="string",
     *      maxLength=50,
     *      nullable=true
     * )
     */
    public $tilsyn_phone;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @Expose
     * @OA\Property(
     *      description="Text for the calendar",
     *      type="string",
     *      maxLength=50,
     *      nullable=true
     * )
     */
    public $calendar_text;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @OA\Property(
     *      description="Second name of the inspector",
     *      type="string",
     *      maxLength=50,
     *      nullable=true
     * )
     */
    public $tilsyn_name2;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @OA\Property(
     *      description="Second email of the inspector",
     *      type="string",
     *      maxLength=255,
     *      nullable=true
     * )
     */
    public $tilsyn_email2;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @OA\Property(
     *      description="Second phone number of the inspector",
     *      type="string",
     *      maxLength=50,
     *      nullable=true
     * )
     */
    public $tilsyn_phone2;

    /**
     * @ORM\Column(type="integer", options={"default" : 0})
     * @Expose
     * @Short
     * @OA\Property(
     *      description="Whether there is an extra calendar, 0 or 1",
     *      type="integer"
     * )
     */
    public $extra_kalendar;

    /**
     * @ORM\Column(type="integer")
     * @Expose
     * @Short
     * @OA\Property(
     *      description="Activity ID associated with the building",
     *      type="integer"
     * )
     */
    public $activity_id;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Expose
     * @OA\Property(
     *      description="Opening hours of the building",
     *      type="string",
     *      nullable=true
     * )
     */
    public $opening_hours;

    /**
     * @ORM\Column(type="json", nullable=true)
     * @Expose
     * @OA\Property(
     *      description="Description in JSON format",
     *      type="object",
     *      nullable=true
     * )
     */
    public $description_json;


    public function __construct($data = [])
    {
        if (!empty($data))
        {
            $this->populate($data);
        }
    }

    public function populate(array $data)
    {
        $this->id = $data['id'] ?? null;
        $this->town_id = $data['town_id'] ?? null;
        $this->name = $data['name'] ?? '';
        $this->homepage = $data['homepage'] ?? '';
        $this->phone = $data['phone'] ?? '';
        $this->email = $data['email'] ?? null;
        $this->active = isset($data['active']) ? (int)$data['active'] : 1;
        $this->street = $data['street'] ?? '';
        $this->zip_code = $data['zip_code'] ?? '';
        $this->city = $data['city'] ?? '';
        $this->district = $data['district'] ?? '';
        $this->location_code = $data['location_code'] ?? null;
        $this->deactivate_calendar = isset($data['deactivate_calendar']) ? (int)$data['deactivate_calendar'] : 0;
        $this->deactivate_application = isset($data['deactivate_application']) ? (int)$data['deactivate_application'] : 0;
        $this->deactivate_sendmessage = isset($data['deactivate_sendmessage']) ? (int)$data['deactivate_sendmessage'] : 0;
        $this->tilsyn_name = $data['tilsyn_name'] ?? null;
        $this->tilsyn_email = $data['tilsyn_email'] ?? null;
        $this->tilsyn_phone = $data['tilsyn_phone'] ?? null;
        $this->calendar_text = $data['calendar_text'] ?? null;
        $this->tilsyn_name2 = $data['tilsyn_name2'] ?? null;
        $this->tilsyn_email2 = $data['tilsyn_email2'] ?? null;
        $this->tilsyn_phone2 = $data['tilsyn_phone2'] ?? null;
        $this->extra_kalendar = isset($data['extra_kalendar']) ? (int)$data['extra_kalendar'] : 0;
        $this->activity_id = $data['activity_id'] ?? null;
        $this->opening_hours = $data['opening_hours'] ?? null;
        $this->description_json = $data['description_json'] ?? null;
    }

    public function toArray()
    {
        return [
            'id' => $this->id,
            'town_id' => $this->town_id,
            'name' => $this->name,
            'homepage' => $this->homepage,
            'phone' => $this->phone,
            'email' => $this->email,
            'active' => $this->active,
            'street' => $this->street,
            'zip_code' => $this->zip_code,
            'city' => $this->city,
            'district' => $this->district,
            'location_code' => $this->location_code,
            'deactivate_calendar' => $this->deactivate_calendar,
            'deactivate_application' => $this->deactivate_application,
            'deactivate_sendmessage' => $this->deactivate_sendmessage,
            'tilsyn_name' => $this->tilsyn_name,
            'tilsyn_email' => $this->tilsyn_email,
            'tilsyn_phone' => $this->tilsyn_phone,
            'calendar_text' => $this->calendar_text,
            'tilsyn_name2' => $this->tilsyn_name2,
            'tilsyn_email2' => $this->tilsyn_email2,
            'tilsyn_phone2' => $this->tilsyn_phone2,
            'extra_kalendar' => $this->extra_kalendar,
            'activity_id' => $this->activity_id,
            'opening_hours' => $this->opening_hours,
            'description_json' => $this->description_json,
        ];
    }
}