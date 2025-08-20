<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * Base comment class for application comments
 * Can be extended in the future for other entity types like events
 *
 * @Exclude
 */
abstract class BaseComment
{
    use SerializableTrait;

    /**
     * @OA\Property(type="integer", description="Comment ID")
     * @Expose
     * @Short
     */
    public int $id;

    /**
     * @OA\Property(type="string", format="date-time", description="Comment creation timestamp")
     * @Expose
     * @Short
     * @Timestamp(format="c", sourceTimezone="UTC")
     */
    public $time;

    /**
     * @OA\Property(type="string", description="Comment author name")
     * @Expose
     * @Short
     * @EscapeString(mode="default")
     */
    public string $author;

    /**
     * @OA\Property(type="string", description="Comment text content")
     * @Expose
     * @Short
     * @EscapeString(mode="default")
     */
    public string $comment;

    /**
     * @OA\Property(
     *     type="string",
     *     description="Comment type",
     *     enum={"comment", "ownership", "status"},
     *     default="comment"
     * )
     * @Expose
     * @Short
     * @Default("comment")
     */
    public string $type = 'comment';


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
                $this->$key = $value;
            }
        }
    }


    /**
     * Convert to array for API responses
     */
    public function toArray(): array
    {
        return $this->serialize();
    }

    /**
     * Convert to array with short format for lists
     */
    public function toShortArray(): array
    {
        return $this->serialize([], true);
    }

}