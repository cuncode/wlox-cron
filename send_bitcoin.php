#!/usr/bin/php
<?php
include 'common.php';
echo date('Y-m-d H:i:s').' Beginning Crypto Withdrawals processing...'.PHP_EOL;
ini_set('display_errors',1);

$cryptos = Currencies::getCryptos();
$sql = "SELECT 
		r.currency, 
		r.site_user, 
		r.amount, 
		r.send_address, 
		r.fee, 
		r.net_amount, 
		r.id, 
		sub.balance, 
		sub.id AS balance_id 
		FROM requests r
		LEFT JOIN site_users su ON (r.site_user = su.id) 
		LEFT JOIN site_users_balances sub ON (sub.site_user = r.site_user AND sub.currency = r.currency) 
		WHERE r.request_status = {$CFG->request_pending_id} 
		AND r.currency IN (".implode(',',$cryptos).") 
		AND r.request_type = {$CFG->request_withdrawal_id}".(($CFG->withdrawals_btc_manual_approval == 'Y') ? " 
		AND (r.done = 'Y' OR su.trusted = 'Y')" : '')."
		ORDER BY r.id ASC";
$result = db_query_array($sql);

if (!$result) {
	echo 'done'.PHP_EOL;
	exit;
}

$wallets = Wallets::get();
if (!$wallets) {
	echo 'Error: no wallets to process.'.PHP_EOL;
	exit;
}

