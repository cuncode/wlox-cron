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
	
	private function request($url,$params=false,$use_get=false) {
		$headers = array(
			'Content-Type: application/json',
			'key: '.$this->api_key,
			'message: '.$this->message,
			'signature: '.$this->signature,
			'nonce: '.$this->nonce
		);
		
		$json = false;
		$query = false;
		
		if (!$use_get)
			$json = is_array($params) ? json_encode($params) : false;
		else
			$query = '?'.http_build_query($params);
		
		$ch = curl_init('https://api.cryptocapital.co/v4/'.$url.($query));
		curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 

		if (!$use_get)
			curl_setopt($ch,CURLOPT_POSTFIELDS,$json);
		
		$r = curl_exec($ch);
		$data = json_decode($r,true);
		
		if (!is_array($data)) {
			print_r(curl_error($ch));
			curl_close($ch);
			return false;
		}
		else if (is_array($data) && !empty($data['statusCode'])) {
			print_r('Error @/'.$url.': '.$data['message']);
			curl_close($ch);
			return false;
		}
		
		curl_close($ch);
		
		return $data;
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
		
		return $res;
	}
	
	public function statement($params) {
		$this->message = 'STATEMENT'.$this->nonce().$params['accountNumber'];
		$this->signature();
		
		$res = $this->request('statement/'.$params['accountNumber'],$params,true);
		if (is_array($res) && !empty($res['statusCode']) && $res['statusCode'] != 0) {
			print_r($res['data']);
			return false;
		}
		
		return $res;
	}
}
