<?php
class API{
	public static function getJson($url) {
		$ch = curl_init();
		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);
		curl_setopt($ch,CURLOPT_TIMEOUT,10);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
		$data1 = curl_exec($ch);
		
		if (curl_exec($ch) === false)
			trigger_error(curl_error($ch));
		
		curl_close($ch);
		$data = json_decode($data1,true);
		
		return $data;
	}
}
	