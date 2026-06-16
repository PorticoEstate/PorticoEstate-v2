<?php

namespace App\modules\booking\controllers;

use App\modules\booking\models\Document;

class ResourceDocumentController extends DocumentController
{
    public function __construct()
    {
        parent::__construct(Document::OWNER_RESOURCE);
    }
}
