<?php

namespace App\modules\bookingfrontend\models;

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

}