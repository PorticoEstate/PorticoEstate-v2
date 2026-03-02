<?php

namespace App\modules\bookingfrontend\models;

use App\modules\booking\models\Application as BookingApplication;

/**
 * Frontend Application model — extends the booking base with sub-resource
 * collections that are hydrated in a single fetch for the Next.js client.
 *
 * @OA\Schema(
 *     schema="Application",
 *     type="object",
 *     title="Application",
 *     description="Application model"
 * )
 * @Exclude
 */
class Application extends BookingApplication
{
    // ── Override: expose case_officer_id without ACL gate for frontend ──

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $case_officer_id;

    // ── Sub-resource collections (hydrated by bookingfrontend repo) ─────

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
     * @SerializeAs(type="array", of="App\modules\booking\models\Date")
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
     * @SerializeAs(type="array", of="App\modules\booking\models\Order")
     */
    public $orders;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/Document"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\booking\models\Document")
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
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/ApplicationComment"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\booking\models\ApplicationComment")
     */
    public array $comments = [];
}
