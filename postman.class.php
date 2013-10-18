<?php
namespace ay\postman;

use PDO;

class Postman {
	const API_KEY = 'AIzaSyAbtbHiGSLHx5Pvbehg08r0OPzMG411Afc';

	private
		$db,
		$redis;

	public function __construct (\ay\pdo\PDO $db, \Redis $redis = null) {
		$this->db = $db;
		$this->redis = $redis;
	}
	
	/**
	 * @author http://www.braemoor.co.uk/software/postcodes.shtml
	 */
	public function isValid ($postcode) {
		$alpha1 = '[abcdefghijklmnoprstuwyz]';
		$alpha2 = '[abcdefghklmnopqrstuvwxy]';
		$alpha3 = '[abcdefghjkpmnrstuvwxy]';
		$alpha4 = '[abehmnprvwxy]';
		$alpha5 = '[abdefghjlnpqrstuwxyz]';
		$BFPOa5 = '[abdefghjlnpqrst]{1}';
		$BFPOa6 = '[abdefghjlnpqrstuwzyz]{1}';
		
		$pcexp = [];
		
		// Expression for BF1 type postcodes 
		$pcexp[0] = '/^(bf1)([[:space:]]{0,})([0-9]{1}' . $BFPOa5 . $BFPOa6 .')$/';
		
		// Expression for postcodes: AN NAA, ANN NAA, AAN NAA, and AANN NAA with a space
		$pcexp[1] = '/^('.$alpha1.'{1}'.$alpha2.'{0,1}[0-9]{1,2})([[:space:]]{0,})([0-9]{1}'.$alpha5.'{2})$/';
		
		// Expression for postcodes: ANA NAA
		$pcexp[2] = '/^('.$alpha1.'{1}[0-9]{1}'.$alpha3.'{1})([[:space:]]{0,})([0-9]{1}'.$alpha5.'{2})$/';
		
		// Expression for postcodes: AANA NAA
		$pcexp[3] = '/^('.$alpha1.'{1}'.$alpha2.'{1}[0-9]{1}'.$alpha4.')([[:space:]]{0,})([0-9]{1}'.$alpha5.'{2})$/';
		
		// Exception for the special postcode GIR 0AA
		$pcexp[4] = '/^(gir)([[:space:]]{0,})(0aa)$/';
		
		// Standard BFPO numbers
		$pcexp[5] = '/^(bfpo)([[:space:]]{0,})([0-9]{1,4})$/';
		
		// c/o BFPO numbers
		$pcexp[6] = '/^(bfpo)([[:space:]]{0,})(c\/o([[:space:]]{0,})[0-9]{1,3})$/';
		
		// Overseas Territories
		$pcexp[7] = '/^([a-z]{4})([[:space:]]{0,})(1zz)$/';
		
		$postcode = strtolower($postcode);
		
		foreach ($pcexp as $regexp) {
		
			if (preg_match($regexp, $postcode, $matches)) {
		
				$postcode = strtoupper ($matches[1] . ' ' . $matches [3]);
		
				// Take account of the special BFPO c/o format
				$postcode = preg_replace ('/C\/O([[:space:]]{0,})/', 'c/o ', $postcode);
		
				return $postcode;
			}
		}
		
		return false;
	}
	
	public function getLatLng ($postcode) {
		$postcode = $this->isValid($postcode);
		
		if (!$postcode) {
			throw new PostcodeException('Invalid postcode supplied to getLatLng method.');
		}
		
		$postcode = $this->db
			->prepare("SELECT X(`location`) `long`, Y(`location`) `lat` FROM `postcode`.`postcode` WHERE `postcode` = s:postcode;")
			->execute(['postcode' => $postcode])
			->fetch(PDO::FETCH_ASSOC);
		
		return $postcode;
	}
	
	public function distance ($postcode1, $postcode2, $unit = 'm') {
		$location1 = $this->getLatLng($postcode1);
		$location2 = $this->getLatLng($postcode2);
		
		if (!$location1 || !$location2) {
			return;
		}
		
		return $this->distanceGeoPoints($location1['long'], $location1['lat'], $location2['long'], $location2['lat'], $unit);
	}
	
	/**
	 * @url http://stackoverflow.com/questions/27928/how-do-i-calculate-distance-between-two-latitude-longitude-points
	 */
	private function distanceGeoPoints ($long1, $lat1, $long2, $lat2, $unit = 'm') {
		$lat = deg2rad($lat2 - $lat1);
		$long = deg2rad($long2 - $long1);
		$a = sin($lat/2) * sin($lat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($long/2) * sin($long/2);
		$c = 2 * atan2(sqrt($a), sqrt(1 - $a));
		$d = 6371 * $c;
		
		if ($unit === 'm') {
			$d *= 1.609344;
		} else if ($unit !== 'km') {
			throw new PostcodeException('Unknown unit.');
		}
		
		return $d;
    }
}

class PostcodeException extends \Exception {}