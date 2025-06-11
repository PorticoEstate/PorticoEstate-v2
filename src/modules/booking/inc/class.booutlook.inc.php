<?php


class booking_booutlook
{
	public $public_functions = array(
		'get_rooms' => true,
	);

	public function __construct()
	{
	}


	public function get_rooms()
	{

		$rooms = array();
		$url = "http://portico_outlook/outlook/available-rooms";
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

		if (curl_errno($ch)) {
			// Handle error
			return array(
				'error' => 'Curl error: ' . curl_error($ch)
			);
		}
		curl_close($ch);
		// Decode the JSON response
		$result = json_decode($response, true);
		// Transform members data to rooms format if needed
		$result['recordsFiltered'] = $result['data'] ? count($result['data']) : 0;
		$result['recordsTotal'] = isset($result['recordsTotal']) ? $result['recordsTotal'] : 0;

		return $result;
	}

}