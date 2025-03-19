<?php

namespace App\modules\bookingfrontend\helpers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Translation;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

use Sanitizer;
use OpenApi\Annotations as OA;

class LangHelper
{
    /**
     * @OA\Get(
     *     path="/bookingfrontend/lang",
     *     summary="Get language settings",
     *     tags={"Translation"},
     *     @OA\Parameter(
     *         name="lang",
     *         in="query",
     *         description="Language code",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Language settings",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="key", type="string", description="Translation key"),
     *             @OA\Property(property="value", type="string", description="Translation value")
     *         )
     *     )
     * )
     */
    public function process(Request $request, Response $response, array $args = []): Response
    {
        $userSettings = Settings::getInstance()->get('user');
        $current_lang = Sanitizer::get_var('selected_lang', 'string', 'COOKIE');

        // Determine the selected language
        $selected_lang = $args['lang'] ??
            ($request->getQueryParams()['lang'] ??
                ($current_lang ?? "no"));

//        // Only set cookie if the language has changed
//        if ($selected_lang && $selected_lang !== $current_lang) {
//            $sessions = Sessions::getInstance();
//            $sessions->phpgw_setcookie('selected_lang', $selected_lang, (time() + (60 * 60 * 24 * 14)));
//        }

        $userlang = $selected_lang ?: $userSettings['preferences']['common']['lang'];

        $return_data = Cache::system_get('phpgwapi', "lang_{$userlang}", true);

        if (!$return_data) {
            $translation = Translation::getInstance();
            $translation->set_userlang($userlang, true);
            $translation->populate_cache();
            $return_data = Cache::system_get('phpgwapi', "lang_{$userlang}", true);
        }

        $response->getBody()->write(json_encode($return_data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}