<?php

namespace App\modules\booking\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="Notification",
 *     type="object",
 *     title="Notification",
 *     description="In-app notification model"
 * )
 * @Exclude
 */
class Notification
{
    use SerializableTrait;

    /**
     * @OA\Property(type="integer", description="Notification ID")
     * @Expose
     */
    public int $id;

    /**
     * @OA\Property(type="string", description="Source type (e.g. application_comment)")
     * @Expose
     */
    public string $source_type;

    /**
     * @OA\Property(type="integer", description="Source entity ID")
     * @Expose
     */
    public int $source_id;

    /**
     * @OA\Property(type="string", description="Target entity type (e.g. application)")
     * @Expose
     */
    public string $entity_type;

    /**
     * @OA\Property(type="integer", description="Target entity ID")
     * @Expose
     */
    public int $entity_id;

    /**
     * @OA\Property(type="string", description="Recipient user type (e.g. phpgw_accounts, bb_user)")
     * @Expose
     */
    public string $recipient_user_type;

    /**
     * @OA\Property(type="string", description="Recipient identifier (account ID or SSN)")
     * @Expose
     */
    public string $recipient_identifier;

    /**
     * @OA\Property(type="string", description="Notification title (may be a translation key)")
     * @Expose
     */
    public string $title;

    /**
     * @OA\Property(type="string", nullable=true, description="Notification message text")
     * @Expose
     */
    public ?string $message = null;

    /**
     * @OA\Property(type="string", nullable=true, description="Link URL for the notification")
     * @Expose
     */
    public ?string $link = null;

    /**
     * @OA\Property(type="boolean", description="Whether the notification has been read")
     * @Expose
     */
    public bool $is_read = false;

    /**
     * @OA\Property(type="string", format="date-time", nullable=true, description="Timestamp when read")
     * @Expose
     */
    public ?string $read_at = null;

    /**
     * @OA\Property(type="object", nullable=true, description="Additional JSON data")
     * @Expose
     */
    public ?array $data = null;

    /**
     * @OA\Property(type="string", format="date-time", description="Creation timestamp")
     * @Expose
     * @Timestamp(format="c", sourceTimezone="UTC")
     */
    public $created;

    /**
     * @OA\Property(type="string", format="date-time", nullable=true, description="Expiration timestamp")
     * @Expose
     */
    public ?string $expires_at = null;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->populate($data);
        }
    }

    public function populate(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                if ($key === 'data' && is_string($value)) {
                    $this->$key = json_decode($value, true);
                } elseif ($key === 'is_read') {
                    $this->$key = (bool) $value;
                } else {
                    $this->$key = $value;
                }
            }
        }
    }

    public function toArray(): array
    {
        return $this->serialize();
    }
}