foreach ($wallets as $wallet) {
	$c_currency_info = $CFG->currencies[$wallet['c_currency']];
	$is_bitcoin = ($c_currency_info['currency'] != 'ETH');
	$is_ether = ($c_currency_info['currency'] == 'ETH');
	
	if ($is_bitcoin) {
		$bitcoin = new Bitcoin($wallet['bitcoin_username'],$wallet['bitcoin_passphrase'],'localhost',$wallet['bitcoin_port'],$wallet['bitcoin_protocol']);
	}
	else if ($is_ether){
		$ethereum = new Ethereum($wallet['bitcoin_username'],$wallet['bitcoin_passphrase'],'localhost',$wallet['bitcoin_port']);
	}
	
	$available = $wallet['hot_wallet_btc'];
	$deficit = $wallet['deficit_btc'];
	$users = array();
	$transactions = array();
	$user_balances = array();
	$addresses = array();
	$fees_collected = 0;
	
	if ($result) {
		$pending = 0;
		
		foreach ($result as $row) {
			if ($row['currency'] != $wallet['c_currency'])
				continue;
			
			$fee = $row['fee'];
			$operator_fee = 0;
			
			// check if user sending to himself
			$addr_info = BitcoinAddresses::getAddress($row['send_address'],$wallet['c_currency']);
			if (!empty($addr_info['site_user']) && $addr_info['site_user'] == $row['site_user']) {
				db_update('requests',$row['id'],array('request_status'=>$CFG->request_completed_id));
				continue;
			}
			
			// check if sending to another wlox user
			if (!empty($addr_info['site_user'])) {
				if (empty($user_balances[$addr_info['site_user']])) {
					$bal_info = User::getBalance($addr_info['site_user'],$wallet['c_currency'],true);
					$user_balances[$addr_info['site_user']] = $bal_info['balance'];
				}
				
				$deposit_fee = ($CFG->crypto_deposit_fee * 0.01 * $row['amount']) + $CFG->fiat_deposit_fee_unit;	
				
				User::updateBalances($row['site_user'],array($wallet['c_currency']=>(-1 * ($row['amount'] + $fee + $operator_fee))),true);
				User::updateBalances($addr_info['site_user'],array($wallet['c_currency']=>($row['amount'] - $deposit_fee)),true);
				Status::updateEscrows(array($wallet['c_currency']=>($fee + $deposit_fee)));
				db_update('requests',$row['id'],array('request_status'=>$CFG->request_completed_id));
				
				$rid = db_insert('requests',array('date'=>date('Y-m-d H:i:s'),'site_user'=>$addr_info['site_user'],'currency'=>$wallet['c_currency'],'amount'=>$row['amount'],'net_amount'=>($row['amount'] - $deposit_fee),'fee'=>$fee,'description'=>$CFG->deposit_bitcoin_desc,'request_status'=>$CFG->request_completed_id,'request_type'=>$CFG->request_deposit_id));
				if ($rid)
					db_insert('history',array('date'=>date('Y-m-d H:i:s'),'history_action'=>$CFG->history_deposit_id,'site_user'=>$addr_info['site_user'],'request_id'=>$rid,'balance_before'=>$user_balances[$addr_info['site_user']],'balance_after'=>($user_balances[$addr_info['site_user']] + $row['amount'] - $deposit_fee),'bitcoin_address'=>$row['send_address']));
				
				$user_balances[$addr_info['site_user']] = $user_balances[$addr_info['site_user']] + $row['amount'];
				continue;
			}

			if (!($row['net_amount'] > 0) || $row['net_amount'] > $available)
				continue;
			
			// check if hot wallet has enough to send
			$pending += $row['amount'];
			$transactions[$row['send_address']] = array(
				'amount' => ((!empty($transactions[$row['send_address']])) ? bcadd($row['net_amount'],(float)$transactions[$row['send_address']]['amount'],8) : $row['net_amount']),
				'blockchain_fee' => $wallet['bitcoin_sending_fee']
			);
			
			$users[$row['site_user']] = (!empty($users[$row['site_user']])) ? bcadd($row['amount'],$users[$row['site_user']],8) : $row['amount'];
			$users_net[$row['site_user']] = (!empty($users_net[$row['site_user']])) ? bcadd($row['net_amount'],$users_net[$row['site_user']],8) : $row['net_amount'];
			$users_fees[$row['site_user']] = (!empty($users_fees[$row['site_user']])) ? bcadd($fee + $operator_fee,$users_fees[$row['site_user']],8) : $fee + $operator_fee;
			$users_addresses[$row['send_address']] = $row['site_user'];
			$requests[$row['id']] = $row['send_address'];
			$available = bcsub($available,$row['net_amount'],8);
		}
	
		if ($pending > $available) {
			db_update('wallets',$wallet['id'],array('deficit_btc'=>($pending - $available),'pending_withdrawals'=>$pending));
			echo $CFG->currencies[$wallet['c_currency']]['currency'].' Deficit: '.($pending - $available).PHP_EOL;
		}
	}
	
	$errors = false;

	if (!empty($transactions)) {
		if ($is_bitcoin) {
			$bitcoin->walletpassphrase($wallet['bitcoin_passphrase'],3);
			
			foreach ($transactions as $address => $info) {
				if ($c_currency_info['currency'] == 'DASH')
					$bitcoin->settxfee((float)$info['blockchain_fee']);
				else
					$bitcoin->settxfee(round(($info['blockchain_fee'] / 226) * 1000,8));
				
				$response = $bitcoin->sendtoaddress($address,(float)$info['amount']);
				
				if (!empty($bitcoin->error)) {
					$errors[] = $bitcoin->error;
					$response = false;
					echo $bitcoin->error.PHP_EOL;
				}
			}
		}
		else if ($is_ether) {
			$hot_wallets = BitcoinAddresses::getHotWallets($wallet['c_currency']);
			$to_send = array();
			
			foreach ($transactions as $address => $info) {
				foreach ($hot_wallets as $hot_wallet) {
					if ($info['amount'] > 0 && $hot_wallet['balance'] > 0) {
						$send_amount = min($info['amount'],$hot_wallet['balance']);
						$info['amount'] -= $send_amount;
						
						$to_send[] = array('to'=>$address,'from'=>$hot_wallet['address'],'amount'=>$send_amount,'fee'=>$info['blockchain_fee']);
					}
				}
			}
			
			$sent = $ethereum->sendTransactions($to_send,$wallet['gas_limit']);
			$response = ($sent['sent'] > 0);
			
			if ($sent['errors'])
				$errors = $sent['errors'];
		}
	}

	if ($errors) {
		echo 'Errors ocurred while sending:'.PHP_EOL;
		echo print_r($errors,1).PHP_EOL;
	}
	
	if (!empty($response) && $users) {
		echo $CFG->currencies[$wallet['c_currency']]['currency'].' Transactions sent. '.PHP_EOL;
		
		$total = 0;
		
		if ($is_bitcoin) {
			$transaction = $bitcoin->gettransaction($response);
			//$actual_fee_difference = $fees_charged - abs($transaction['fee']);
			
			foreach ($users as $site_user => $amount) {
				$total += $users[$site_user];
				$fees_collected += $users_fees[$site_user];
				
				User::updateBalances($site_user,array($wallet['c_currency']=>(-1 * $amount)),true);
			}
			
			foreach ($requests as $request_id => $address) {
				db_update('requests',$request_id,array('request_status'=>$CFG->request_completed_id));
			}
		}
		else if ($is_ether) {
			//$actual_fee_difference = 0;
			$sent_addresses = array();
			
			foreach ($sent['transactions'] as $detail) {
				if (empty($detail['txid']) || empty($users_addresses[$detail['to']]))
					continue;
				
				$site_user = $users_addresses[$detail['to']];
				$gross_amount = $users[$site_user];
				$fees_collected += $users_fees[$site_user];
				$total += $detail['amount'];
				$sent_addresses[] = $detail['to'];
				
				User::updateBalances($users_addresses[$detail['to']],array($wallet['c_currency']=>(-1 * $gross_amount)),true);
			}
			
			foreach ($requests as $request_id => $address) {
				if (!in_array($address,$sent_addresses))
					continue;
				
				db_update('requests',$request_id,array('request_status'=>$CFG->request_completed_id));
			}
		}
		
		if ($total > 0) {
			Wallets::sumFields($wallet['id'],array('hot_wallet_btc'=>(0 - $total),'total_btc'=>(0 - $total)));
			//Status::updateEscrows(array($wallet['c_currency']=>($fees_collected + $actual_fee_difference)));
			db_update('wallets',$wallet['id'],array('pending_withdrawals'=>($pending - $total)));
		}
	}
	
	if (empty($pending)) db_update('wallets',$wallet['id'],array('deficit_btc'=>'0'));
	
	// FALLBACK FOR STUCK BITCOIN TRANSACTIONS
	if ($c_currency_info['currency'] == 'BTC') {
		$stuck = $bitcoin->listtransactions('*',300);
		if (is_array($stuck) && count($stuck) > 0) foreach ($stuck as $t) {
			if ($t['trusted'] || $t['category'] != 'send')
				continue;
			
			$tt = $bitcoin->gettransaction($t['txid']);
			$bitcoin->sendrawtransaction($tt['hex']);
		} 
	}
}

db_update('status',1,array('cron_send_bitcoin'=>date('Y-m-d H:i:s')));

echo 'done'.PHP_EOL;
