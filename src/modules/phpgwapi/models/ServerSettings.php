<?php

namespace App\modules\phpgwapi\models;

use App\modules\booking\models\BookingConfig;
use App\modules\bookingfrontend\models\BookingfrontendConfig;
use App\modules\phpgwapi\services\Config;
use App\modules\phpgwapi\services\Settings;
use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="ServerSettings",
 *     type="object",
 *     title="ServerSettings",
 *     description="Comprehensive Server Settings model"
 * )
 * @Exclude
 */
class ServerSettings
{
    use SerializableTrait;

    /**
     * @OA\Property(type="integer")
     */
    public $account_max_id;

    /**
     * @OA\Property(type="integer")
     */
    public $account_min_id;

    /**
     * @OA\Property(type="string")
     */
    public $account_repository;

    /**
     * @OA\Property(type="string")
     */
    public $acl_default;

    /**
     * @OA\Property(type="integer")
     */
    public $addressmaster;

    /**
     * @OA\Property(type="string")
     */
    public $asyncservice;

    /**
     * @OA\Property(type="string")
     */
    public $auth_type;

    /**
     * @OA\Property(type="integer")
     */
    public $auto_create_expire;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $bakcground_image;

    /**
     * @OA\Property(type="integer")
     */
    public $block_time;

    /**
     * @OA\Property(type="integer")
     */
    public $cache_refresh_token;

    /**
     * @OA\Property(type="string")
     */
    public $cookie_domain;

    /**
     * @OA\Property(type="string")
     */
    public $daytime_port;

    /**
     * @OA\Property(type="string")
     */
    public $Debugoutput;

    /**
     * @OA\Property(type="boolean")
     */
    public $disable_autoload_langfiles;

    /**
     * @OA\Property(type="string")
     */
    public $encryption_type;

    /**
     * @OA\Property(type="string")
     */
    public $encryptkey;

    /**
     * @OA\Property(type="string")
     */
    public $file_repository;

    /**
     * @OA\Property(type="string")
     */
    public $files_dir;

    /**
     * @OA\Property(type="string")
     */
    public $file_store_contents;

    /**
     * @OA\Property(type="integer")
     */
    public $group_max_id;

    /**
     * @OA\Property(type="integer")
     */
    public $group_min_id;

    /**
     * @OA\Property(type="string")
     */
    public $hostname;

    /**
     * @OA\Property(type="string")
     */
    public $install_id;

    /**
     * @OA\Property(type="boolean")
     * @Expose
     */
    public $is_test_server;

    /**
     * @OA\Property(type="object")
     */
    public $lang_ctimes;

    /**
     * @OA\Property(type="string")
     */
    public $ldap_account_home;

    /**
     * @OA\Property(type="string")
     */
    public $ldap_account_shell;

    /**
     * @OA\Property(type="string")
     */
    public $ldap_encryption_type;

    /**
     * @OA\Property(type="string")
     */
    public $ldap_host;

    /**
     * @OA\Property(type="string")
     */
    public $ldap_root_pw;

    /**
     * @OA\Property(type="object")
     */
    public $log_levels;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $logo_url;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $logo_title;

    /**
     * @OA\Property(type="string")
     */
    public $mapping;

    /**
     * @OA\Property(type="integer")
     */
    public $max_access_log_age;

    /**
     * @OA\Property(type="integer")
     */
    public $max_history;

    /**
     * @OA\Property(type="string")
     */
    public $mcrypt_algo;

    /**
     * @OA\Property(type="string")
     */
    public $mcrypt_mode;

    /**
     * @OA\Property(type="integer")
     */
    public $num_unsuccessful_id;

    /**
     * @OA\Property(type="integer")
     */
    public $num_unsuccessful_ip;

    /**
     * @OA\Property(type="string")
     */
    public $password_level;

    /**
     * @OA\Property(type="integer")
     */
    public $sessions_app_timeout;

    /**
     * @OA\Property(type="integer")
     */
    public $sessions_timeout;

    /**
     * @OA\Property(type="string")
     */
    public $showpoweredbyon;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $site_title;

    /**
     * @OA\Property(type="integer")
     */
    public $SMTPDebug;

    /**
     * @OA\Property(type="string")
     */
    public $smtp_server;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $support_address;

