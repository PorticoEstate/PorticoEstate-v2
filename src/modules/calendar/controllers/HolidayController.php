<?php

namespace App\modules\calendar\controllers;

use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HolidayController
{
    private function holidays(): object
    {
        $holidays = \CreateObject('calendar.boholiday');
        $holidays->check_admin();
        return $holidays;
    }

    private function input(array $body, string $locale = ''): array
    {
        $values = (array) ($body['holiday'] ?? $body);
        $values['hol_id'] = (int) ($values['hol_id'] ?? 0);
        $values['locale'] = strtoupper(trim((string) ($values['locale'] ?? $locale)));
        $values['name'] = trim((string) ($values['name'] ?? ''));
        $values['mday'] = (int) ($values['mday'] ?? 0);
        $values['month_num'] = (int) ($values['month_num'] ?? 0);
        $values['occurence'] = (int) ($values['occurence'] ?? 0);
        $values['year'] = (int) ($values['year'] ?? 0);
        $values['dow'] = (int) ($values['dow'] ?? 0);
        $values['observance_rule'] = !empty($values['observance_rule']) ? 1 : 0;
        return $values;
    }

    private function validate(array $values): array
    {
        $errors = [];
        if (!preg_match('/^[A-Z]{2}$/', $values['locale'])) $errors[] = lang('Please enter a valid country code');
        if ($values['name'] === '') $errors[] = lang('Please enter a name');
        if ($values['month_num'] < 1 || $values['month_num'] > 12) $errors[] = lang('Please select a month');
        if ($values['mday'] < 0 || $values['mday'] > 31) $errors[] = lang('Please enter a valid day');
        if ($values['year'] < 0 || ($values['year'] > 0 && ($values['year'] < 1900 || $values['year'] > 2100))) $errors[] = lang('Please enter a valid year');
        if ($values['occurence'] < 0 || ($values['occurence'] > 99 && $values['year'] <= 0)) $errors[] = lang('Please enter a valid occurrence');
        if ($values['year'] > 0 && $values['occurence'] > 0) $errors[] = lang('You can only set a year or a occurrence');
        if ($values['year'] > 0 && $values['mday'] > 0) $errors[] = lang('You can only set a year or a day');
        if ($values['dow'] < 0 || $values['dow'] > 6) $errors[] = lang('Please select a valid weekday');
        if ($values['year'] <= 0 && (($values['mday'] > 0) === ($values['occurence'] > 0))) $errors[] = lang('You need to set either a day or a occurrence');
        return $errors;
    }

    private function mapHoliday(array $holiday): array
    {
        return [
            'id' => (int) ($holiday['index'] ?? $holiday['hol_id'] ?? 0),
            'locale' => (string) ($holiday['locale'] ?? ''),
            'name' => (string) ($holiday['name'] ?? ''),
            'mday' => (int) ($holiday['day'] ?? $holiday['mday'] ?? 0),
            'month_num' => (int) ($holiday['month'] ?? $holiday['month_num'] ?? 0),
            'occurence' => (int) ($holiday['occurence'] ?? 0),
            'dow' => (int) ($holiday['dow'] ?? 0),
            'observance_rule' => (int) ($holiday['observance_rule'] ?? 0),
        ];
    }

    public function locales(Request $request, Response $response): Response
    {
        $holidays = $this->holidays();
        $query = (string) ($request->getQueryParams()['search'] ?? '');
        $items = [];
        foreach ((array) $holidays->get_locale_list('', 'locale', $query) as $locale) $items[] = ['locale' => (string) $locale];
        return ResponseHelper::sendJSONResponse(['data' => $items, 'recordsTotal' => count($items), 'recordsFiltered' => count($items)]);
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        $query = $request->getQueryParams();
        $locale = strtoupper((string) ($args['locale'] ?? $query['locale'] ?? ''));
        $year = (int) ($query['year'] ?? 0);
        if (!preg_match('/^[A-Z]{2}$/', $locale)) return ResponseHelper::sendErrorResponse(['error' => 'Invalid locale'], 400);
        $holidays = $this->holidays();
        $rows = array_map([$this, 'mapHoliday'], (array) $holidays->get_holiday_list($locale, '', 'month_num,mday', '', '', $year));
        return ResponseHelper::sendJSONResponse(['data' => $rows, 'recordsTotal' => count($rows), 'recordsFiltered' => count($rows)]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $holiday = (array) $this->holidays()->read_entry((int) ($args['id'] ?? 0));
        return $holiday ? ResponseHelper::sendJSONResponse($this->mapHoliday($holiday)) : ResponseHelper::sendErrorResponse(['error' => 'Not found'], 404);
    }

    public function store(Request $request, Response $response): Response
    {
        $values = $this->input((array) ($request->getParsedBody() ?: []));
        $errors = $this->validate($values);
        if ($errors) return ResponseHelper::sendErrorResponse(['error' => implode(' ', $errors)], 422);
        if ($values['year'] > 0) $values['occurence'] = $values['year'];
        $this->holidays()->save_holiday($values);
        return ResponseHelper::sendJSONResponse(['message' => lang('Holiday saved')], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $values = $this->input((array) ($request->getParsedBody() ?: []));
        $values['hol_id'] = (int) ($args['id'] ?? $values['hol_id']);
        $errors = $this->validate($values);
        if ($errors) return ResponseHelper::sendErrorResponse(['error' => implode(' ', $errors)], 422);
        if ($values['year'] > 0) $values['occurence'] = $values['year'];
        $this->holidays()->save_holiday($values);
        return ResponseHelper::sendJSONResponse(['message' => lang('Holiday saved')]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->holidays()->so->delete_holiday((int) ($args['id'] ?? 0));
        return ResponseHelper::sendJSONResponse(['message' => lang('Holiday deleted')]);
    }

    public function destroyLocale(Request $request, Response $response, array $args): Response
    {
        $this->holidays()->so->delete_locale(strtoupper((string) ($args['locale'] ?? '')));
        return ResponseHelper::sendJSONResponse(['message' => lang('Locale deleted')]);
    }
}
