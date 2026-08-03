<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use App\modules\booking\repositories\HospitalityOrderRepository;
use App\modules\booking\services\HospitalityDeadlineCalculator;

/**
 * Guards the two rules the catering serving time depends on (#373):
 *
 *  1. serving_time_iso is persisted as naive UTC. The column is `timestamp without time zone`,
 *     so an offset-aware value has to be converted before it reaches Postgres — otherwise the
 *     offset is silently dropped and the stored instant moves.
 *  2. open_days decides which weekday may be served, using the same mask semantics as the
 *     order-deadline countback.
 */
class HospitalityServingTimeTest extends TestCase
{
    private const OSLO = 'Europe/Oslo';

    public function testOffsetAwareInputIsConvertedToUtc(): void
    {
        // 23:00 in Oslo (+02:00) is 21:00 UTC — the offset must not simply be dropped.
        $this->assertSame(
            '2026-08-18 21:00:00',
            HospitalityOrderRepository::toStorageTimestamp('2026-08-18T23:00:00+02:00')
        );
    }

    public function testZuluAndFractionalInputAreStoredUnchanged(): void
    {
        $this->assertSame(
            '2026-08-18 21:00:00',
            HospitalityOrderRepository::toStorageTimestamp('2026-08-18T21:00:00Z')
        );
        $this->assertSame(
            '2026-08-18 21:00:00',
            HospitalityOrderRepository::toStorageTimestamp('2026-08-18T21:00:00.000Z')
        );
    }

    public function testNaiveValueIsTreatedAsUtcAndConversionIsIdempotent(): void
    {
        $stored = HospitalityOrderRepository::toStorageTimestamp('2026-08-18T23:00:00+02:00');

        $this->assertSame('2026-08-18 21:00:00', HospitalityOrderRepository::toStorageTimestamp($stored));
        $this->assertSame($stored, HospitalityOrderRepository::toStorageTimestamp($stored));
    }

    public function testEmptyServingTimeStaysNull(): void
    {
        $this->assertNull(HospitalityOrderRepository::toStorageTimestamp(null));
        $this->assertNull(HospitalityOrderRepository::toStorageTimestamp(''));
    }

    /**
     * The same wall-clock moment expressed three ways must normalise identically — this is what
     * lets an unchanged serving time be recognised as unchanged instead of "moved" (which would
     * wrongly re-validate, and reject, an order sitting on a since-closed day).
     */
    public function testEquivalentInstantsNormaliseIdentically(): void
    {
        $offsetAware = HospitalityOrderRepository::toStorageTimestamp('2026-06-07T16:00:00+02:00');
        $zulu = HospitalityOrderRepository::toStorageTimestamp('2026-06-07T14:00:00Z');
        $naive = HospitalityOrderRepository::toStorageTimestamp('2026-06-07 14:00:00');

        $this->assertSame($offsetAware, $zulu);
        $this->assertSame($offsetAware, $naive);
    }

    /**
     * The stored instant decides the serving weekday, read in venue-local time. An order for
     * 23:00 Oslo must stay on its own day and not slide into the next one.
     */
    public function testStoredInstantKeepsTheLocalServingWeekday(): void
    {
        $stored = HospitalityOrderRepository::toStorageTimestamp('2026-08-18T23:00:00+02:00');

        $local = (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::OSLO));

        $this->assertSame('2026-08-18', $local->format('Y-m-d'));
        $this->assertSame(2, (int)$local->format('N')); // Tuesday
    }

    public function testOpenDaysMaskDecidesServingWeekday(): void
    {
        // 31 = Mon-Fri open, weekend closed.
        $this->assertTrue(HospitalityDeadlineCalculator::isOpenOnWeekday(31, 2));
        $this->assertFalse(HospitalityDeadlineCalculator::isOpenOnWeekday(31, 6));
        $this->assertFalse(HospitalityDeadlineCalculator::isOpenOnWeekday(31, 7));

        // 65 = Monday + Sunday only.
        $this->assertTrue(HospitalityDeadlineCalculator::isOpenOnWeekday(65, 1));
        $this->assertTrue(HospitalityDeadlineCalculator::isOpenOnWeekday(65, 7));
        $this->assertFalse(HospitalityDeadlineCalculator::isOpenOnWeekday(65, 3));
    }

    /**
     * 127 is the default and must never reject a day — the pre-#373 behaviour. An empty mask is
     * treated the same way, matching the deadline arithmetic and the client's open-days helper.
     */
    public function testAllOpenAndEmptyMasksNeverReject(): void
    {
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $this->assertTrue(HospitalityDeadlineCalculator::isOpenOnWeekday(HospitalityDeadlineCalculator::ALL_DAYS_OPEN, $weekday));
            $this->assertTrue(HospitalityDeadlineCalculator::isOpenOnWeekday(0, $weekday));
        }
    }
}
