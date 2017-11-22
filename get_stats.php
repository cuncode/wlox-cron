#!/usr/bin/php
<?php
echo "Beginning Get Global Market Stats processing...".PHP_EOL;

include 'common.php';

$main = Currencies::getMain();
$wallets = Wallets::get();
if (!$wallets)
	exit;

// GET FEE LEVELS
$ch = curl_init();
foreach ($wallets as $wallet) {
	$c_currency_info = $CFG->currencies[$wallet['c_currency']];
	$is_bitcoin = ($c_currency_info['currency'] != 'ETH');
	$is_ether = ($c_currency_info['currency'] == 'ETH');
	$wait = 10;
	$wait1 = 30;
	$wait2 = 60;
	
	if ($is_bitcoin) {
		$bitcoin = new Bitcoin($wallet['bitcoin_username'],$wallet['bitcoin_passphrase'],'localhost',$wallet['bitcoin_port'],$wallet['bitcoin_protocol']);
		$latest_id = $bitcoin->getblockcount();
		$latest_hash = $bitcoin->getblockhash($latest_id);
		$latest_t = $bitcoin->getblock($latest_hash);
		$first_hash = $bitcoin->getblockhash($latest_id - 20);
		$first_t = $bitcoin->getblock($first_hash);
		$time_per_block = ceil(($latest_t['time'] - $first_t['time']) / 20);
		$half_hour = $bitcoin->estimatefee(floor(1800/$time_per_block));
		
		if ($half_hour == -1 && $c_currency_info['currency'] == 'BTC') {
			$data = API::getJson('https://bitcoinfees.21.co/api/v1/fees/recommended');
			$half_hour = $data['halfHourFee'] * 0.00000001 * 226;
		}
	}
	else if ($is_ether){
		$data = API::getJson('http://ethgasstation.info/json/ethgasAPI.json');
		$from_wei = 1000000000;
		$half_hour = number_format(($data['average'] * $wallet['gas_limit']) / $from_wei,8,'.','');
	}	
	
	db_update('wallets',$wallet['id'],array('bitcoin_sending_fee'=>$half_hour));
}

// GET CRYPTO GLOBAL STATS
$data = API::getJson('https://api.coinmarketcap.com/v1/ticker/');

foreach ($wallets as $wallet) {
	$market_data = array();
	foreach ($data as $market) {
		if ($market['symbol'] == $CFG->currencies[$wallet['c_currency']]['currency']) {
			$market_data = $market;
			break;
		}
	}
	
	if (count($market_data) == 0)
		continue;

	$update_data = array(
		'global_btc' => $market_data['available_supply'],
		'market_cap' => ($market_data['market_cap_usd'] / $CFG->currencies[$main['fiat']]['usd_ask']),
		'trade_volume' => ($market_data['24h_volume_usd'] / $CFG->currencies[$main['fiat']]['usd_ask'])
	);
	
	db_update('wallets',$wallet['id'],$update_data);
}

// GET FIAT EXCHANGE RATES
if ($CFG->currencies) {
	foreach ($CFG->currencies as $currency) {
		if ($currency['is_crypto'] == 'Y' || $currency == 'USD')
			continue;
		
		$currencies[] = $currency['currency'].'USD';
	}
	
	$currency_string = urlencode(implode(',',$currencies));
	$data = API::getJson('http://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20yahoo.finance.xchange%20where%20pair%3D%22'.$currency_string.'%22&format=json&diagnostics=true&env=store%3A%2F%2Fdatatables.org%2Falltableswithkeys');
	
	if ($data['query']['results']['rate']) {
		$bid_str = '(CASE currency ';
		$ask_str = '(CASE currency ';
		$currency_ids = array();
		$last = false;
		
		foreach ($data['query']['results']['rate'] as $row) {
			$key = str_replace('USD','',$row['id']);
			if ($key == $last)
				continue;
			
			$ask = $row['Ask'];
			$bid = $row['Bid'];
			
			if (strlen($key) < 3 || strstr($key,'='))
				continue;
			
			if ($bid == $CFG->currencies[$key]['usd_bid'] || $ask == $CFG->currencies[$key]['usd_ask'])
				continue;
			
			$bid_str .= ' WHEN "'.$key.'" THEN '.$bid.' ';
			$ask_str .= ' WHEN "'.$key.'" THEN '.$ask.' ';
			$currency_ids[] = $CFG->currencies[$key]['id'];
			$last = $key;
		}
		
		$bid_str .= ' END)';
		$ask_str .= ' END)';
		
		if (count($currency_ids) > 0) {
			$sql = 'UPDATE currencies SET usd_bid = '.$bid_str.', usd_ask = '.$ask_str.' WHERE id IN ('.implode(',',$currency_ids).')';
			$result = db_query($sql);
		}
	}
}
db_update('status',1,array('cron_get_stats'=>date('Y-m-d H:i:s')));
echo 'done'.PHP_EOL;

