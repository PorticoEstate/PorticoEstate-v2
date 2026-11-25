<?php

namespace App\modules\booking\services;

use App\modules\phpgwapi\helpers\EmailTwigHelper;

/**
 * Composes the mail to the case officer when the applicant asks for a new time, or shortens the
 * approved one, on one allocation from Min side (GH #1392). Composition only — sending is the
 * notifier's job — so the content can be checked without ever touching the mail transport or
 * storage/emails.
 *
 * LANGUAGE. The mail is for the OFFICER, but it is composed inside the CITIZEN's request, whose
 * language is whatever their selected_lang cookie said (LangHelper sets the Translation singleton
 * from it). So the language is forced for the duration of the render to the officer's own — and
 * put back afterwards, also when the render throws. Keys are addressed "booking.<key>" explicitly,
 * so the module context of the acting request does not matter either. The same swap as
 * CateringMailRenderer::render.
 *
 * TEXT. Stored values (names) are decoded to plain text first (CateringText); the Twig
 * environment's autoescape escapes everything once when it lands in the HTML body. The applicant's
 * own comment is plain text typed into a text field: it is not decoded, only escaped. The subject
 * is plain text and is never HTML-escaped.
 */
final class AllocationTimeChangeMailRenderer
{
    /** A new time the case officer has yet to accept or reject. */
    public const KIND_REQUESTED = 'requested';
    /** A shorter time inside the approved one, already applied. */
    public const KIND_SHORTENED = 'shortened';

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
     *   occurrence: array{resource_name: string, date: string, old_time: string, new_time: string},
     *   applicant_comment?: string,
     *   links: array{case: string}
     * } $payload      `date` is d.m.Y and the times H:i–H:i, as the case comment shows them
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
        if (!in_array($kind, [self::KIND_REQUESTED, self::KIND_SHORTENED], true)) {
            throw new \InvalidArgumentException("Unknown allocation time change mail kind '{$kind}'");
        }

        $app = $payload['application'];
        $occurrence = $payload['occurrence'];
        $event = CateringText::decode($app['name'] ?? '');
        $actor = CateringText::decode($payload['actor'] ?? '');
        $organization = CateringText::decode($app['organization_name'] ?? '');

        $subject = CateringText::singleLine(
            lang("booking.allocation_change_mail_subject_{$kind}", $event, (string)$occurrence['date'])
        );

        $body = $this->twig->render('@views/emails/allocation_time_changed_by_applicant.twig', [
            'intro' => lang("booking.allocation_change_mail_intro_{$kind}", $actor !== '' ? $actor : lang('booking.catering_mail_the_applicant')),
            'organization' => $organization !== '' ? lang('booking.catering_mail_on_behalf_of', $organization) : '',
            'application' => [
                'id_string' => CateringText::decode($app['id_string'] ?? ''),
                'name' => $event,
                'building' => CateringText::decode($app['building_name'] ?? ''),
                'activity' => CateringText::decode($app['activity_name'] ?? ''),
            ],
            'occurrence' => [
                'resource' => CateringText::decode($occurrence['resource_name'] ?? ''),
                'date' => (string)$occurrence['date'],
                'old_time' => (string)$occurrence['old_time'],
                'new_time' => (string)$occurrence['new_time'],
            ],
            'comment' => trim((string)($payload['applicant_comment'] ?? '')),
            'links' => $payload['links'],
            'l' => [
                'application' => lang('booking.catering_mail_application'),
                'building' => lang('booking.catering_mail_building'),
                'activity' => lang('booking.catering_mail_activity'),
                'resource' => lang('booking.allocation_change_mail_resource'),
                'date' => lang('booking.allocation_change_mail_date'),
                'old_time' => lang('booking.allocation_change_mail_old_time'),
                'new_time' => lang('booking.allocation_change_mail_new_time'),
                'applicant_comment' => lang('booking.allocation_change_mail_applicant_comment'),
                'open_case' => lang('booking.catering_mail_open_case'),
            ],
        ]);

        return ['subject' => $subject, 'body' => $body];
    }
}
