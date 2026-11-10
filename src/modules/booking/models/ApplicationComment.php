<?php

namespace App\modules\booking\models;

/**
 * @OA\Schema(
 *     schema="ApplicationComment",
 *     type="object",
 *     title="ApplicationComment",
 *     description="Application comment model"
 * )
 * @Exclude
 */
class ApplicationComment extends BaseComment
{
    /**
     * @OA\Property(type="integer", description="Application ID this comment belongs to")
     * @Expose
     */
    public int $application_id;

    /**
     * @OA\Property(
     *     type="string",
     *     nullable=true,
     *     enum={"allocation", "event", "booking"},
     *     description="The kind of application element the comment is about; null for the whole application"
     * )
     * @Expose
     */
    public ?string $element_type = null;

    /**
     * @OA\Property(type="integer", nullable=true, description="Id of that element in its own table; ids overlap across the three tables, so read it with element_type")
     * @Expose
     */
    public ?int $element_id = null;

    /**
     * @OA\Property(
     *     type="object",
     *     nullable=true,
     *     description="The element as it is now. exists=false when it has been deleted or no longer belongs to the application; otherwise from_/to_ are local time without an offset.",
     *     @OA\Property(property="type", type="string"),
     *     @OA\Property(property="id", type="integer"),
     *     @OA\Property(property="exists", type="boolean"),
     *     @OA\Property(property="from_", type="string"),
     *     @OA\Property(property="to_", type="string"),
     *     @OA\Property(property="active", type="boolean"),
     *     @OA\Property(property="resource_names", type="string")
     * )
     * @Expose
     */
    public ?array $element = null;

}
