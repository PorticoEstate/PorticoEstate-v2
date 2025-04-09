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
     * @Short
     */
    public int $id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public string $id_string;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public int $active;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public int $display_in_dashboard;

    /**
     * @OA\Property(type="string")
     * @Expose
     * @Short
     */
    public string $type;

    /**
     * @OA\Property(type="string")
     * @Expose
     * @Short
     */
    public string $status;

    /**
     * @OA\Property(type="string")
     * @Expose
     * @Short
     */
    public string $secret;

    /**
     * @OA\Property(type="string", format="date-time")
     * @Expose
     * @Short
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
	 * @EscapeString(mode="default")
	 * @Short
     */
    public $building_name;

    /**
     * @OA\Property(type="integer")
     * @Expose
     * @Short
     */
    public $building_id;

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
     * @Short
     */
    public $customer_ssn;

    /**
     * @OA\Property(type="string")
     * @Expose
     * @Short
     */
    public $customer_organization_number;

    /**
     * @OA\Property(type="string")
     * @Expose
	 * @EscapeString(mode="default")
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
     * @Short
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
     * @OA\Property(
     *     type="array",
     *     @OA\Items(
     *         type="object",
     *         @OA\Property(property="agegroup_id", type="integer"),
     *         @OA\Property(property="male", type="integer"),
     *         @OA\Property(property="female", type="integer")
     *     )
     * )
     * @Expose
     */
    public $agegroups;

    /**
     * @OA\Property(type="array", @OA\Items(type="integer"))
     * @Expose
     */
    public $audience;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/Date"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\bookingfrontend\models\helper\Date")
     * @Short
     */
    public $dates;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/Resource"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\bookingfrontend\models\Resource", short=true)
     * @Short
     */
    public $resources;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/Order"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\bookingfrontend\models\Order")
     */
    public $orders;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/Document"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\bookingfrontend\models\Document")
     */
    public array $documents;

    /**
     * @OA\Property(
     *     type="array",
     *     @OA\Items(
     *         type="object",
     *         @OA\Property(property="id", type="integer", description="Article mapping ID"),
     *         @OA\Property(property="quantity", type="integer", description="Quantity ordered"),
     *         @OA\Property(property="parent_id", type="integer", nullable=true, description="Optional parent mapping ID for sub-items")
     *     )
     * )
     * @Expose
     */
    public array $articles;

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