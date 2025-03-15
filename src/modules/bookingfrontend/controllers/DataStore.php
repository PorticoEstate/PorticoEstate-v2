<?php
namespace App\modules\bookingfrontend\controllers;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception; // For handling potential errors
use App\Database\Db;

/**
 * @OA\OpenApi(
 *    @OA\Server(url="http://localhost:8080"),
 *   @OA\Info(
 *    title="Portico API",
 *   version="1.0.0",
 *  description="Portico API",
 * @OA\Contact(
 * email="sigurdne@gmail.com"
 * )
 * )
 * )
 */

class DataStore
{
    private $db;

    public function __construct(ContainerInterface $container)
	{
		$this->db = Db::getInstance();

	}
	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/searchdataall",
	 *     summary="Get various search data",
	 *     tags={"Search Data"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="Successful response",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="activities", type="array", @OA\Items(ref="#/components/schemas/Activity")),
	 *             @OA\Property(property="buildings", type="array", @OA\Items(ref="#/components/schemas/Building")),
	 *             @OA\Property(property="building_resources", type="array", @OA\Items(ref="#/components/schemas/BuildingResource")),
	 *             @OA\Property(property="facilities", type="array", @OA\Items(ref="#/components/schemas/Facility")),
	 *             @OA\Property(property="resources", type="array", @OA\Items(ref="#/components/schemas/Resource")),
	 *             @OA\Property(property="resource_activities", type="array", @OA\Items(ref="#/components/schemas/ResourceActivity")),
	 *             @OA\Property(property="resource_facilities", type="array", @OA\Items(ref="#/components/schemas/ResourceFacility")),
	 *             @OA\Property(property="resource_categories", type="array", @OA\Items(ref="#/components/schemas/ResourceCategory")),
	 *             @OA\Property(property="resource_category_activity", type="array", @OA\Items(ref="#/components/schemas/ResourceCategoryActivity")),
	 *             @OA\Property(property="towns", type="array", @OA\Items(ref="#/components/schemas/Town")),
	 *             @OA\Property(property="organizations", type="array", @OA\Items(ref="#/components/schemas/Organization"))
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="error", type="string")
	 *         )
	 *     )
	 * )
	 */

	public function SearchDataAll(Request $request, Response $response): Response
	{
		try {
			$data = [
				'activities' => $this->getRowsAsArray("SELECT * from bb_activity where active=1"),
				'buildings' => $this->getRowsAsArray("SELECT id, activity_id, deactivate_calendar, deactivate_application,"
				. " deactivate_sendmessage, extra_kalendar, name, homepage, location_code, phone, email, tilsyn_name, tilsyn_phone,"
				. " tilsyn_email, tilsyn_name2, tilsyn_phone2, tilsyn_email2, street, zip_code, district, city, calendar_text, opening_hours"
				. " FROM bb_building WHERE active=1"),
				'building_resources' => $this->getRowsAsArray("SELECT * from bb_building_resource"),
				'facilities' => $this->getRowsAsArray("SELECT * from bb_facility where active=1"),
				'resources' => $this->getRowsAsArray("SELECT * from bb_resource where active=1 and hidden_in_frontend=0 and deactivate_calendar=0"),
				'resource_activities' => $this->getRowsAsArray("SELECT * from bb_resource_activity"),
				'resource_facilities' => $this->getRowsAsArray("SELECT * from bb_resource_facility"),
				'resource_categories' => $this->getRowsAsArray("SELECT * from bb_rescategory where active=1"),
				'resource_category_activity' => $this->getRowsAsArray("SELECT * from bb_rescategory_activity"),
				'towns' => $this->getRowsAsArray("SELECT DISTINCT bb_building.id as b_id, bb_building.name as b_name, fm_part_of_town.id, fm_part_of_town.name FROM"
					. " bb_building JOIN fm_locations ON bb_building.location_code = fm_locations.location_code"
					. " JOIN fm_location1 ON fm_locations.loc1 = fm_location1.loc1"
					. " JOIN fm_part_of_town ON fm_location1.part_of_town_id = fm_part_of_town.id"
					. " where bb_building.active=1"),
				'organizations' => $this->getRowsAsArray("SELECT id, organization_number, name, homepage, phone, email, co_address,"
				. " street, zip_code, district, city, activity_id, show_in_portal"
				. " FROM bb_organization WHERE active=1 AND show_in_portal=1"),
			];

			$response->getBody()->write(json_encode($data));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			// Handle database error (e.g., log the error, return an error response)
			$error = "Error fetching data: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	public function getRowsAsArray($sql)
	{
		$values = array();
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$values[] = $row;
		}
		return $values;
	}

}
