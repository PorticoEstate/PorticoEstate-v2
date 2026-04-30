<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_document_view combining building and resource documents';

	public function up(): void
	{
		$this->sql(
			"CREATE OR REPLACE VIEW bb_document_view " .
			"AS SELECT bb_document.id AS id, bb_document.name AS name, bb_document.owner_id AS owner_id, bb_document.category AS category, bb_document.description AS description, bb_document.type AS type " .
			"FROM " .
			"((SELECT *, 'building' as type from bb_document_building) UNION ALL (SELECT *, 'resource' as type from bb_document_resource)) " .
			"as bb_document"
		);
	}
};
