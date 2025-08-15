<?php

namespace App\modules\booking\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="BookingConfig",
 *     type="object",
 *     title="BookingConfig",
 *     description="Booking Configuration model"
 * )
 * @Exclude
 */
class BookingConfig
{
    use SerializableTrait;

    /**
     * @OA\Property(type="string")
	 * @Expose
     * @ParseBool
     */
    public $activate_application_articles;

    /**
     * @OA\Property(type="string")
     */
    public $allocation_canceled_mail;

    /**
     * @OA\Property(type="string")
     */
    public $allocation_canceled_mail_subject;

    /**
     * @OA\Property(type="string")
     */
    public $application_comment_mail_subject;

    /**
     * @OA\Property(type="string")
     */
    public $application_comment_mail_subject_caseofficer;

    /**
     * @OA\Property(type="string")
     */
    public $application_contact_information;

    /**
     * @OA\Property(type="string")
     */
    public $application_description;

    /**
     * @OA\Property(type="string")
     */
    public $application_equipment;

    /**
     * @OA\Property(type="string")
     */
    public $application_howmany;

    /**
     * @OA\Property(type="string")
     */
    public $application_invoice_information;

    /**
     * @OA\Property(type="string")
     */
    public $application_mail_accepted;

    /**
     * @OA\Property(type="string")
     */
    public $application_mail_created;

    /**
     * @OA\Property(type="string")
     */
    public $application_mail_pending;

    /**
     * @OA\Property(type="string")
     */
    public $application_mail_rejected;

    /**
     * @OA\Property(type="string")
     */
    public $application_mail_signature;

    /**
     * @OA\Property(type="string")
     */
    public $application_mail_subject;

    /**
     * @OA\Property(type="string")
     */
    public $application_mail_systemname;

    /**
     * @OA\Property(type="string")
     */
    public $application_new_application;

    /**
     * @OA\Property(type="string")
     */
    public $application_notify_on_accepted;

    /**
     * @OA\Property(type="string")
     */
    public $application_responsible_applicant;

    /**
     * @OA\Property(type="string")
     */
    public $application_terms;

    /**
     * @OA\Property(type="string")
     */
    public $application_terms2;

    /**
     * @OA\Property(type="string")
     */
    public $application_when;

    /**
     * @OA\Property(type="string")
     */
    public $application_where;

    /**
     * @OA\Property(type="string")
     */
    public $application_who;

    /**
     * @OA\Property(type="string")
     */
    public $article;

    /**
     * @OA\Property(type="string")
     */
    public $booking_canceled_mail;

    /**
     * @OA\Property(type="string")
     */
    public $booking_canceled_mail_subject;

    /**
     * @OA\Property(type="string")
     */
    public $customer_list_format;

    /**
     * @OA\Property(type="string")
     */
    public $dim_1;

    /**
     * @OA\Property(type="string")
     */
    public $dim_2;

    /**
     * @OA\Property(type="string")
     */
    public $dim_3;

    /**
     * @OA\Property(type="string")
     */
    public $dim_5;

    /**
     * @OA\Property(type="string")
     */
    public $dim_value_1;

    /**
     * @OA\Property(type="string")
     */
    public $dim_value_4;

    /**
     * @OA\Property(type="string")
     */
    public $dim_value_5;

    /**
     * @OA\Property(type="string")
     */
    public $emails;

    /**
     * @OA\Property(type="string")
     */
    public $email_sender;

    /**
     * @OA\Property(type="string")
     */
    public $enable_upload_attachment;

    /**
     * @OA\Property(type="string")
     */
    public $event_canceled_mail;

    /**
     * @OA\Property(type="string")
     */
    public $event_canceled_mail_subject;

    /**
     * @OA\Property(type="string")
     */
    public $event_change_mail;

    /**
     * @OA\Property(type="string")
     */
    public $event_change_mail_subject;

    /**
     * @OA\Property(type="string")
     */
    public $event_conflict_mail_subject;

    /**
     * @OA\Property(type="string")
     */
    public $event_edited_mail;

    /**
     * @OA\Property(type="string")
     */
    public $event_edited_mail_subject;

    /**
     * @OA\Property(type="string")
     */
    public $event_mail_building;

    /**
     * @OA\Property(type="string")
     */
    public $event_mail_building_subject;

    /**
     * @OA\Property(type="string")
     */
    public $event_mail_conflict_contact_active_collision;

    /**
     * @OA\Property(type="string")
     */
    public $external_format;

    /**
     * @OA\Property(type="string")
     */
    public $external_format_linebreak;

    /**
     * @OA\Property(type="string")
     */
    public $external_site_address;

    /**
     * @OA\Property(type="string")
     */
    public $extra_schedule;

    /**
     * @OA\Property(type="string")
	 * @Expose
     */
    public $frontimagetext;

    /**
     * @OA\Property(type="string")
     */
    public $frontpage_filterboxdata;

    /**
     * @OA\Property(type="string")
	 * @Expose
     */
    public $frontpagetext;

    /**
     * @OA\Property(type="string")
	 * @Expose
     */
    public $frontpagetitle;

    /**
     * @OA\Property(type="string")
     */
    public $image_maxheight;

    /**
     * @OA\Property(type="string")
     */
    public $image_maxwidth;

    /**
     * @OA\Property(type="string")
     */
    public $internal_format;

    /**
     * @OA\Property(type="string")
     */
    public $invoice_export_method;

    /**
     * @OA\Property(type="string")
     */
    public $invoice_export_path;

    /**
     * @OA\Property(type="string")
     */
    public $invoice_ftp_host;

    /**
     * @OA\Property(type="string")
     */
    public $invoice_ftp_password;

    /**
     * @OA\Property(type="string")
     */
    public $invoice_ftp_user;

    /**
     * @OA\Property(type="string")
     */
    public $invoice_last_id;

    /**
	 * @OA\Property(type="array", @OA\Items(type="string"))
	 * @Expose
     */
    public $landing_sections;

    /**
     * @OA\Property(type="string")
     */
    public $logopath_frontend;

    /**
     * @OA\Property(type="string")
     */
    public $mail_users_season;

    /**
     * @OA\Property(type="string")
     */
    public $metatag_author;

    /**
     * @OA\Property(type="string")
     */
    public $metatag_robots;

    /**
     * @OA\Property(type="string")
     */
    public $organization_value;

    /**
     * @OA\Property(type="string")
     */
    public $output_files;

    /**
     * @OA\Property(type="string")
     */
    public $participant_limit_sms;

    /**
     * @OA\Property(type="string")
     */
    public $participanttext;

    /**
     * @OA\Property(type="string")
     */
    public $proxy;

    /**
     * @OA\Property(type="string")
     */
    public $split_pool;

    /**
     * @OA\Property(type="string")
     */
    public $split_pool4_ids;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $support_address;

    /**
     * @OA\Property(type="string")
     */
    public $user_can_delete;

    /**
     * @OA\Property(type="string")
	 * @Expose
	 * @ParseBool
     */
    public $user_can_delete_allocations;

    /**
     * @OA\Property(type="string")
     */
    public $user_can_delete_bookings;

    /**
     * @OA\Property(type="string")
     */
    public $user_can_delete_events;

    /**
     * @OA\Property(type="string")
     */
    public $voucher_client;

    /**
     * @OA\Property(type="string")
     */
    public $voucher_responsible;

    /**
     * @OA\Property(type="string")
     */
    public $voucher_type;

    /**
     * @OA\Property(type="boolean")
	 * @Expose
	 * @ParseBool
     */
    public $combined_applications_mode;

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