    /**
     * @OA\Property(type="string")
     */
    public $temp_dir;

    /**
     * @OA\Property(type="boolean")
     */
    public $usecookies;

    /**
     * @OA\Property(type="string")
     */
    public $useframes;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $webserver_url;

    /**
     * @OA\Property(ref="#/components/schemas/BookingfrontendConfig")
     * @Expose
     * @SerializeAs(type="object", of="App\modules\bookingfrontend\models\BookingfrontendConfig")
     */
    public $bookingfrontend_config;

    /**
     * @OA\Property(ref="#/components/schemas/BookingConfig")
     * @Expose
     * @SerializeAs(type="object", of="App\modules\booking\models\BookingConfig")
     */
    public $booking_config;


    /**
     * Default values for settings from head.inc.php
     */
    private $defaults = [
        'site_title' => 'please set a site name in admin > siteconfig',
        'support_address' => 'support@aktivkommune.no',
        'logo_title' => 'Logo'
    ];

    public function __construct(array $data = [], bool $includeConfigs = false)
    {
        $this->populate($data); // Populate first to set the initial values
        $this->setDefaults();   // Then set defaults for properties that were not set in populate
        $this->setTestServerFlag(); // Determine if this is a test server based on hostname
        if ($includeConfigs) {
            $this->loadConfigs();
        }
    }


    private function loadConfigs()
    {
        $bookingfrontendData = $this->getConfig('bookingfrontend');
        $this->bookingfrontend_config = new BookingfrontendConfig($bookingfrontendData);

		$bookingData = $this->getConfig('booking');
		$this->booking_config = new BookingConfig($bookingData);
    }
    private function getConfig(string $module): array
    {
        $config = new Config($module);
        return $config->read();
    }

    public static function getInstance(bool $includeConfigs = false): ServerSettings
    {
        $settings = Settings::getInstance();
        $serverSettingsRaw = $settings->get('server');
        return new ServerSettings($serverSettingsRaw, $includeConfigs);
    }

    private function setDefaults()
    {
        foreach ($this->defaults as $key => $value)
        {
            if (property_exists($this, $key) && $this->$key === null)
            {
                // Only set the default if the property is currently null
                if ($key === 'logo_url')
                {
                    $this->$key = $this->getDefaultLogoUrl();
                } else
                {
                    $this->$key = $value;

                }
            }
        }
    }

    private function setTestServerFlag()
    {
        $this->is_test_server = in_array($this->hostname, ['test.aktiv-kommune.no', 'pe-api.test']);
    }


    public function populate(array $data)
    {
        foreach ($data as $key => $value)
        {
            if (property_exists($this, $key))
            {
                switch ($key)
                {
                    case 'account_max_id':
                    case 'account_min_id':
                    case 'addressmaster':
                    case 'auto_create_expire':
                    case 'block_time':
                    case 'cache_refresh_token':
                    case 'group_max_id':
                    case 'group_min_id':
                    case 'max_access_log_age':
                    case 'max_history':
                    case 'num_unsuccessful_id':
                    case 'num_unsuccessful_ip':
                    case 'sessions_app_timeout':
                    case 'sessions_timeout':
                    case 'SMTPDebug':
                        $this->$key = (int)$value;
                        break;
                    case 'disable_autoload_langfiles':
                    case 'usecookies':
                    case 'no_jscombine':
                        $this->$key = $value === 'True' || $value === true;
                        break;
                    case 'lang_ctimes':
                    case 'log_levels':
                        $this->$key = is_array($value) ? $value : $this->safeUnserialize($value);
                        break;
                    case 'webserver_url':
                        $this->$key = $value ? $value . PHPGW_MODULES_PATH : PHPGW_MODULES_PATH;
                        break;
                    default:
                        $this->$key = $value;
                }
            }
        }
    }

    private function safeUnserialize($value)
    {
        if (is_string($value))
        {
            $unserialized = @unserialize($value);
            return $unserialized !== false ? $unserialized : $value;
        }
        return $value;
    }

    private function getDefaultLogoUrl()
    {
        $webserver_url = $this->webserver_url ?: PHPGW_MODULES_PATH;
        return $webserver_url . "/phpgwapi/templates/bookingfrontend_2/img/Aktiv-kommune-footer-logo.png";
    }

}