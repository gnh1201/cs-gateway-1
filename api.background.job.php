<?php
loadHelper("webpagetool");
loadHelper("perftool");

$action = get_requested_value("action");
$adjust = get_requested_value("adjust");
$end_dt = get_requested_value("end_dt");
$start_dt = get_requested_value("start_dt");

if(empty($adjust)) {
    $adjust = "-10m";
}

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($start_dt)) {
    $start_dt = get_current_datetime(array(
        "now" => $end_dt,
        "adjust" => $adjust
    ));
}

// wait a few seconds if cpu idle 25% or below
//set_min_cpu_idle(0.25);

$responses = array();

$device_actions = array(
	"cpucore",
    "cpucore.zabbix",
	"cputime",
	"cpu",
	"cpu.zabbix",
	"memtotal",
	"memtotal.zabbix",
	"memtime",
	"mem",
	"mem.zabbix",
	"disk",
	"disk.zabbix",
	"hotfix",
	"portmap",
	"network",
    "user"
);

$bind = false;
$sql = get_bind_to_sql_select("autoget_devices", $bind, array(
    "fieldnames" => "id"
));
$devices = exec_db_fetch_all($sql, $bind);

if(in_array($action, $device_actions)) {
    foreach($devices as $device) {
        switch($action) {
            case "cpucore":
                // get cpu cores
                $responses[] = get_web_page(get_route_link("api.cpucore.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
                ));
                break;
                
            case "cpucore.zabbix":
                // get cpu cores from zabbix
                $responses[] = get_web_page(get_route_link("api.cpucore.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background.zabbix"
                ));
                break;

            case "cputime":
                // get cpu usage details
                $responses[] = get_web_page(get_route_link("api.cputime.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
                ));
                break;

            case "cpu":
                // get cpu usage
                $responses[] = get_web_page(get_route_link("api.cpu.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
                ));
                break;
                
            case "cpu.zabbix":
                // get cpu usage
                $responses[] = get_web_page(get_route_link("api.cpu.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background.zabbix"
                ));
                break;

            case "memtotal":
                // get memory total
                $responses[] = get_web_page(get_route_link("api.memtotal.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
                ));
                break;

            case "memtotal.zabbix":
                // get memory total (zabbix)
                $responses[] = get_web_page(get_route_link("api.memtotal.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background.zabbix"
                ));
                break;

            case "memtime":
                // get memory usage
                $responses[] = get_web_page(get_route_link("api.memtime.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
                ));
                break;

            case "mem":
                // get memory usage
                $responses[] = get_web_page(get_route_link("api.mem.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
                ));
                break;
                
            case "mem.zabbix":
                // get cpu usage
                $responses[] = get_web_page(get_route_link("api.mem.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background.zabbix"
                ));
                break;

            case "disk":
                // get disk usage
                $responses[] = get_web_page(get_route_link("api.disk.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
                ));
                break;

            case "disk.zabbix":
                // get disk usage
                $responses[] = get_web_page(get_route_link("api.disk.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background.zabbix"
                ));
                break;

            case "hotfix":
                // get disk usage
                $responses[] = get_web_page(get_route_link("api.hotfix.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
                ));
                break;
                
            case "portmap":
                // get disk usage
                $responses[] = get_web_page(get_route_link("api.portmap.dot"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
                ));
                break;

            case "user":
                // get user data
                $responses[] = get_web_page(get_route_link("api.user.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
                ));
                break;

            case "network":
                // get network data (zabbix)
                $responses[] = get_web_page(get_route_link("api.network.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
                ));
                break;
        }
    }
}

if($action == "report.data") {
    // overrride time range
    $end_dt = substr($end_dt, 0, 10) . " 23:59:59";
    $adjust = get_requested_value("adjust");

    // create report data
    $responses[] = get_web_json(get_route_link("api.report.json"), "get", array(
        "end_dt" => $end_dt,
        "adjust" => $adjust,
        "mode" => "table.insert"
    ));
}

if($action == "report.excel") {
    // overrride time range
    $end_dt = substr($end_dt, 0, 10) . " 18:00:00";
    $adjust = get_requested_value("adjust");
    $planner = get_requested_value("planner");

    // make report excel
    $responses[] = get_web_page(get_route_link("api.report.json"), "get", array(
        "end_dt" => $end_dt,
        "adjust" => $adjust,
        "mode" => "make.excel",
        "planner" => $planner
    ));
}

