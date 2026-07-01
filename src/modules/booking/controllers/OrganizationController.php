<?php

namespace App\modules\booking\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database\Db;
use PDO;

/**
 * Booking organization endpoints.
 *
 * Serves the Tier 2 multi-group group picker on the digdir one-click
 * booking-create flow (#pe-queue/116): a booking is made by a specific group
 * (team) within an organization. Single-group orgs are resolved server-side on
 * create; organizations with several active groups need an explicit choice, so
 * the client lists them here and passes the chosen group_id.
 */
class OrganizationController
{
	/**
	 * GET /booking/organizations/{id}/groups
	 *
	 * The organization's ACTIVE groups as [{id, name}], name-sorted.
	 * {id} is the organization id (bb_organization.id — what bb_group.organization_id references).
	 */
	public function groups(Request $request, Response $response, array $args): Response
	{
		$orgId = (int) ($args['id'] ?? 0);

		$stmt = Db::getInstance()->prepare(
			'SELECT id, name FROM bb_group WHERE organization_id = :org_id AND active = 1 ORDER BY name ASC'
		);
		$stmt->execute([':org_id' => $orgId]);

		$groups = array_map(static function (array $row): array {
			return ['id' => (int) $row['id'], 'name' => $row['name']];
		}, $stmt->fetchAll(PDO::FETCH_ASSOC));

		$response->getBody()->write(json_encode($groups));
		return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
	}
}
