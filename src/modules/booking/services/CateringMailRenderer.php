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
 *
 * BODY. The change in the words of the order page's Endringslogg — the same CateringChangeText,
 * so the officer reads the same lines in the mail and on the page, incl. VAT per line — then the
 * order's cancellation deadline (not on a cancellation), then the links.
 */
final class CateringMailRenderer
{
    public const KIND_ADDED = 'added';
    public const KIND_CHANGED = 'changed';
    public const KIND_CANCELLED = 'cancelled';

    private const VENUE_TIMEZONE = 'Europe/Oslo';

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
     *   diff: array{rows: list<array>, old: array<string, string>, new: array<string, string>},
     *   summary: list<array{key: string, label: ?string, value: string}>,
     *   changed_at?: ?string,
     *   context?: array{hospitality?: ?string, serving_time?: ?string, location?: ?string},
     *   cancel_deadline?: ?string,
     *   links: array{case: string, order: string}
     * } $payload      `serving_time_iso` is naive UTC as stored; `fallback_date` is the
     *                 application's earliest date, naive Europe/Oslo; `changed_at` and
     *                 `cancel_deadline` are ISO-8601 instants; `context` is the order as it is now
     *                 (CateringChangeText::contextOf)
     * @return array{subject: string, body: string}
     */
    public function render(array $payload, string $officerLang): array
    {
        $saved = $this->lang->current();
        try {
            $this->lang->set($officerLang);
            return $this->compose($payload, $officerLang);
        } finally {
            $this->lang->set($saved);
        }
    }

    private function compose(array $payload, string $lang): array
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

        // The change as the Endringslogg words it: a change FROM -> TO, an addition or a cancellation
        // what the order contains.
        $summary = [];
        foreach ($payload['summary'] ?? [] as $row) {
            $summary[$row['key']] = $row['value'];
        }
        $log = CateringChangeText::describe([
            'kind' => $kind,
            'actor' => $payload['actor'] ?? '',
            'changed_at' => $payload['changed_at'] ?? null,
            'old' => $kind === self::KIND_CHANGED ? ($payload['diff']['old'] ?? []) : [],
            'new' => $kind === self::KIND_CHANGED ? ($payload['diff']['new'] ?? []) : $summary,
        ], $payload['context'] ?? [], $lang);

        // «Avbestillingsfrist», from the same guard verdict the applicant's own page shows; not on a
        // cancellation, and left out when there is none to show.
        $deadline = '';
        $until = $payload['cancel_deadline'] ?? null;
        if ($kind !== self::KIND_CANCELLED && $until !== null && $until !== '') {
            try {
                $deadline = CateringChangeText::deadlineLine(new \DateTimeImmutable($until), $lang);
            } catch (\Exception $e) {
                // no line is better than a wrong one
            }
        }

        $body = $this->twig->render('@views/emails/catering_changed_by_applicant.twig', [
            'intro' => lang("booking.catering_mail_intro_{$kind}", $actor !== '' ? $actor : lang('booking.catering_mail_the_applicant')),
            'organization' => $organization !== '' ? lang('booking.catering_mail_on_behalf_of', $organization) : '',
            'log' => $log,
            'deadline' => $deadline,
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
                'open_case' => lang('booking.catering_mail_open_case'),
                'open_order' => lang('booking.catering_mail_open_order'),
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
}
