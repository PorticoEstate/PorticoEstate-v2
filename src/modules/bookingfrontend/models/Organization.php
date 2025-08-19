<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;
use OpenApi\Annotations as OA;

/**
 * @ORM\Entity
 * @ORM\Table(name="bb_organization")
 * @OA\Schema(
 *      schema="Organization",
 *      type="object",
 *      title="Organization",
 *      description="Organization model for Norwegian municipalities booking system",
 *      required={"id", "name", "active", "organization_number", "customer_internal", "show_in_portal"}
 * )
 * @Exclude
 */
class Organization
{
    use SerializableTrait;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Expose
     * @Short
     * @OA\Property(description="Unique identifier for the organization", type="integer", example=1)
     */
    public $id;

    /**
     * @ORM\Column(type="string", length=150, nullable=false)
     * @Expose
     * @Short
     * @EscapeString(mode="default")
     * @OA\Property(description="Name of the organization", type="string", maxLength=150, example="Sports Club Oslo")
     */
    public $name;

    /**
     * @ORM\Column(type="string", length=9, nullable=false)
     * @Expose
     * @Short
     * @OA\Property(description="Norwegian organization number (9 digits)", type="string", maxLength=9, pattern="^[0-9]{9}$", example="123456789")
     */
    public $organization_number;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Homepage URL of the organization", type="string", nullable=true, example="https://www.sportsclub.no")
     */
    public $homepage;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Phone number of the organization", type="string", maxLength=50, nullable=true, example="+47 22 12 34 56")
     */
    public $phone;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Email address of the organization", type="string", maxLength=255, nullable=true, format="email", example="contact@sportsclub.no")
     */
    public $email;

    /**
     * @ORM\Column(type="integer", nullable=false)
     * @Expose(when={"$user_has_access=true"})
     * @Short
     * @OA\Property(description="Whether the organization is active (1=active, 0=inactive)", type="integer", enum={0, 1}, example=1)
     */
    public $active;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Street address", type="string", maxLength=255, nullable=true, example="Sportsgata 1")
     */
    public $street;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Zip code", type="string", maxLength=255, nullable=true, example="0123")
     */
    public $zip_code;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="City", type="string", maxLength=255, nullable=true, example="Oslo")
     */
    public $city;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="District or borough", type="string", maxLength=255, nullable=true, example="Sentrum")
     */
    public $district;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Activity ID reference", type="integer", nullable=true, example=5)
     */
    public $activity_id;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Expose(when={"$user_has_access=true"})
     * @Short
     * @OA\Property(description="Customer number for billing/invoicing", type="string", nullable=true, example="CUST-2024-001")
     */
    public $customer_number;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose(when={"$user_has_access=true"})
     * @Short
     * @OA\Property(description="Type of customer identifier", type="string", maxLength=255, nullable=true, example="organization_number")
     */
    public $customer_identifier_type;

    /**
     * @ORM\Column(type="string", length=9, nullable=true)
     * @Expose(when={"$user_has_access=true"})
     * @Short
     * @OA\Property(description="Customer organization number (9 digits)", type="string", maxLength=9, nullable=true, pattern="^[0-9]{9}$", example="987654321")
     */
    public $customer_organization_number;

    /**
     * @ORM\Column(type="string", length=12, nullable=true)
     * @Expose(when={"$user_has_access=true"})
     * @Short
     * @OA\Property(description="Customer SSN for personal organizations (Norwegian format, 11 digits) - RESTRICTED: Only visible to organization admins and delegates", type="string", maxLength=12, nullable=true, pattern="^[0-9]{11}$", example="12345678901")
     */
    public $customer_ssn;

    /**
     * @ORM\Column(type="integer", nullable=false)
     * @Expose(when={"$user_has_access=true"})
     * @Short
     * @OA\Property(description="Whether this is an internal customer (1=internal, 0=external)", type="integer", enum={0, 1}, example=1)
     */
    public $customer_internal;

    /**
     * @ORM\Column(type="string", length=11, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Short name/abbreviation for the organization", type="string", maxLength=11, nullable=true, example="SC Oslo")
     */
    public $shortname;

    /**
     * @ORM\Column(type="integer", nullable=false)
     * @Expose(when={"$user_has_access=true"})
     * @Short
     * @OA\Property(description="Whether to show in public portal (1=visible, 0=hidden)", type="integer", enum={0, 1}, example=1)
     */
    public $show_in_portal;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose(when={"$user_has_access=true"})
     * @Short
     * @OA\Property(description="Whether organization is registered in tax register (1=yes, 0=no)", type="integer", enum={0, 1}, nullable=true, example=1)
     */
    public $in_tax_register;

    /**
     * @ORM\Column(type="string", length=150, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Care of address line", type="string", maxLength=150, nullable=true, example="c/o John Doe")
     */
    public $co_address;

    /**
     * @ORM\Column(type="jsonb", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(
     *      description="Multi-language descriptions in JSON format",
     *      type="object",
     *      nullable=true,
     *      example={"no":"Idrettsklubb som tilbyr fotball, basketball og tennis","nn":"Idrettsklubb som tilbyr fotball, basketball og tennis","en":"Sports club offering football, basketball and tennis"}
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
        foreach ($data as $key => $value)
        {
            if (property_exists($this, $key))
            {
                $this->$key = $value;
            }
        }
    }
}