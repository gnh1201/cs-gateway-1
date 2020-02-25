<?php
loadHelper("itsm.api");
loadHelper("zabbix.api");

$data = array(
	"success" => false
);

$responses = array();

$bind = false;
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$devices = exec_db_fetch_all($sql, $bind);

foreach($devices as $device) {
	$is_zabbix = !empty($device['zabbix_hostid']);
	$is_itsm = !empty($device['itsm_assetid']);

	if($is_zabbix && !$is_itsm) {
		// insert new data to datamon
		$responses[] = itsm_add_data("assets", array(
			"categoryid" => 1,
			"adminid" => 1,
			"clientid" => 1,
			"userid" => 1,
			"manufacturerid" => "",
			"modelid" => "",
			"supplierid" => "",
			"statusid" => "4",
			"purchase_date" => date("Y-m-d"),
			"warranty_date" => date("Y-m-d"),
			"warranty_months" => 36,
			"tag" => "ZBXHOST-" . $device['zabbix_hostid'],
			"name" => $device['computer_name'],
			"serial" => "",
			"notes" => "",
			"locationid" => "",
			"qrvalue" => ""
		));
		write_common_log(sprintf("ADDED. TAG: %s, STATUSID: %s",  "DEVICE-ID-" . $device['id'], 4));
	} elseif(!$is_zabbix && $is_itsm) {
		// change asset status to idle(idle=2)
		$responses[] = itsm_edit_data("assets", array(
			"id" => $device['itsm_assetid'],
			"statusid" => "2"
		));
		write_common_log(sprintf("EDITED. ID: %s, STATUSID: %s",  $device['itsm_assetid'], 2));
	}
}

$data['success'] = true;
$data['responses'] = $responses;

header("Content-Type: application/json");
echo json_encode($data);
