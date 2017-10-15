#!/usr/bin/php
<?php
include 'common.php';
echo date('Y-m-d H:i:s').' Beginning Fiat Withdrawal processing...'.PHP_EOL;
ini_set('display_errors',1);

// GET SYSTEM BANK ACCOUNTS
$sys = array();
$acc = array();

foreach ($CFG->currencies as $c) {
	if (!$c['account_number'])
		continue;
		
	$sys[$c['account_number']] = $c;
};


$cc = new CryptoCap($CFG->crypto_capital_pk,$CFG->crypto_capital_secret);

// FETCH INCOMING DEPOSITS
foreach ($acc as $ac) {
	$result = $cc->statement(array('limit'=>'100', 'accountNumber'=>$ac));
	
	if ($result) foreach ($result as $r) {
		if ($r['type'] != 'deposit')
			continue;
		
		// check internal conversion
		if ($sys[$r['sendAccount']]) {
			db_query('UPDATE requests  
					SET requests.crypto_id = "" 
					WHERE requests.request_status = '.$CFG->request_pending_id.' 
					AND requests.request_type = '.$CFG->request_withdrawal_id.' 
					AND crypto_id = 1 
					AND requests.currency = "'.$CFG->currencies[strtoupper($r['receiveCurrency'])].'"');
			continue;
		}
		
		$e = db_query_array('SELECT id FROM requests WHERE crypto_id = '.$r['id'].' LIMIT 0,1');
		if ($e)
			continue;
		
		$sql = 'SELECT 
				bank_accounts.site_user AS user_id, 
				bank_accounts.currency AS currency_id, 
				currencies1.id AS currency_id1, 
				bank_accounts1.account_number AS account_number1, 
				currencies.currency AS currency, 
				site_users.notify_deposit_bank AS notify_deposit_bank, 
				site_users.first_name AS first_name, 
				site_users.last_name AS last_name, 
				site_users.email AS email, 
				site_users.last_lang AS last_lang, 
				site_users.notify_deposit_bank AS notify_deposit_bank, 
				site_users_balances.balance AS cur_balance, 
				site_users_balances.id AS balance_id 
				FROM bank_accounts 
				LEFT JOIN currencies ON (currencies.id = bank_accounts.currency) 
				LEFT JOIN site_users ON (bank_accounts.site_user = site_users.id) 
				LEFT JOIN currencies currencies1 ON (currencies1.currency = "'.$r['receiveCurrency'].'") 
				LEFT JOIN bank_accounts bank_accounts1 ON (site_users.id = bank_accounts1.site_user AND bank_accounts1.currency = currencies1.id) 
				LEFT JOIN site_users_balances ON (site_users.id = site_users_balances.site_user AND site_users_balances.currency = currencies1.id) 
				WHERE bank_accounts.account_number = '.$r['sendAccount'].' LIMIT 0,1';
				
		$i = db_query_array($sql);
		if (!$i)
			continue;
		
		if ($i['currency'] != $r['receiveCurrency'])
			$i['currency_id'] = $i['currency_id1'];
								
		$fee = $r['sendAmount'] - $r['receiveAmount'];
		$id = db_query('INSERT INTO requests (`date`,site_user,currency,amount,net_amount,fee,description,request_type,request_status,account,crypto_id) VALUES ("'.date('Y-m-d H:i:s').'",'.$i['user_id'].','.$i['currency_id'].','.$r['receiveAmount'].','.$r['receiveAmount'].','.$fee.','.$CFG->deposit_fiat_desc.','.$CFG->request_deposit_id.','.$CFG->request_completed_id.','.$r['sendAccount'].','.$r['id'].')');
	
		if ($i['balance_id'])
			db_query('UPDATE site_users_balances SET balance = balance + '.$r['receiveAmount'].' WHERE id = '.$i['balance_id']);
		else
			db_query('INSERT INTO site_users_balances (balance,site_user,currency) VALUES ('.$r['receiveAmount'].','.$i['user_id'].','.$i['currency_id1'].')');
	
		db_query('INSERT INTO history (`date`,history_action,site_user,request_id,balance_before,balance_after) VALUES ("'.date('Y-m-d H:i:s').'",'.$CFG->history_deposit_id.','.$i['user_id'].','.$id.','.$i['cur_balance'].','.($i['cur_balance'] + $r['receiveAmount']).')');
	}
}

// CHECK PENDING WITHDRAWALS
$sql = 'SELECT r.*, 
		b.id AS user_acc_id,
		c.account_number AS sys_acc_num, 
		c.currency AS currency_abbr 
		FROM requests r 
		LEFT JOIN bank_accounts b ON (b.account_number = r.account AND b.site_user = r.site_user AND b.currency = r.currency) 
		LEFT JOIN currencies c ON (r.currency = c.id) 
		WHERE r.request_status = '.$CFG->request_pending_id.' 
		AND r.request_type = '.$CFG->request_withdrawal_id.' 
		AND c.is_crypto != "Y" 
		AND crypto_id != 1 
		AND r.done != "Y"';
if ($CFG->withdrawals_fiat_manual_approval == 'Y')
	$sql .= ' AND requests.approved = "Y"';

$result = db_query_array($sql);

// SEND WITHDRAWALS FOR PROCESSING
if ($result) foreach ($result as $r) {
	if (!$r['user_acc_id'] || !$r['sys_acc_num']) {
		db_query('UPDATE requests SET request_status = '.$CFG->request_cancelled_id+' WHERE requests.id = '.$r['id']);
		continue;
	}
	
	$res = $cc->transfer(array(
		'fromAccount' => $r['sys_acc_num'],
		'toAccount' => $r['account'],
		'currency' => $r['currency_abbr'],
		'amount' => $r['amount'],
		'narrative' => $CFG->exchange_name.' #'.$r['id']
	));
	
	// SUBTRACT SUCCESSFUL WITHDRAWAL FROM BALANCE
	if ($res) {
		$b = db_query_array('
			SELECT r.id AS request_id, 
			r.site_user AS user_id, 
			sub.balance AS balance, 
			sub.id AS balance_id 
			FROM requests r 
			LEFT JOIN site_users_balances sub ON (r.site_user = sub.site_user AND r.currency = sub.currency) 
			WHERE r.id = '.$r['id'].' 
			AND r.request_status != '.$CFG->request_completed_id.' 
			LIMIT 0,1');
			
		db_query('UPDATE site_users_balances SET balance = balance - '.$r['amount'].' WHERE id = '.$b['balance_id']);
		db_query('UPDATE requests SET request_status = '.$CFG->request_completed_id.', done = "Y" WHERE id = '.$r['id']);
		db_query('UPDATE history SET balance_before = '.$b['balance'].', balance_after = '.($b['balance'] - $r['amount']).' WHERE request_id = '.$r['id']);
	}
}
