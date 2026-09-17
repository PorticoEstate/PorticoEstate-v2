<?php

namespace App\modules\calendar\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\calendar\services\HolidayLoader;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HolidayViewController
{
    private TwigHelper $twig;
    private LegacyViewHelper $legacyView;

    public function __construct()
    {
        $this->twig = new TwigHelper('calendar');
        $this->legacyView = new LegacyViewHelper();
    }

    private function render(Request $request, Response $response, string $template, array $vars): Response
    {
        Settings::getInstance()->update('flags', ['app_header' => lang('Calendar') . ' - ' . lang('Holiday Management')]);
        $html = $this->twig->render($template, array_merge(['layout' => '@views/_bare.twig'], $vars));
        $response->getBody()->write($this->legacyView->render($html, ['calendar', 'holiday'], 'calendar::holiday'));
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function index(Request $request, Response $response): Response
    {
        $holidays = \CreateObject('calendar.boholiday');
        $holidays->check_admin();
        $locales = (new HolidayLoader())->availableLocales();
        foreach ((array) $holidays->get_locale_list('', 'locale', '') as $locale)
        {
            $locales[] = strtoupper((string) $locale);
        }
        $locales = array_values(array_unique($locales));
        sort($locales);

        return $this->render($request, $response, '@views/holiday/holiday_locales.twig', [
            'api_url' => \phpgw::link('/calendar/holidays/locales'),
            'holiday_url_template' => \phpgw::link('/calendar/view/holidays/{locale}'),
            'delete_url_template' => \phpgw::link('/calendar/holidays/locale/{locale}'),
            'import_url_template' => \phpgw::link('/calendar/view/holidays/{locale}/import'),
            'new_url' => \phpgw::link('/calendar/view/holidays/new'),
            'locales' => $locales,
        ]);
    }

    public function import(Request $request, Response $response, array $args): Response
    {
        $locale = strtoupper((string)($args['locale'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $locale))
        {
            return $response->withStatus(404);
        }

        $holidays = \CreateObject('calendar.boholiday');
        $holidays->check_admin();
        if ($holidays->so->holiday_total($locale) === 0)
        {
            foreach ((new HolidayLoader())->load($locale) as $holiday)
            {
                $holidays->save_holiday($holiday);
            }
        }

        return $response
            ->withHeader('Location', \phpgw::link('/calendar/view/holidays/' . $locale))
            ->withStatus(303);
    }

    public function holidays(Request $request, Response $response, array $args): Response
    {
        $locale = strtoupper((string) ($args['locale'] ?? ''));
        return $this->render($request, $response, '@views/holiday/holiday_list.twig', [
            'locale' => $locale,
            'api_url' => \phpgw::link('/calendar/holidays/' . $locale),
            'list_url' => \phpgw::link('/calendar/view/holidays'),
            'new_url' => \phpgw::link('/calendar/view/holidays/' . $locale . '/new'),
            'edit_url_template' => \phpgw::link('/calendar/view/holidays/' . $locale . '/__HOLIDAY_ID__/edit'),
              'delete_url_template' => \phpgw::link('/calendar/holidays/{id}'),
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $locale = strtoupper((string) ($args['locale'] ?? ''));
        $id = (int) ($args['id'] ?? 0);
        $values = [];
        if ($id)
        {
            $values = (array) \CreateObject('calendar.boholiday')->read_entry($id);
        }
        $listUrl = $locale
            ? \phpgw::link('/calendar/view/holidays/' . $locale)
            : \phpgw::link('/calendar/view/holidays');
        return $this->render($request, $response, '@views/holiday/holiday_edit.twig', [
            'locale' => $locale,
            'holiday_id' => $id,
            'api_url' => $id ? \phpgw::link('/calendar/holidays/' . $id) : \phpgw::link('/calendar/holidays'),
            'form_url' => $id ? \phpgw::link('/calendar/holidays/' . $id) : \phpgw::link('/calendar/holidays'),
            'list_url' => $listUrl,
            'values' => $values,
        ]);
    }
}
