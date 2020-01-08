<?php
loadHelper("webpagetool");

$action = get_requested_value("action");
$adjust = get_requested_value("adjust");

if(empty($adjust)) {
    $adjust = "-10m";
}

$start_dt = get_current_datetime(array(
    "adjust" => $adjust
));

$responses = array();

$bind = false;
$sql = get_bind_to_sql_select("autoget_devices", $bind, array(
    "fieldnames" => "id"
));
$devices = exec_db_fetch_all($sql, $bind);

if(in_array($action, array("cpu", "cputime", "mem", "memtime", "disk", "hotfix", "portmap"))) {
    foreach($devices as $device) {
        switch($action) {
            case "cpu":
                // get cpu usage
                $responses[] = get_web_page(get_route_link("api.cpu.json"), "get", array(
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
            
            case "mem":
                // get memory usage
                $responses[] = get_web_page(get_route_link("api.mem.json"), "get", array(
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
        }
    }
}

if($action == "flush") {
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

    $responses[] = array("content" => "done flush");
}

header("Content-Type: application/json");
$data = array(
    "data" => $responses
);
echo json_encode($data);
