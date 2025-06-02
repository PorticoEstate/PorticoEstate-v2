<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="BookingfrontendConfig",
 *     type="object",
 *     title="BookingfrontendConfig",
 *     description="Bookingfrontend Configuration model"
 * )
 * @Exclude
 */
class BookingfrontendConfig
{
    use SerializableTrait;


    /**
     * @OA\Property(type="string")
     */
    public $anonymous_passwd;

    /**
     * @OA\Property(type="string")
     */
    public $anonymous_user;

    /**
     * @OA\Property(type="string")
     */
    public $authentication_method;

    /**
     * @OA\Property(type="string")
	 * @Expose 
     */
    public $develope_mode;

    /**
     * @OA\Property(type="string")
     */
    public $footer_info;

    /**
     * @OA\Property(type="string")
     */
    public $footer_privacy_link;

    /**
     * @OA\Property(type="string")
     */
    public $site_title;

    /**
     * @OA\Property(type="string")
     */
    public $soap_password;

    /**
     * @OA\Property(type="string")
     */
    public $test_ssn;

    /**
     * @OA\Property(type="string")
     */
    public $usecookies;

    /**
     * @OA\Property(type="string")
	 * @Expose
     */
    public $url_uustatus;

    /**
     * @OA\Property(type="string")
	 * @Expose
     */
    public $url_system_feedback;

    public function __construct(?array $data)
    {
        if (is_array($data))
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
}