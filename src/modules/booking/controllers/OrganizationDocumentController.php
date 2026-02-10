<?php

namespace App\modules\booking\controllers;

use App\modules\booking\models\Document;

class OrganizationDocumentController extends DocumentController
{
    public function __construct()
    {
        parent::__construct(Document::OWNER_ORGANIZATION);
    }
}
