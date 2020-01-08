<?php
loadHelper("string.utils");

$format = get_requested_value("format");

$device_id = get_requested_value("device_id");
$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");

if(empty($device_id)) {
    set_error("device_id is required");
    show_errors();
}

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($start_dt)) {
    $start_dt = get_current_datetime(array(
        "adjust" => "-1h"
    ));
}

// set variables
$devices = array();
$nodes = array();
$relations = array();

// get device information
$bind = array(
    "id" => $device_id
);
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$rows = exec_db_fetch_all($sql, $bind);
foreach($rows as $row) {
    $devices[] = $row;
    $nodekey = sprintf("dv_%s", $row['id']);
    $nodes[$nodekey] = $row['computer_name'];
}
$device = current($devices);

// 1: tasklist (command_id = 1)
// 2: netstat -anof | findstr -v 127.0.0.1 | findstr -v UDP (command_id = 2)
// 4: netstat -ntp | grep -v 127.0.0.1 | grep -v ::1 (command_id = 4)
$bind = false;
$sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
    "setwheres" => array(
        array("and", array(
            array("or", array(
                array("and", array("eq", "command_id", 1)),
                array("and", array("in", "pos_x", array(1, 2))),
                array("and", array("gt", "pos_y", 3))
            )),
            array("or", array(
                array("and", array("eq", "command_id", 2)),
                array("and", array("in", "pos_x", array(2, 4, 5))),
                array("and", array("gt", "pos_y", 4))
            )),
            array("or", array(
                array("and", array("eq", "command_id", 4)),
                array("and", array("in", "pos_x", array(4, 6, 7))),
                array("and", array("gt", "pos_y", 2))
            ))
        )),
        array("and", array("gte", "datetime", $start_dt)),
        array("and", array("lte", "datetime", $end_dt)),
        array("and", array("eq", "device_id", $device_id))
    )
));
$_tbl0 = exec_db_temp_start($sql);

// tasklist (windows)
$sql = "
select
    group_concat(if(pos_x = 1, b.term, null)) as process_name,
    group_concat(if(pos_x = 2, b.term, null)) as pid
from $_tbl0 a left join autoget_terms b on a.term_id = b.id
where a.command_id = 1
group by a.pos_y, a.datetime
";
$_tbl1 = exec_db_temp_start($sql);

// netstat (windows)
$sql = "
select
    group_concat(if(a.pos_x = 2, b.term, null)) as address,
    substring_index(group_concat(if(a.pos_x = 2, b.term, null)), ':', -1) as port,
    group_concat(if(a.pos_x = 4, b.term, null)) as state,
    group_concat(if(a.pos_x = 5, b.term, null)) as pid
from $_tbl0 a left join autoget_terms b on a.term_id = b.id
where a.command_id = 2
group by a.pos_y, a.datetime
";
$rows = exec_db_fetch_all($sql);
var_dump($rows);
exit;

$_tbl2 = exec_db_temp_start($sql);

// netstat (linux)
$sql = "
select
    substring_index(group_concat(if(a.pos_x = 2, b.term, null)), ':', 1) as address,
    substring_index(group_concat(if(a.pos_x = 4, b.term, null)), ':', -1) as port,
    group_concat(if(a.pos_x = 6, b.term, null)) as state,
    substring_index(group_concat(if(a.pos_x = 7, b.term, null)), '/', 1) as pid,
    substring_index(group_concat(if(a.pos_x = 7, b.term, null)), '/', -1) as process_name
from $_tbl0 a left join autoget_terms b on a.term_id = b.id
where command_id = 4
group by a.pos_y, a.datetime
";
$_tbl3 = exec_db_temp_start($sql);

// step 1
$_tbl4 = false;
if($device['platform'] == "windows") {
    $sql = "
    select
        b.process_name as process_name,
        a.address as address,
        a.port as port,
        a.state as state,
        a.pid as pid
    from $_tbl2 a left join $_tbl1 b on a.pid = b.pid";
} elseif($device['platform'] == "linux")  {
    $sql = "select process_name, address, port, state, pid from $_tbl3";
}
$rows = exec_db_fetch_all($sql);
var_dump($rows);
exit;

$_tbl4 = exec_db_temp_start($sql);

// step 2
$sql = "select * from $_tbl4 group by port";
$_tbl5 = exec_db_temp_start($sql);

// add IPv4 to nodes
$sql = "select address as ip from $_tbl5 group by ip";
$rows = exec_db_fetch_all($sql);
foreach($rows as $row) {
    if(strlen($row['ip']) < 2) {
        $row['ip'] = "Unknown";
    }
    if(!in_array($row['ip'], array("0.0.0.0", "Unknown"))) {
        $nodekey = sprintf("ip_%s", get_hashed_text($row['ip']));
        $nodes[$nodekey] = $row['ip'];
    }
}

// add port/process to node
$sql = "select concat(port, ',', process_name) as pp from $_tbl5";
$rows = exec_db_fetch_all($sql);
foreach($rows as $row) {
    if(strlen($row['pp'] < 2)) {
        $row['pp'] = "Unknown";
    }
    if(!in_array($row['pp'], array("Unknown"))) {
        $nodekey = sprintf("pp_%s", get_hashed_text($row['pp']));
        $nodes[$nodekey] = $row['pp'];
    }
}

// make relations
$sql = "select address as ip, concat(port, ',', process_name) as pp, state from $_tbl5";
$rows = exec_db_fetch_all($sql);
foreach($rows as $row) {
    if(strlen($row['ip']) < 2) {
        $row['ip'] = "Unknown";
    }

    if(strlen($row['pp'] < 2)) {
        $row['pp'] = "Unknown";
    }

    if(strlen($row['state']) < 2) {
        $row['state'] = "Unknown";
    }

    $ip_nodekey = sprintf("ip_%s", get_hashed_text($row['ip']));
    $pp_nodekey = sprintf("pp_%s", get_hashed_text($row['pp']));
    $dv_nodekey = sprintf("dv_%s", $device['id']);
    if(!in_array($row['pp'], array("Unknown"))) {
        $relations[] = array($dv_nodekey, $pp_nodekey, $row['state']);
    }
    if(!in_array($row['pp'], array("Unknown")) && !in_array($row['ip'], array("0.0.0.0", "Unknown"))) {
        $relations[] = array($pp_nodekey, $ip_nodekey, $row['state']);
    }
}

// clean relations
for($i = 0; $i < count($relations); $i++) {
    $rel = $relations[$i];
    if(!(array_key_exists($rel[0], $nodes) && array_key_exists($rel[1], $nodes))) {
        unset($relations[$i]);
    }
}

// pass to view
$data = array(
    "nodes" => $nodes,
    "relations" => $relations
);

renderView("view_api.portmap.dot", $data);

// close all temporary tables
exec_db_temp_end($_tbl6);
exec_db_temp_end($_tbl5);
exec_db_temp_end($_tbl4);
exec_db_temp_end($_tbl3);
exec_db_temp_end($_tbl2);
exec_db_temp_end($_tbl1);
exec_db_temp_end($_tbl0);
