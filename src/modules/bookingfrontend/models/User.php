<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;
use App\modules\bookingfrontend\helpers\UserHelper;

/**
 * @OA\Schema(
 *     schema="User",
 *     type="object",
 *     title="User",
 *     description="Expanded User model"
 * )
 * @Exclude
 */
class User
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
     * @OA\Property(type="string")
     * @Expose
     */
    public $ssn;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $orgnr;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $orgname;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $customer_number;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $org_id;

    /**
     * @OA\Property(type="boolean")
     * @Expose
     */
    public $is_logged_in;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $homepage;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $phone;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $email;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $street;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $zip_code;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $city;

    /**
     * @OA\Property(
     *     type="array",
     *     @OA\Items(ref="#/components/schemas/Delegate")
     * )
     * @Expose
     * @SerializeAs(type="array", of="App\modules\bookingfrontend\models\Delegate")
     */
    public $delegates;

    public $userHelper;

    public function __construct(UserHelper $userHelper)
    {
        $this->userHelper = $userHelper;
        $this->ssn = $userHelper->ssn;
        $this->orgnr = $userHelper->orgnr;
        $this->orgname = $userHelper->orgname;
        $this->org_id = $userHelper->org_id;
        $this->is_logged_in = $userHelper->is_logged_in();
        $this->delegates = $userHelper->organizations;


        if ($this->is_logged_in) {
            $this->loadUserDetails();
        }
    }

    private function loadUserDetails()
    {
        $this->id = $this->userHelper->get_user_id($this->ssn);
        if ($this->id) {
            $userDetails = $this->userHelper->read_single($this->id);
            $this->name = $userDetails['name'] ?? null;
            $this->homepage = $userDetails['homepage'] ?? null;
            $this->phone = $userDetails['phone'] ?? null;
            $this->email = $userDetails['email'] ?? null;
            $this->street = $userDetails['street'] ?? null;
            $this->zip_code = $userDetails['zip_code'] ?? null;
            $this->city = $userDetails['city'] ?? null;
            $this->customer_number = $userDetails['customer_number'] ?? null;


//            $this->loadApplications();
//            $this->loadInvoices();
        }
    }
}