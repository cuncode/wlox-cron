<?php
class Ethereum {
	public $user,$pass,$host,$port;
	
	public function __construct($user,$pass,$host,$port,$etherscan_api_key=false) {
		$this->user = $user;
		$this->pass = $pass;
		$this->host = $host;
		$this->port = $port;
		$this->etherscan_api_key = $etherscan_api_key;
		$this->from_wei = 1000000000000000000;
	}
	
	public function newAccount() {
		$params = array(
			'jsonrpc'=>'2.0x',
			'method'=>'personal_newAccount',
			'params'=>array($this->pass),
			'id'=>time()
		);
		
		$result = $this->call($params);
		if ($result && $result['result'])
			return $result['result'];
		else if ($result && $result['error'])
			echo $result['error']['message'].PHP_EOL;

		return false;	
	}
	
	public function getGasPrice() {
		$params = array(
			'jsonrpc'=>'2.0x',
			'method'=>'eth_gasPrice',
			'params'=>array(),
			'id'=>time()
		);
		
		$result = $this->call($params);
		if ($result && $result['result'])
			return hexdec($result['result']);
		else if ($result && $result['error'])
			echo $result['error']['message'].PHP_EOL;
		
		return false;
	}
	
	public function unlock($address,$password) {
		$params = array(
			'jsonrpc'=>'2.0x',
			'method'=>'personal_unlockAccount',
			'params'=>array($address,$password,60),
			'id'=>time()
		);
		
		$result = $this->call($params);
		if ($result && $result['result'])
			return $result['result'];
		else if ($result && $result['error'])
			echo $result['error']['message'].PHP_EOL;
		
		return false;
	}
	
	public function sendTransactions($transactions,$gas_limit) {
		if (!is_array($transactions) || count($transactions) == 0)
			return false;
		
		$sent = 0;
		$errors = false;
		
		foreach ($transactions as $i => $transaction) {
			$this->unlock($transaction['from'],$this->pass);
			$gas_price = (bcmul($transaction['fee'],$this->from_wei) / $gas_limit);
			
			$params = array(
				'jsonrpc'=>'2.0x',
				'method'=>'eth_sendTransaction',
				'params'=>array(array(
					'from'=>$transaction['from'],
					'to'=>$transaction['to'],
					'gas'=>$this->encodeHex($gas_limit),
					'gasPrice'=>$this->encodeHex($gas_price),
					'value'=>$this->encodeHex(bcmul($transaction['amount'],$this->from_wei) - ($gas_price * $gas_limit))
				)),
				'id'=>time()
			);
			
			$result = $this->call($params);
			if ($result && $result['result']) {
				$transactions[$i]['txid'] = $result['result'];
				$transactions[$i]['fee'] = number_format(($gas_price * $gas_limit) / $this->from_wei,8,'.','');
				$sent += $transaction['amount'];
			}
			else if ($result && $result['error']) {
				echo $result['error']['message'].PHP_EOL;
				$errors[] = $result['error']['message'];
			}
		}
		
		return array('sent'=>$sent,'transactions'=>$transactions,'errors'=>$errors);
	}
	
	public function getTransactions($current_block,$addresses) {
		if (!$addresses)
			return false;
		
		$params = array(
			'jsonrpc'=>'2.0x',
			'method'=>'eth_getLogs',
			'params'=>array(
				array(
					'topics'=>array(),
					'fromBlock'=>$this->encodeHex($current_block - 14000),
					'toBlock'=>$this->encodeHex($current_block),
					'address'=>$addresses,
				)
			),
			'id'=>time()
		);
		
		$result = $this->call($params);
		if ($result && is_array($result['result'])) {
			$result['result']['amount'] = number_format($this->decodeHex($result['result']['value']) / $this->from_wei,8,'.','');
			return $result['result'];
		}
		else if ($result && $result['error'])
			echo $result['error']['message'].PHP_EOL;
			
		return false;
	}
	
