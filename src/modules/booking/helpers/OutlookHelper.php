<?php

namespace App\modules\booking\helpers;

use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\ConfigLocation;
use App\modules\phpgwapi\services\Cache;

class OutlookHelper
{
	public $public_functions = array(
		'get_rooms' => true,
	);

	private $baseurl;

	public function __construct()
	{
		$location_obj = new Locations();
		$location_id = $location_obj->get_id('booking', 'run');
		$custom_config_data = (new ConfigLocation($location_id))->read();

		if (!empty($custom_config_data['Outlook']['baseurl']))
		{
			$this->baseurl = rtrim($custom_config_data['Outlook']['baseurl'], '/');
		}
	}


	public function get_outlook_resources($query = '')
	{
		if (empty($this->baseurl))
		{
			return false; // Base URL is not set, return false or handle error as needed
		}

		$url = "{$this->baseurl}/bridges/outlook/available-resources";
		if (!empty($query))
		{
			$url .= '?query=' . urlencode($query);
		}
		//use the cURL library to fetch data (json) from the URL
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		// Disable proxy for internal Docker communication
		curl_setopt($ch, CURLOPT_PROXY, '');
		curl_setopt($ch, CURLOPT_NOPROXY, 'portico_outlook,localhost,127.0.0.1');


		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Accept: application/json'
		));
		$response = curl_exec($ch);

		if (curl_errno($ch))
		{
			// Handle error
			return array(
				'error' => 'Curl error: ' . curl_error($ch)
			);
		}
		curl_close($ch);
		// Decode the JSON response
		$result = json_decode($response, true);
		// Transform members data to rooms format if needed
		$result['recordsFiltered'] = $result['resources'] ? count($result['resources']) : 0;
		$result['recordsTotal'] = isset($result['count']) ? $result['count'] : 0;
		$result['data'] = isset($result['resources']) ? $result['resources'] : array();
		unset($result['resources']);

		return $result;
	}

	public function add_resource_mapping($resource_id, $outlook_item_name, $outlook_item_id)
	{
		if (empty($this->baseurl))
		{
			return false; // Base URL is not set, return false or handle error as needed
		}

		$url = "{$this->baseurl}/mappings/resources";
		$data = array(
			'bridge_from' => 'booking_system',
			'bridge_to' => 'bridge_to',
			'source_calendar_id' => $resource_id,
			'source_calendar_name' => $resource_id,
			'target_calendar_id' => $outlook_item_id,
			'target_calendar_name' => $outlook_item_name,
		);

		//use the cURL library to post data (json) to the URL
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		// Disable proxy for internal Docker communication
		curl_setopt($ch, CURLOPT_PROXY, '');
		curl_setopt($ch, CURLOPT_NOPROXY, 'portico_outlook,localhost,127.0.0.1');
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json'
		));
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		$response = curl_exec($ch);

		if (curl_errno($ch))
		{
			// Handle error
			curl_close($ch);
			return array(
				'status' => 'error',
				'msg' => 'Curl error: ' . curl_error($ch)
			);
		}

		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// Decode the JSON response
		$result = json_decode($response, true);

		if ($http_code >= 200 && $http_code < 300)
		{
			return array(
				'status' => 'success',
				'msg' => 'Resource mapping added successfully',
				'data' => $result
			);
		}
		else
		{
			return array(
				'status' => 'error',
				'msg' => 'Failed to add resource mapping. HTTP code: ' . $http_code,
				'data' => $result
			);
		}
	}


	public function get_resource_mapping($resource_id)
	{
		if (empty($this->baseurl))
		{
			return []; // Base URL is not set, return empty array or handle error as needed
		}

		$url = "{$this->baseurl}/mappings/resources/by-resource/{$resource_id}";
		//use the cURL library to fetch data (json) from the URL
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		// Disable proxy for internal Docker communication
		curl_setopt($ch, CURLOPT_PROXY, '');
		curl_setopt($ch, CURLOPT_NOPROXY, 'portico_outlook,localhost,127.0.0.1');
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Accept: application/json'
		));
		$response = curl_exec($ch);
		if (curl_errno($ch))
		{
			// Handle error
			Cache::message_set('Error fetching Outlook resources: ' . curl_error($ch), 'error');
			return [];
		}
		curl_close($ch);
		// Decode the JSON response
		$result = json_decode($response, true);
		if (isset($result['error']))
		{
			return array(
				'error' => $result['error']
			);
		}
		return $this->format_resource_mapping($result, $resource_id);
	}

	public function format_resource_mapping($data, $resource_id)
	{
		$values = [];
		if (!empty($data['mappings']) && is_array($data['mappings']))
		{
			foreach ($data['mappings'] as $mapping)
			{
				$values[] = array(
					'outlook_item_name' => $mapping['calendar_name'],
					'outlook_item_id' => $mapping['calendar_id'],
				);
			}
		}
		return $values;
	}

	public function delete_resource_mapping($resource_id, $outlook_item_id)
	{
		if (empty($this->baseurl))
		{
			return false; // Base URL is not set, return false or handle error as needed
		}
		$url = "{$this->baseurl}/mappings/resources/booking_system/{$resource_id}/{$outlook_item_id}";
		//use the cURL library to delete data (json) from the URL
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
		// Disable proxy for internal Docker communication
		curl_setopt($ch, CURLOPT_PROXY, '');
		curl_setopt($ch, CURLOPT_NOPROXY, 'portico_outlook,localhost,127.0.0.1');
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json'
		));
		$response = curl_exec($ch);
		if (curl_errno($ch))
		{
			// Handle error
			return array(
				'error' => 'Curl error: ' . curl_error($ch)
			);
		}
		curl_close($ch);
		// Decode the JSON response
		$result = json_decode($response, true);
		if (isset($result['error']))
		{
			return array(
				'error' => $result['error']
			);
		}
		return array(
			'status' => 'success',
			'message' => 'Resource mapping deleted successfully.',
			'resource_id' => $resource_id
		);
	}
}
