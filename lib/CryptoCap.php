<?php
Class CryptoCap {
	public $api_key,$api_secret,$message,$signature,$nonce;
	
	public function __construct($api_key,$api_secret) {
		$this->api_key = $api_key;
		$this->api_secret = $api_secret;
	}
	
	private function nonce() {
		$this->nonce = round(microtime(true) * 1000);
		return $this->nonce;
	}
	
	private function signature() {
		$this->signature = sha1($this->message.$this->api_key.$this->api_secret);
		return $this->signature;
	}
	
	private function request($url,$params=false) {
		$headers = array(
			'Content-Type: application/json',
			'key: '.$this->api_key,
			'message: '.$this->message,
			'signature: '.$this->signature,
			'nonce: '.$this->nonce
		);
		
		$json = is_array($params) ? json_encode($params) : false;
		
		$ch = curl_init('https://api.cryptocapital.co/v4/'.$url);
		curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
		curl_setopt($ch,CURLOPT_POSTFIELDS,$json);
		$data = curl_exec($ch);
		curl_close($ch);
		
		if ($data === false) {
			print_r(curl_error($ch));
			return false;
		}
		
		return json_decode($data,true);
	}
	
	public function ping() {
		$this->message = 'PING'.$this->nonce();
		$this->signature();
		
		return $this->request('ping');
	}
	
	public function transfer($params) {
		$this->message = 'TRANSFER'.$this->nonce().$params['fromAccount'].$params['toAccount'].$params['currency'].$params['amount'];
		$this->signature();
		
		$res = $this->request('transfer',$params);
		if (is_array($res) && $res['statusCode'] != 0) {
			print_r($res['data']);
			return false;
		}
		
		return true;
	}
	
	public function statement($params) {
		$this->message = 'STATEMENT'.$this->nonce().$params['accountNumber'];
		$this->signature();
		
		$res = $this->request('statement/'.$params['accountNumber'],$params);
		if (is_array($res) && $res['statusCode'] != 0) {
			print_r($res['data']);
			return false;
		}
		
		return true;
	}
}
