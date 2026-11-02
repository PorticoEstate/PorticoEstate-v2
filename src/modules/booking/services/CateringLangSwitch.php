<?php

namespace App\modules\booking\services;

/**
 * The one global the catering mail has to borrow: the request's translation language.
 *
 * An interface so the swap-and-restore can be proven without the real Translation singleton
 * (which needs the database), and so the renderer cannot reach for it any other way.
 */
interface CateringLangSwitch
{
    /** The language in force right now, as a 2-letter code. */
    public function current(): string;

    /** Make $lang the language in force. */
    public function set(string $lang): void;
}