	public function getTransactionsFromEtherscan($current_block,$address) {
		if (!$this->etherscan_api_key || !$address)
			return false;
		
		$params = array(
			'module'=>'account',
			'action'=>'txlist',
			'address'=>$address,
			'startBlock'=>$current_block - 14000,
			'sort'=>'asc',
			'api_key'=>$this->etherscan_api_key
		);
		
		$return = array();
		$result = $this->callEtherscan($params);
		if (!empty($result['result'])) {
			foreach ($result['result'] as $t) {
				$t['amount'] = number_format($t['value'] / $this->from_wei,8,'.','');
				$t['txid'] = $t['hash'];
				$return[] = $t;
			}
		}
		
		return $return;
	}
	
	public function getTransaction($hash,$current_block) {
		$params = array(
			'jsonrpc'=>'2.0x',
			'method'=>'eth_getTransactionByHash',
			'params'=>array($hash),
			'id'=>time()
		);
		
		$result = $this->call($params);
		if ($result && $result['result']) {
			if ($current_block > 0) {
				$b = hexdec($result['result']['blockNumber']);
				$result['result']['confirmations'] = $b - $current_block;
			}
			return $result['result'];
		}
		else if ($result && $result['error'])
			echo $result['error']['message'].PHP_EOL;
			
		return false;	
	}
	
	public function getBlockNum() {
		$params = array(
			'jsonrpc'=>'2.0x',
			'method'=>'eth_blockNumber',
			'params'=>array(),
			'id'=>time()
		);
		
		$result = $this->call($params);
		if ($result && $result['result'])
			return hexdec($result['result']);
		else if ($result && $result['error'])
			echo $result['error']['message'].PHP_EOL;
			
		return false;
	}
	
	public function getBalance($address) {
		if (!$address)
			return false;
		
		$params = array(
			'jsonrpc'=>'2.0x',
			'method'=>'eth_getBalance',
			'params'=>array($address,'latest'),
			'id'=>time()
		);
		
		$result = $this->call($params);
		if ($result && $result['result'])
			return number_format(hexdec($result['result']) / $this->from_wei,8,'.','');
		else if ($result && $result['error'])
			echo $result['error']['message'].PHP_EOL;
			
		return false;
	}
	
	public function getAddressesWithBalance() {
		$input = 'function getBalances() { var wb = []; for (i in web3.eth.accounts) { var b = web3.fromWei(web3.eth.getBalance(web3.eth.accounts[i])); if (b > 0) wb.push(web3.eth.accounts[i]); } return wb; } getBalances();';
		return $this->callGeth($input);
	}
	
	public function call($params) {
		$ch = curl_init();
			
		curl_setopt($ch,CURLOPT_URL,$this->host.':'.$this->port);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch,CURLOPT_POSTFIELDS,str_replace('2.0x','2.0',json_encode($params)));
		curl_setopt($ch,CURLOPT_POST,1);
		
		$headers = array();
		$headers[] = "Content-Type: application/x-www-form-urlencoded";
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		
		if (!empty($params['simulate'])) {
			print_r(str_replace('2.0x','2.0',json_encode($params)));
			return false;
		}
		
		$result = json_decode(curl_exec($ch),true);
		curl_close($ch);
		
		return $result;
	}
	
	public function callGeth($input) {
		$exec = 'geth --exec "'.$input.'" attach http://'.$this->host.':'.$this->port;
		return json_decode(exec($exec),true);
	}
	
	public function callEtherscan($params) {
		$ch = curl_init();
			
		curl_setopt($ch,CURLOPT_URL,'http://api.etherscan.io/api?'.http_build_query($params));
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		
		$result = json_decode(curl_exec($ch),true);
		curl_close($ch);
		
		return $result;
	}
	
	private function decodeHex($input) {
		if(substr($input,0,2) == '0x')
			$input = substr($input,2);
		
		if(preg_match('/[a-f0-9]+/', $input))
			return hexdec($input);
			
		return $input;
	}

	private function encodeHex($input) {
		$number = (string) dechex($input);
		return '0x'.$number;
	}
}
