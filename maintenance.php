#!/usr/bin/php
<?php
echo "Beginning Maintenance processing...".PHP_EOL;

include 'cfg.php';
$CFG->session_active = 1;
$CFG->in_cron = 1;

// compile historical data
$sql = 'SELECT id FROM historical_data WHERE `date` = (CURDATE() - INTERVAL 1 DAY) LIMIT 0,1';
$result = db_query_array($sql);
if (!$result) {
	$sql = "INSERT INTO historical_data (`date`,usd) (SELECT '".(date('Y-m-d',strtotime('-1 day')))."',(transactions.btc_price * currencies.usd_ask) AS btc_price FROM transactions LEFT JOIN currencies ON (transactions.currency = currencies.id) WHERE transactions.date <= (CURDATE() - INTERVAL 1 DAY) ORDER BY transactions.date DESC LIMIT 0,1) ";
	$result = db_query($sql);
}

// get 24 hour BTC volume
$sql = "SELECT SUM(btc) AS total_btc_traded FROM transactions WHERE `date` >= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY `date` ASC LIMIT 0,1";
$result = db_query_array($sql);
$total_btc_traded = ($result[0]['total_btc_traded']) ? $result[0]['total_btc_traded'] : '0';

// determine users' monthly volume
$sql = 'UPDATE site_users s1 JOIN transactions ON (s1.id = transactions.site_user OR s1.id = transactions.site_user1) SET s1.fee_schedule = (SELECT IF(fee_schedule.from_usd >= fee_schedule1.from_usd,fee_schedule.id, fee_schedule1.id) AS id FROM (SELECT ROUND(SUM(transactions.btc * transactions.btc_price * currencies.usd_ask),2) AS volume, site_users.id AS user_id FROM site_users LEFT JOIN transactions ON (site_users.id = transactions.site_user OR site_users.id = transactions.site_user1) LEFT JOIN currencies ON (currencies.id = transactions.currency) WHERE transactions.date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) GROUP BY site_users.id) AS volumes LEFT JOIN fee_schedule ON (volumes.volume >= fee_schedule.from_usd AND (volumes.volume <= fee_schedule.to_usd OR fee_schedule.to_usd = 0)) LEFT JOIN (SELECT id, global_btc, from_usd FROM fee_schedule WHERE global_btc <= '.$total_btc_traded.' ORDER BY global_btc DESC, from_usd ASC LIMIT 0,1) AS fee_schedule1 ON (fee_schedule1.global_btc <= '.$total_btc_traded.') WHERE volumes.user_id = s1.id GROUP BY volumes.volume)';
$result = db_query($sql);
$sql = 'UPDATE site_users SET fee_schedule = 1 WHERE fee_schedule = 0';
$result = db_query($sql);

// expire settings change request
$sql = 'DELETE FROM change_settings WHERE `date` <= ("'.date('Y-m-d H:i:s').'" - INTERVAL 1 DAY)';
$result = db_query($sql);

// expire unautharized requests
$sql = 'UPDATE requests SET request_status = '.$CFG->request_cancelled_id.' WHERE request_status = '.$CFG->request_awaiting_id.' AND `date` <= (NOW() - INTERVAL 1 DAY)';
$result = db_query($sql);

// 30 day token don't ask
$sql = 'UPDATE site_users SET dont_ask_30_days = "N" WHERE dont_ask_date <= (NOW() - INTERVAL 1 MONTH) AND dont_ask_30_days = "Y" ';
$result = db_query($sql);

// delete old sessions
$sql = "DELETE FROM sessions WHERE session_time < ('".date('Y-m-d H:i:s')."' - INTERVAL 15 MINUTE) ";
db_query($sql);

// set market price orders at market price
db_start_transaction();
$sql = "SELECT orders.id AS id,orders.btc AS btc,orders.order_type AS order_type,orders.currency AS currency, fee_schedule.fee AS fee, orders.site_user AS site_user FROM orders LEFT JOIN site_users ON (orders.site_user = site_users.id) LEFT JOIN fee_schedule ON (site_users.fee_schedule = fee_schedule.id) WHERE orders.market_price = 'Y' ORDER BY orders.date ASC FOR UPDATE";
$result = db_query_array($sql);
if ($result) {
	foreach ($result as $row) {
		if ($row['order_type'] == $CFG->order_type_bid) {
			$price = Orders::getCurrentAsk(false,$row['currency']);
			$buy = true;
		}
		else {
			$price = Orders::getCurrentBid(false,$row['currency']);
			$buy = false;
		}
		
		if ($price > 0)
			$operations = Orders::executeOrder($buy,$price,$row['btc'],$row['currency'],$row['fee'],1,$row['id'],$row['site_user'],1);
	}
}
db_commit();

// subtract withdrawals
db_start_transaction();
$sql = 'SELECT requests.site_user AS site_user, LOWER(currencies.currency) AS currency, SUM(requests.amount) AS amount FROM requests LEFT JOIN currencies ON (currencies.id = requests.currency) WHERE requests.request_type = '.$CFG->request_widthdrawal_id.' AND requests.currency != '.$CFG->btc_currency_id.' AND requests.request_status = '.$CFG->request_pending_id.' AND requests.done = \'Y\' GROUP BY requests.site_user, requests.currency FOR UPDATE';
$result = db_query_array($sql);
if ($result) {
	foreach ($result as $row) {
		$sql = 'UPDATE site_users SET '.$row['currency'].' = '.$row['currency'].' - '.$row['amount'].' WHERE id = '.$row['site_user'];
		db_query($sql);
	}
	$sql = 'UPDATE requests SET request_status = '.$CFG->request_completed_id.' WHERE requests.request_type = '.$CFG->request_widthdrawal_id.' AND requests.currency != '.$CFG->btc_currency_id.' AND requests.request_status = '.$CFG->request_pending_id.' AND requests.done = \'Y\' ';
	db_query($sql);
}
db_commit();
echo 'done'.PHP_EOL;
