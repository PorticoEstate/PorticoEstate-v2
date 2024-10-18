<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="Application",
 *     type="object",
 *     title="Application",
 *     description="Application model"
 * )
 * @Exclude
 */
class Application
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
    public $id_string;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $active;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $display_in_dashboard;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $type;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $status;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $secret;

    /**
     * @OA\Property(type="string", format="date-time")
     * @Expose
     */
    public $created;

    /**
     * @OA\Property(type="string", format="date-time")
     * @Expose
     */
    public $modified;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $building_name;

    /**
     * @OA\Property(type="string", format="date-time")
     * @Expose
     */
    public $frontend_modified;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $owner_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $case_officer_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $activity_id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $customer_identifier_type;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $customer_ssn;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $customer_organization_number;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $name;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $organizer;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $homepage;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $description;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $equipment;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $contact_name;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $contact_email;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $contact_phone;

    /**
     * @OA\Property(type="array", @OA\Items(type="integer"))
     * @Expose
     */
    public $audience;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/Date"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\bookingfrontend\models\helper\Date")
     */
    public $dates;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/Resource"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\bookingfrontend\models\Resource", short=true)
     */
    public $resources;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/Order"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\bookingfrontend\models\Order")
     */
    public $orders;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $responsible_street;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $responsible_zip_code;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $responsible_city;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $session_id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $agreement_requirements;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $external_archive_key;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $customer_organization_name;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $customer_organization_id;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->populate($data);
        }
    }

    public function populate(array $data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}