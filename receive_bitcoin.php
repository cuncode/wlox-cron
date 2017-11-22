#!/usr/bin/php
<?php
include 'common.php';
echo date('Y-m-d H:i:s').' Beginning Crypto Deposits processing...'.PHP_EOL;
ini_set('display_errors',1);

$CFG->session_active = true;
$transactions_dir = $CFG->dirroot.'transactions/';

$transactions = scandir($transactions_dir);
$transactions = (!$transactions) ? array() : $transactions;

$wallets = Wallets::get();
if (!$wallets) {
	echo 'Error: no wallets to process.'.PHP_EOL;
	exit;
}

$sql = "SELECT id, transaction_id FROM bitcoind_log WHERE date >= '".date('Y-m-d H:i:s',strtotime('-2 day'))."' ORDER BY id DESC ";
$result = db_query_array($sql);
if ($result) {
	foreach ($result as $row) {
		$transaction_log[$row['transaction_id']] = $row['id'];
	}
}

foreach ($wallets as $wallet) {
	$c_currency_info = $CFG->currencies[$wallet['c_currency']];
	$is_bitcoin = ($c_currency_info['currency'] != 'ETH');
	$is_ether = ($c_currency_info['currency'] == 'ETH');
	
	$total_received = 0;
	$block_num = false;
	
	if ($is_bitcoin) {
		$bitcoin = new Bitcoin($wallet['bitcoin_username'],$wallet['bitcoin_passphrase'],'localhost',$wallet['bitcoin_port'],$wallet['bitcoin_protocol']);
		if ($c_currency_info['currency'] == 'DASH')
			$bitcoin->settxfee((float)$wallet['bitcoin_sending_fee']);
		else
			$bitcoin->settxfee(($wallet['bitcoin_sending_fee'] / 226) * 1000);
	}
	else if ($is_ether) {
		$ethereum = new Ethereum($wallet['bitcoin_username'],$wallet['bitcoin_passphrase'],'localhost',$wallet['bitcoin_port'],$wallet['etherscan_api_key']);
		$block_num = $ethereum->getBlockNum();
		$address_list = $ethereum->getAddressesWithBalance();
		$transactions = array();
		
		foreach ($address_list as $address) {
			$trans_received = $ethereum->getTransactionsFromEtherscan($block_num,$address);
			if ($trans_received)
				$transactions = array_merge($transactions,$trans_received);
		}
	}
	
	$email = SiteEmail::getRecord('new-deposit');
	$sql = "SELECT transaction_id, id FROM requests WHERE request_status != {$CFG->request_completed_id} AND currency = {$wallet['c_currency']} AND request_type = {$CFG->request_deposit_id} ";
	$result = db_query_array($sql);
	if ($result) {
		foreach ($result as $row) {
			$requests[$row['transaction_id']] = $row['id'];
		}
	}
	
	$addresses = array();
	$addresses_received = array();
	$hot_wallet_addresses = array();
	$user_balances = array();
	
	foreach ($transactions as $t_id) {
		if (!$t_id || $t_id == '.' || $t_id == '..' || $t_id == '.gitignore')
			continue;
		
		if ($is_bitcoin) {
			$transaction = $bitcoin->gettransaction($t_id);
			
			if (!empty($transaction_log[$t_id])) {
				unlink($transactions_dir.$t_id);
				continue;
			}
			if (empty($transaction['details']))
				continue;
		}
		else if ($is_ether) {
			$transaction = $t_id;
			$transaction['details'] = array($t_id);
			
			if (!empty($transaction_log[$t_id['txid']]))
				continue;
		}
		
		$send = false;
		$pending = false;
		$hot_wallet_in = 0;
		
		foreach ($transaction['details'] as $detail) {
			if ($is_bitcoin) {
				if ($detail['category'] == 'send') {
					$send = true;
					continue;
				}
			}
			
			$addr_in = ($is_bitcoin) ? $detail['address'] : $detail['to'];
			if (empty($addresses[$addr_in])) {
				$addr_info = BitcoinAddresses::getAddress($addr_in,$wallet['c_currency']);
				if (!$addr_info)
					continue;
				
				$addresses[$addr_in] = $addr_info;
				$addresses_received[$addr_in] = 0;
			}
			
			$user_id = $addresses[$addr_in]['site_user'];
			$request_id = (!empty($requests[$transaction['txid']])) ? $requests[$transaction['txid']] : false;
			$fee = ($detail['amount'] * $CFG->crypto_deposit_fee * 0.01) + $CFG->crypto_deposit_fee_unit;
			$net_amount = max($detail['amount'] - $fee,0);
			
			/*
			$limits = User::checkLimits(true,$net_amount,$c_currency_info['id']);
			
			if (!$limits || $limits['d_remaining'] < 0)
				Errors::add(str_replace('[limit]',$limits['d_limit'],Lang::string('daily-dp-limit')));
			else if ($limits['m_remaining'] < 0)
				Errors::add(str_replace('[limit]',$limits['m_limit'],Lang::string('monthly-dp-limit'))); 
			 */
			 
			// check for hot wallet recharge
			if ($addresses[$addr_in]['hot_wallet'] == 'Y') {
				if ($transaction['confirmations'] > 0) {
					$hot_wallet_in = $detail['amount'];
					
					if (empty($hot_wallet_addresses[$addr_in]))
						$hot_wallet_addresses[$addr_in] = 0;
					
					$hot_wallet_addresses[$addr_in] += $detail['amount'];
					unset($addresses_received[$addr_in]);
				}
				
				continue;
			}
			elseif ($addresses[$addr_in]['system_address'] == 'Y') {
				if ($is_bitcoin)
					unlink($transactions_dir.$t_id);
				else if ($is_ether) {
					BitcoinAddresses::updateAddressBalance($addresses[$addr_in]['id'],$detail['amount'],true);
					db_insert('bitcoind_log',array('transaction_id'=>$transaction['txid'],'amount'=>$detail['amount'],'date'=>date('Y-m-d H:i:s')));	
				}
				
				unset($addresses_received[$addr_in]);
				continue;
			}
			
			// get user balance... no need to lock
			if (empty($user_balances[$user_id])) {
				$bal_info = User::getBalance($user_id,$wallet['c_currency']);
				$user_balances[$user_id] = $bal_info['balance'];
			}
			
			// if not confirmed enough
			if (($addresses[$addr_in]['trusted'] == 'Y' && $transaction['confirmations'] < 1) || ($addresses[$addr_in]['trusted'] != 'Y' && $transaction['confirmations'] < $wallet['confirmations'])) {
				if (!($request_id > 0)) {
					$rid = db_insert('requests',array('date'=>date('Y-m-d H:i:s'),'site_user'=>$user_id,'currency'=>$wallet['c_currency'],'amount'=>$detail['amount'],'net_amount'=>$net_amount,'fee'=>$fee,'description'=>$CFG->deposit_bitcoin_desc,'request_status'=>$CFG->request_pending_id,'request_type'=>$CFG->request_deposit_id,'transaction_id'=>$transaction['txid'],'send_address'=>$addr_in));
					db_insert('history',array('date'=>date('Y-m-d H:i:s'),'history_action'=>$CFG->history_deposit_id,'site_user'=>$user_id,'request_id'=>$rid,'balance_before'=>$user_balances[$user_id],'balance_after'=>($user_balances[$user_id] + $net_amount),'bitcoin_address'=>$addr_in));
				}
				
				echo $CFG->currencies[$wallet['c_currency']]['currency'].' transaction pending.'.PHP_EOL;
				$pending = true;
			}
			else {
				// if confirmation sufficient
				if (!($request_id > 0)) {
					$updated = db_insert('requests',array('date'=>date('Y-m-d H:i:s'),'site_user'=>$user_id,'currency'=>$wallet['c_currency'],'amount'=>$detail['amount'],'net_amount'=>$net_amount,'fee'=>$fee,'description'=>$CFG->deposit_bitcoin_desc,'request_status'=>$CFG->request_completed_id,'request_type'=>$CFG->request_deposit_id,'transaction_id'=>$transaction['txid'],'send_address'=>$addr_in));
					db_insert('history',array('date'=>date('Y-m-d H:i:s'),'history_action'=>$CFG->history_deposit_id,'site_user'=>$user_id,'request_id'=>$updated,'balance_before'=>$user_balances[$user_id],'balance_after'=>($user_balances[$user_id] + $net_amount),'bitcoin_address'=>$addr_in));
				}
				else
					$updated = db_update('requests',$request_id,array('request_status'=>$CFG->request_completed_id));
				
				if ($updated > 0) {
					User::updateBalances($user_id,array($wallet['c_currency']=>$net_amount),true);
					Status::updateEscrows(array($wallet['c_currency']=>$fee));
					db_insert('bitcoind_log',array('transaction_id'=>$transaction['txid'],'amount'=>$detail['amount'],'date'=>date('Y-m-d H:i:s')));
					
					$total_received += $detail['amount'];
					$addresses_received[$addr_in] += $detail['amount'];
					$user_balances[$user_id] = $user_balances[$user_id] + $net_amount;
					$info = $addresses[$addr_in];
					
					if ($is_bitcoin) {
						$unlink = unlink($transactions_dir.$t_id);
						if (!$unlink && file_exists($unlink)) {
							$unlink = unlink($transactions_dir.$t_id);
						}
					}
					else if ($is_ether)
						$unlink = true;
					
					if ($info['notify_deposit_btc'] == 'Y') {
					    $info['amount'] = $detail['amount'];
					    $info['currency'] = $CFG->currencies[$wallet['c_currency']]['currency'];
					    $info['id'] = (!empty($request_id)) ? $request_id : $updated;
					    $CFG->language = ($info['last_lang']) ? $info['last_lang'] : 'en';
					    Email::send($CFG->form_email,$info['email'],str_replace('[amount]',$detail['amount'],str_replace('[currency]',$info['currency'],$email['title'])),$CFG->form_email_from,false,$email['content'],$info);
					}
					
					if (!$unlink)
						echo 'Error: Could not delete transaction file.'.PHP_EOL;
					else
						echo $CFG->currencies[$wallet['c_currency']]['currency'].' transaction credited successfully.'.PHP_EOL;
				}
			}
		}
		
		if ($send && !$pending && !($hot_wallet_in > 0) && $is_bitcoin)
			unlink($transactions_dir.$t_id);
		elseif (!$send && ($hot_wallet_in > 0)) {
			$updated = Wallets::sumFields($wallet['id'],array('hot_wallet_btc'=>$hot_wallet_in,'warm_wallet_btc'=>(-1 * ($hot_wallet_in + $wallet['bitcoin_sending_fee'])),'total_btc'=>(-1 * $wallet['bitcoin_sending_fee'])));
			echo 'Hot wallet received '.$hot_wallet_in.' '.$CFG->currencies[$wallet['c_currency']]['currency'].PHP_EOL;
			if ($updated) {
				if ($is_bitcoin) {
					$unlink = unlink($transactions_dir.$t_id);
					if (!$unlink && file_exists($unlink)) {
						$unlink = unlink($transactions_dir.$t_id);
					}
				}
				
				db_insert('bitcoind_log',array('transaction_id'=>$transaction['txid'],'amount'=>$hot_wallet_in,'date'=>date('Y-m-d H:i:s')));
				db_update('wallets',$wallet['id'],array('hot_wallet_notified'=>'N'));
			}
		}
	}
	
	$warm_wallet = $wallet['bitcoin_warm_wallet_address'];
	$reserve = Wallets::getReserveSurplus($wallet['id']);
	$reserve_surplus = round($reserve['surplus'],8,PHP_ROUND_HALF_UP) + $total_received;
	echo 'Reserve surplus: '.sprintf("%.8f", $reserve_surplus).' '.$CFG->currencies[$wallet['c_currency']]['currency'].PHP_EOL;
	
	if ($total_received > 0) {
		echo 'Total '.$CFG->currencies[$wallet['c_currency']]['currency'].' received: '.$total_received.PHP_EOL;
		$update = Wallets::sumFields($wallet['id'],array('hot_wallet_btc'=>$total_received,'total_btc'=>$total_received));
	}
	
	if ($is_bitcoin) {
		if ($warm_wallet && $reserve_surplus > $CFG->bitcoin_reserve_min) {
			$bitcoin->walletpassphrase($wallet['bitcoin_passphrase'],3);
			$response = $bitcoin->sendtoaddress($warm_wallet,floatval($reserve_surplus));
			$transferred = 0;
			echo $bitcoin->error;
			
			if ($response && !$bitcoin->error) {
				$transferred = $reserve_surplus;
				$transfer_fees = 0;
				$transaction = $bitcoin->gettransaction($response);
				
				foreach ($transaction['details'] as $detail) {
					if ($detail['category'] == 'send') {
						$detail['fee'] = round(abs($detail['fee']),8,PHP_ROUND_HALF_UP);
						if ($detail['fee'] > 0) {
							$transfer_fees += $detail['fee'];
							db_insert('fees',array('fee'=>$detail['fee'],'date'=>date('Y-m-d H:i:s'),'c_currency'=>$wallet['c_currency']));
						}
					}
				}
				
				Wallets::sumFields($wallet['id'],array('hot_wallet_btc'=>(0 - $transferred - $transfer_fees),'warm_wallet_btc'=>$transferred - $transfer_fees,'total_btc'=>(0 - $transfer_fees)));
				echo 'Transferred '.$reserve_surplus.' '.$CFG->currencies[$wallet['c_currency']]['currency'].' to warm wallet.'.PHP_EOL;
			}
		}
	}
	else if ($is_ether) {
		$hot_wallets = BitcoinAddresses::getHotWallets($wallet['c_currency']);
		$system_addresses = BitcoinAddresses::getSystemAddresses($wallet['c_currency']);
		$system_wallet = ($system_addresses) ? $system_addresses[0] : false;
		$gas_price = $wallet['bitcoin_sending_fee'];
		$send_to_hot = array();
		$send_to_warm = array();
		
		if (count($addresses_received) > 0) foreach ($addresses_received as $addr_in => $amount) {
			if ($reserve_surplus > 0 && $warm_wallet) {
				$send_to_warm[] = array('amount'=>min($amount,$reserve_surplus),'from'=>$addr_in,'to'=>$warm_wallet,'fee'=>$gas_price);
				$orig_amount = $amount;
				$amount -= $reserve_surplus;
				$reserve_surplus -= $orig_amount;
			}
			
			if ($amount > 0 && $system_wallet) {
				$send_to_hot[] = array('amount'=>$amount,'from'=>$addr_in,'to'=>$system_wallet['address'],'fee'=>$gas_price);
			}
		}
		
		if (count($hot_wallet_addresses) > 0 && $reserve_surplus > 0 && $warm_wallet) foreach ($hot_wallet_addresses as $addr_in => $amount) {
			if ($reserve_surplus <= 0)
				break;
			
			$send_to_warm[] = array('amount'=>min($amount,$reserve_surplus),'from'=>$addr_in,'to'=>$warm_wallet,'fee'=>$gas_price);
			$reserve_surplus -= $amount;
		}
		
		if (count($hot_wallets) > 0 && $reserve_surplus > 0 && $warm_wallet) foreach ($hot_wallets as $address) {
			if ($reserve_surplus <= 0 || $address['balance'] <= 0)
				break;
			
			$send_to_warm[] = array('amount'=>min($address['balance'],$reserve_surplus),'from'=>$address['address'],'to'=>$warm_wallet,'fee'=>$gas_price);
			$reserve_surplus -= $address['balance'];
		}

		if (count($send_to_hot) > 0 || count($send_to_warm)) {
			$sent_to_hot = $ethereum->sendTransactions($send_to_hot,$wallet['gas_limit']);
			$sent_to_warm = $ethereum->sendTransactions($send_to_warm,$wallet['gas_limit']);
			
			if ($sent_to_hot['transactions']) foreach ($sent_to_hot['transactions'] as $detail) {
				if (empty($detail['txid']))
					continue;
				
				$transfer_fees += $detail['fee'];
				db_insert('fees',array('fee'=>$detail['fee'],'date'=>date('Y-m-d H:i:s'),'c_currency'=>$wallet['c_currency']));
			}
			
			if ($sent_to_warm['transactions']) foreach ($sent_to_warm['transactions'] as $detail) {
				if (empty($detail['txid']))
					continue;
				
				$transfer_fees += $detail['fee'];
				db_insert('fees',array('fee'=>$detail['fee'],'date'=>date('Y-m-d H:i:s'),'c_currency'=>$wallet['c_currency']));
			}
			
			foreach ($hot_wallets as $address) {
				$balance = $ethereum->getBalance($address['address']);
				if ($balance)
					BitcoinAddresses::updateAddressBalance($address['id'],$balance);
			}
			
			if (!empty($sent_to_warm['sent']) && $sent_to_warm['sent'] > 0) {
				Wallets::sumFields($wallet['id'],array('hot_wallet_btc'=>(0 - $sent_to_warm['sent'] - $transfer_fees),'warm_wallet_btc'=>$sent_to_warm['sent'] - $transfer_fees,'total_btc'=>(0 - $transfer_fees)));
				echo 'Transferred '.$sent_to_warm['sent'].' '.$CFG->currencies[$wallet['c_currency']]['currency'].' to warm wallet.'.PHP_EOL;
			}
		}
	}
}

db_update('status',1,array('cron_receive_bitcoin'=>date('Y-m-d H:i:s')));

echo 'done'.PHP_EOL;
