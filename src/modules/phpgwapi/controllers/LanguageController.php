<?php

namespace App\modules\phpgwapi\controllers;

use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Translation;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Sanitizer;

class LanguageController
{
    public function __construct(ContainerInterface $container)
    {
        // Constructor for dependency injection if needed
    }

    /**
     * Get all installed languages with flag info and current selection
     */
    public function getLanguages(Request $request, Response $response): Response
    {
        $translation = Translation::getInstance();
        $userSettings = Settings::getInstance()->get('user');
        $installed_langs = $translation->get_installed_langs();

        $userlang = $userSettings['preferences']['common']['lang'] ?? 'en';
        $selected_lang = Sanitizer::get_var('selected_lang', 'string', 'COOKIE', $userlang);

        $languages = [];
        foreach ($installed_langs as $code => $name) {
            $flag_class = match (true) {
                in_array($code, ['no', 'nn']) => 'fi-no',
                $code === 'en' => 'fi-gb',
                default => "fi-{$code}",
            };

            $languages[] = [
                'code' => $code,
                'name' => lang($name),
                'flag_class' => $flag_class,
                'is_selected' => ($code === $selected_lang),
            ];
        }

        $result = [
            'languages' => $languages,
            'selected' => $selected_lang,
            'translations' => [
                'choose_language' => lang('choose language'),
                'choose_language_subtitle' => lang('Which language do you want?'),
            ],
        ];

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Set the user's language preference
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function setLanguage(Request $request, Response $response, array $args): Response
    {
        $lang = $args['lng'] ?? '';
        
        if (empty($lang)) {
            $error = ['error' => 'Language parameter is required'];
            $response->getBody()->write(json_encode($error));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $lang = Sanitizer::clean_value($lang, 'string');
        
        if (!$lang) {
            $error = ['error' => 'Invalid language parameter'];
            $response->getBody()->write(json_encode($error));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Set the language cookie for 14 days
        $sessions = Sessions::getInstance();
        $sessions->phpgw_setcookie('selected_lang', $lang, (time() + (60 * 60 * 24 * 14)));

        $result = ['success' => true, 'language' => $lang];
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}