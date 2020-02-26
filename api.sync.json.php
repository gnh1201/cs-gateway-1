<?php
loadHelper("itsm.api");
loadHelper("zabbix.api");

$data = array(
	"success" => false
);

$responses = array();

$zabbix_hostids = array();

$bind = false;
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$devices = exec_db_fetch_all($sql, $bind);
foreach($devices as $device) {
	$is_zabbix = !empty($device['zabbix_hostid']);
	$is_itsm = !empty($device['itsm_assetid']);

	$tag = "ZBXHOST-" . $device['zabbix_hostid'];

	if($is_zabbix) {
		$zabbix_hostids[] = $device['zabbix_hostid'];
	}

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

		// update asset ID to device
		$rows = itsm_get_data("assets", array(
			"tag" => $tag
		));
		foreach($rows as $row) {
			$bind = array(
				"id" => $device['id'],
				"itsm_assetid" => $row->id
			);
			$sql = get_bind_to_sql_update("autoget_devices", $bind,  array(
				"setkeys" => array("id")
			));
			exec_db_query($sql, $bind);
		}
		write_common_log("Added: " . $tag); 
	} elseif(!$is_zabbix && $is_itsm) {
		// change asset status to idle(idle=2)
		$responses[] = itsm_edit_data("assets", array(
			"id" => $device['itsm_assetid'],
			"statusid" => "2"
		));
		write_common_log("Edited: " . $tag);
	}
}

// get hosts from zabbix
zabbix_authenticate();
$hosts = zabbix_get_hosts();
foreach($hosts as $host) {
	$tag = "ZBXHOST-" . $host->hostid;

	if(!in_array($host->hostid, $zabbix_hostids)) {
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
			"tag" => "ZBXHOST-" . $host->hostid,
			"name" => $host->host,
			"serial" => "",
			"notes" => "",
			"locationid" => "",
			"qrvalue" => ""
		), array(
			49 => current($host->interfaces)->ip
		));
		write_common_log("Added: " . $tag);
	} else {
		$rows = itsm_get_data("assets", array(
			"tag" => "ZBXHOST-" . $host->hostid
		));
		foreach($rows as $row) {
			$responses[] = itsm_edit_data("assets", array(
				"id" => $row->id
			), array(
				49 => current($host->interfaces)->ip
			));
			write_common_log("Edited: " . $tag);
		}
	}
}

$data['success'] = true;
$data['responses'] = $responses;

header("Content-Type: application/json");
echo json_encode($data);
