<?php

class test
{
	function jwkToPem($jwk)
	{
		// Convert base64url to base64
		$modulus = strtr($jwk['n'], '-_', '+/');
		$exponent = strtr($jwk['e'], '-_', '+/');

		// Decode base64
		$modulus = base64_decode($modulus . str_repeat('=', 3 - (3 + strlen($modulus)) % 4));
		$exponent = base64_decode($exponent . str_repeat('=', 3 - (3 + strlen($exponent)) % 4));

		// Convert the modulus and exponent to their binary representations
		$modulus = "\x00" . $modulus; // Add leading zero to ensure it's interpreted as a positive number
		$exponent = "\x00" . $exponent; // Add leading zero to ensure it's interpreted as a positive number

		// Construct the ASN.1 structure for the RSA public key
		$modulus = "\x02" . $this->encodeLength(strlen($modulus)) . $modulus;
		$exponent = "\x02" . $this->encodeLength(strlen($exponent)) . $exponent;
		$rsaPublicKey = "\x30" . $this->encodeLength(strlen($modulus) + strlen($exponent)) . $modulus . $exponent;
		$rsaPublicKey = "\x30" . $this->encodeLength(strlen($rsaPublicKey) + 2) . "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00\x03" . $this->encodeLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

		// Encode the ASN.1 structure in base64 and wrap it in the PEM format
		$pem = "-----BEGIN PUBLIC KEY-----\n" .
			chunk_split(base64_encode($rsaPublicKey), 64, "\n") .
			"-----END PUBLIC KEY-----\n";

		return $pem;
	}


	function encodeLength($length)
	{
		if ($length <= 0x7F)
		{
			return chr($length);
		}
		else
		{
			$len = ltrim(pack('N', $length), "\x00");
			return chr(0x80 | strlen($len)) . $len;
		}
	}
}
$jwk = [
	"kty" => "RSA",
	"n" => "0YsOoA0v5HD_XzOwLHfJcGWN6-vVdAoJtaTPl9QkKk9M2KQVAxzPS5TKFsbBXftg4KmoaOAPKRtz8xphsqXLsUeauDSaP5jEgBO24pvlQG4Rlea6ZtxDsNK8va0RMAU8IsL1CuqJN73BwBjYwZl9j8QB06decCxeRVF-BeFKfi0cVM_ZO_v17TXGZjXziGxJlx6xhH96s9p0sYD5-tCOQRJaoRZH2JBm3mhYEFomIRTKmjvrzQLgzShO71PL4SnFj79Ye6LoWzfjhG3urnpspFZ3ds2oO1oHCGaJ4d5RP2sDx04ucfntDgZGmO5qqNUZhWxPQZ4aWlvbbMsroSJuvQ",
	"e" => "AQAB"
];

$test = new test();
	
$pem = $test->jwkToPem($jwk);
echo $pem;
