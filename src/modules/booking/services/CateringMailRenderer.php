<?php

namespace App\modules\booking\services;

use App\modules\phpgwapi\helpers\EmailTwigHelper;

/**
 * Composes the mail to the case officer when the applicant adds, changes or cancels a catering
 * order from Min side (GH #1425). Composition only — sending is the notifier's job — so the
 * content can be checked without ever touching the mail transport or storage/emails.
 *
 * LANGUAGE. The mail is for the OFFICER, but it is composed inside the CITIZEN's request, whose
 * language is whatever their selected_lang cookie said (LangHelper sets the Translation singleton
 * from it). So the language is forced for the duration of the render to the officer's own — and
 * put back afterwards, also when the render throws. Keys are addressed "booking.<key>" explicitly,
 * so the module context of the acting request does not matter either.
 *
 * TEXT. Every stored value arrives already decoded to plain text (CateringText); the Twig
 * environment's autoescape escapes it once when it lands in the HTML body. The subject is plain
 * text and is never HTML-escaped.
 */
final class CateringMailRenderer
{
    public const KIND_ADDED = 'added';
    public const KIND_CHANGED = 'changed';
    public const KIND_CANCELLED = 'cancelled';

    private const VENUE_TIMEZONE = 'Europe/Oslo';
    private const FIXED_FIELDS = ['hospitality', 'serving_time', 'location', 'comment', 'special_requirements', 'total'];

    private CateringLangSwitch $lang;
    /** @var object{render: callable}|EmailTwigHelper */
    private $twig;

    /**
     * @param object|null $twig anything with render(string $template, array $vars): string;
     *                          defaults to the booking module's email Twig environment
     */
    public function __construct(CateringLangSwitch $lang, ?object $twig = null)
    {
        $this->lang = $lang;
        $this->twig = $twig ?? new EmailTwigHelper('booking');
    }

    /**
     * @param array{
     *   kind: string,
     *   actor: string,
     *   application: array{id: int, id_string?: string, name?: string, building_name?: string, activity_name?: string, organization_name?: ?string},
     *   order: array{id: int, serving_time_iso?: ?string},
     *   fallback_date?: ?string,
     *   diff: array{rows: list<array{key: string, label: ?string, from: string, to: string}>},
     *   summary: list<array{key: string, label: ?string, value: string}>,
     *   links: array{case: string, order: string}
     * } $payload      `serving_time_iso` is naive UTC as stored; `fallback_date` is the
     *                 application's earliest date, naive Europe/Oslo
     * @return array{subject: string, body: string}
     */
    public function render(array $payload, string $officerLang): array
    {
        $saved = $this->lang->current();
        try {
            $this->lang->set($officerLang);
            return $this->compose($payload);
        } finally {
            $this->lang->set($saved);
        }
    }

    private function compose(array $payload): array
    {
        $kind = $payload['kind'];
        if (!in_array($kind, [self::KIND_ADDED, self::KIND_CHANGED, self::KIND_CANCELLED], true)) {
            throw new \InvalidArgumentException("Unknown catering mail kind '{$kind}'");
        }

        $app = $payload['application'];
        $event = CateringText::decode($app['name'] ?? '');
        $actor = CateringText::decode($payload['actor'] ?? '');
        $organization = CateringText::decode($app['organization_name'] ?? '');

        $subject = CateringText::singleLine(
            lang("booking.catering_mail_subject_{$kind}", $event, $this->subjectDate($payload))
        );

        // A change shows FROM -> TO; an addition or a cancellation shows the order itself.
        if ($kind === self::KIND_CHANGED) {
            $rows = array_map(fn(array $r): array => [
                'label' => $this->fieldLabel($r),
                'from' => $this->valueText($r['key'], $r['from']),
                'to' => $this->valueText($r['key'], $r['to']),
            ], $payload['diff']['rows']);
        } else {
            $rows = array_map(fn(array $r): array => [
                'label' => $this->fieldLabel($r),
                'value' => $this->valueText($r['key'], $r['value']),
            ], $payload['summary']);
        }

        $body = $this->twig->render('@views/emails/catering_changed_by_applicant.twig', [
            'kind' => $kind,
            'intro' => lang("booking.catering_mail_intro_{$kind}", $actor !== '' ? $actor : lang('booking.catering_mail_the_applicant')),
            'organization' => $organization !== '' ? lang('booking.catering_mail_on_behalf_of', $organization) : '',
            'rows' => $rows,
            'application' => [
                'id_string' => CateringText::decode($app['id_string'] ?? ''),
                'name' => $event,
                'building' => CateringText::decode($app['building_name'] ?? ''),
                'activity' => CateringText::decode($app['activity_name'] ?? ''),
            ],
            'links' => $payload['links'],
            'l' => [
                'application' => lang('booking.catering_mail_application'),
                'building' => lang('booking.catering_mail_building'),
                'activity' => lang('booking.catering_mail_activity'),
                'field' => lang('booking.catering_mail_field'),
                'from' => lang('booking.catering_mail_from'),
                'to' => lang('booking.catering_mail_to'),
                'value' => lang('booking.catering_mail_value'),
                'open_case' => lang('booking.catering_mail_open_case'),
                'open_order' => lang('booking.catering_mail_open_order'),
                'nothing' => lang('booking.catering_mail_nothing'),
            ],
        ]);

        return ['subject' => $subject, 'body' => $body];
    }

    /** d.m.Y of the order's serving time, else of the application's earliest date. */
    private function subjectDate(array $payload): string
    {
        $serving = $payload['order']['serving_time_iso'] ?? null;
        if ($serving !== null && $serving !== '') {
            try {
                return (new \DateTimeImmutable($serving, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone(self::VENUE_TIMEZONE))->format('d.m.Y');
            } catch (\Exception $e) {
                // fall through to the application's own date
            }
        }
        $fallback = $payload['fallback_date'] ?? null;
        if ($fallback !== null && $fallback !== '') {
            try {
                return (new \DateTimeImmutable($fallback, new \DateTimeZone(self::VENUE_TIMEZONE)))->format('d.m.Y');
            } catch (\Exception $e) {
                // no date is better than a wrong one
            }
        }
        return '';
    }

    private function fieldLabel(array $row): string
    {
        if (in_array($row['key'], self::FIXED_FIELDS, true)) {
            return lang('booking.catering_field_' . $row['key']);
        }
        // An article line is labelled with the article's own name.
        return (string)($row['label'] ?? $row['key']);
    }

    /** An empty side of a diff reads as "—"; the fixed status words are translated. */
    private function valueText(string $key, string $value): string
    {
        if ($value === '') {
            return lang('booking.catering_mail_nothing');
        }
        if ($key === 'status') {
            $map = ['pending' => 'pending_status', 'confirmed' => 'confirmed', 'delivered' => 'delivered', 'cancelled' => 'cancelled'];
            return isset($map[$value]) ? lang('booking.' . $map[$value]) : $value;
        }
        return $value;
    }
}
