<?php

namespace App\modules\bookingfrontend\services\applications;

/**
 * What the applicant may see of a catering order: the ONE place that decides it (GH #1423 follow-up).
 *
 * An allow-list, not a strip. HospitalityOrder is the admin's model too, so its serialisation carries
 * everything an officer needs: the changelog (with the officers' account_lid and internal comments)
 * and created_by / modified_by (which hold an officer's account id when an officer wrote). A list of
 * what to take away leaks the next field someone adds; a list of what is sent does not.
 *
 * The keys are the client's contract: IHospitalityOrder and IHospitalityOrderLine in
 * client/src/service/types/api/hospitality.types.ts. tests/hospitality-deadline/run_citizen.php
 * parses that file and fails if this class and the type drift apart in either direction.
 */
final class CitizenHospitalityOrder
{
    /** @var list<string> the keys of IHospitalityOrder that every endpoint returning an order sends */
    public const ORDER_KEYS = [
        'id',
        'application_id',
        'hospitality_id',
        'location_resource_id',
        'status',
        'comment',
        'special_requirements',
        'serving_time_iso',
        'hospitality_name',
        'location_name',
        'total_amount',
        'lines',
    ];

    /** @var list<string> the optional keys of IHospitalityOrder, sent by GET /hospitality-orders only */
    public const READ_KEYS = ['actions', 'contact'];

    /** @var list<string> the keys of IHospitalityOrderLine */
    public const LINE_KEYS = [
        'id',
        'order_id',
        'hospitality_article_id',
        'quantity',
        'unit_price',
        'tax_code',
        'amount',
        'comment',
        'article_name',
        'unit',
    ];

    /**
     * @param array $serialized the order as HospitalityOrder::serialize() returns it
     * @param array $read       what GET /hospitality-orders adds on top (`actions`, `contact`)
     * @return array            only the allowed keys; `lines` is always an array
     */
    public static function present(array $serialized, array $read = []): array
    {
        $out = array_intersect_key($serialized, array_flip(self::ORDER_KEYS));

        // serialize() drops an empty array, so an order with no lines used to come back with no
        // `lines` key although the client types it as always present.
        $lines = $out['lines'] ?? [];
        $out['lines'] = array_map(
            static fn($line) => is_array($line) ? array_intersect_key($line, array_flip(self::LINE_KEYS)) : $line,
            is_array($lines) ? array_values($lines) : []
        );

        return $out + array_intersect_key($read, array_flip(self::READ_KEYS));
    }
}