// simulation
if($action == "report.batch") {
    $s = array(range(1, 31), range(1, 3));
    $d = array();
    foreach($s as $k=>$v) {
        foreach($v as $a) {
            $d[] = sprintf("%04d-%02d-%02d", 2020, $k + 1, $a);
        }
    }
    $w = array("2020-01-03", "2020-01-10", "2020-01-17", "2020-01-24", "2020-01-31");
    $m = array("2020-01-31");
    
    // daily
    foreach($d as $_d) {
        $end_dt = $_d . " 18:00:00";
        $adjust = "-10h";
        $planner = "daily";
        $responses[] = get_web_page(get_route_link("api.report.json"), "get", array(
            "end_dt" => $end_dt,
            "adjust" => $adjust,
            "mode" => "make.excel",
            "planner" => $planner
        ));
    }

    // weekly
    foreach($w as $_w) {
        $end_dt = $_w. " 18:00:00";
        $adjust = "-5d";
        $planner = "weekly";
        $responses[] = get_web_page(get_route_link("api.report.json"), "get", array(
            "end_dt" => $end_dt,
            "adjust" => $adjust,
            "mode" => "make.excel",
            "planner" => $planner
        ));
    }
    
    // monthly
    foreach($m as $_m) {
        $end_dt = $_m. " 18:00:00";
        $adjust = "-30d";
        $planner = "monthly";
        $responses[] = get_web_page(get_route_link("api.report.json"), "get", array(
            "end_dt" => $end_dt,
            "adjust" => $adjust,
            "mode" => "make.excel",
            "planner" => $planner
        ));
    }
}

if($action == "flush_sheets") {
    // flush sheets
    $sql = get_bind_to_sql_select("autoget_sheets.tables", false, array(
        "setwheres" => array(
            array("and", array("lt", "datetime", $start_dt))
        )
    ));
    $rows = exec_db_fetch_all($sql, false);
    foreach($rows as $row) {
        $sql = sprintf("drop table `%s`", $row['table_name']);
        exec_db_query($sql);

        $bind = array(
            "table_name" => $row['table_name']
        );
        $sql = get_bind_to_sql_delete("autoget_sheets.tables", $bind);
        exec_db_query($sql, $bind);
    }

    $responses[] = array(
        "success" => true
    );
}

if($action == "flush_terms") {
    // flush terms
    $bind = array(
        "start_dt" => $start_dt
    );
    $sql = "delete from autoget_terms where last < :start_dt";
    $result = exec_db_query($sql, $bind);

    $responses[] = array(
        "success" => $result
    );
}

if($action == "flush_tx_queue") {
    // flush tx_queue
    $bind = array(
        "start_dt" => $start_dt
    );
    $sql = "delete from autoget_tx_queue where expired_on < :start_dt";
    $result = exec_db_query($sql, $bind);

    $responses[] = array(
        "success" => $result
    );
}

if($action == "grafana.status") {
	// change status (commit)
	$bind = array(
		"status" => 1
	);
	$sql = get_bind_to_sql_update("autoget_data_reverse_file", $bind, array(
		"setwheres" => array(
			array("and", array("eq", "status", 0))
		)
	));
	exec_db_query($sql, $bind);
	
	// reload status
	$bind = false;
	$sql = get_bind_to_sql_select("autoget_data_reverse", $bind, array(
		"setwheres" => array(
			array("and", array("like", "uri", "/api.zbx.status.json"))
		)
	));
	$rows = exec_db_fetch_all($sql, $bind);
	foreach($rows as $row) {
		$_data = json_decode($row['text']);
		$_url = get_route_link("api.zbx.status.json", array(
			"_uri" => $row['uri'],
			"mode" => "background"
		), false);
		$responses[] = get_web_page($_url, "jsondata.async", $_data);
	}
}

if($action == "grafana.top") {
	$bind = false;
	$sql = get_bind_to_sql_select("autoget_data_reverse", $bind, array(
		"setwheres" => array(
			array("and", array("like", "uri", "/api.zbx.top.json"))
		)
	));
	$rows = exec_db_fetch_all($sql, $bind);
	foreach($rows as $row) {
		$_data = json_decode($row['text']);
		$_url = get_route_link("api.zbx.top.json", array(
			"_uri" => $row['uri'],
			"mode" => "background"
		), false);
		$responses[] = get_web_page($_url, "jsondata.async", $_data);
	}
}

if($action == "hosts") {
    $responses[] = get_web_page(get_route_link("api.hosts.json"), "get.async");
}

header("Content-Type: application/json");
$data = array(
    "data" => $responses
);
echo json_encode($data);
