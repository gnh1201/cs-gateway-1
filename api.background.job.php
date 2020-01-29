<?php
loadHelper("webpagetool");

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

$responses = array();

$allow_actions = array("cpucore", "cputime", "cpu", "memtotal", "memtime", "mem", "disk", "hotfix", "portmap", "report.data", "report.excel", "report.batch");

$bind = false;
$sql = get_bind_to_sql_select("autoget_devices", $bind, array(
    "fieldnames" => "id"
));
$devices = exec_db_fetch_all($sql, $bind);

if(in_array($action, $allow_actions)) {
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

            case "memtotal":
                // get memory total
                $responses[] = get_web_page(get_route_link("api.memtotal.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
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
                
            case "disk":
                // get disk usage
                $responses[] = get_web_page(get_route_link("api.disk.json"), "get", array(
                    "device_id" => $device['id'],
                    "adjust" => $adjust,
                    "mode" => "background"
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
                
            case "report.data":
                // create report data
                $responses[] = get_web_page(get_route_link("api.report.json"), "get", array(
                    "adjust" => $adjust,
                    "mode" => "table.insert"
                ));
                break;

            case "report.excel":
                // overrride time range
                $end_dt = date("Y-m-d 18:00:00");
                $start_dt = get_current_datetime(array(
                    "now" => $end_dt,
                    "adjust" => $adjust
                ));

                // make report excel
                $responses[] = get_web_page(get_route_link("api.report.json"), "get", array(
                    "end_dt" => $end_dt,
                    "adjust" => $adjust,
                    "mode" => "make.excel"
                ));
                break;

            case "report.batch":
                $ds = range(1, 30);
                foreach($ds as $d) {
                    $_end_dt = date(sprintf("Y-m-%02d 00:00:00", $d + 1));
                    // create report data
                    $responses[] = get_web_page(get_route_link("api.report.json"), "get", array(
                        "end_dt" => $_end_dt,
                        "adjust" => "-24h",
                        "mode" => "table.insert"
                    ));

                    // make report excel
                    $_end_dt = date(sprintf("Y-m-%02d 18:00:00", $d));
                    $responses[] = get_web_page(get_route_link("api.report.json"), "get", array(
                        "end_dt" => $_end_dt,
                        "adjust" => "-10h",
                        "mode" => "make.excel"
                    ));
                }

                break;
        }
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

header("Content-Type: application/json");
$data = array(
    "data" => $responses
);
echo json_encode($data);
