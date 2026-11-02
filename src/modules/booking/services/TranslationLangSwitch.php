<?php

namespace App\modules\booking\services;

use App\modules\phpgwapi\services\Translation;

/**
 * The real language switch: the Translation singleton every lang() call reads.
 *
 * reset=true both ways. Translation keeps its string cache without a language in the key, so
 * changing the language without reloading the cache would go on serving the old language's text.
 * (bookingfrontend/helpers/LangHelper.php makes the same call to set the request language.)
 */
final class TranslationLangSwitch implements CateringLangSwitch
{
    public function current(): string
    {
        return (string)Translation::getInstance()->get_userlang();
    }

    public function set(string $lang): void
    {
        Translation::getInstance()->set_userlang($lang, true);
    }
}
