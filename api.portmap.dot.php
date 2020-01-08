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
        "adjust" => "-24 hours"
    ));
}

// get devices
$bind = array(
    "id" => $device_id
);

$sql = get_bind_to_sql_select("autoget_devices", $bind);
$row = exec_db_fetch($sql, $bind);

if(array_key_empty("id", $row)) {
    set_error("device ID could not found");
    show_errors();
}
$device = $row;
$devices[$row['id']] = $row['computer_name'];

// 1: tasklist (command_id = 1)
// 2: netstat -anof | findstr -v 127.0.0.1 | findstr -v UDP (command_id = 2)
// 4: netstat -ntp | grep -v 127.0.0.1 | grep -v ::1 (command_id = 4)
$sql = get_bind_to_sql_select("autoget_sheets", false, array(
    "setwheres" => array(
        array("and", array(
            array("or", array(
                array("and", array("eq", "command_id", 1)),
                array("and", array("in", "col_n", array(1, 2))),
                array("and", array("gt", "row_n", 3))
            )),
            array("or", array(
                array("and", array("eq", "command_id", 2)),
                array("and", array("in", "col_n", array(2, 4, 5))),
                array("and", array("gt", "row_n", 4))
            )),
            array("or", array(
                array("and", array("eq", "command_id", 4)),
                array("and", array("in", "col_n", array(4, 6, 7))),
                array("and", array("gt", "row_n", 2))
            ))
        )),
        array("and", array("gte", "datetime", $start_dt)),
        array("and", array("lte", "datetime", $end_dt)),
        array("and", array("eq", "device_id", $device_id))
    ),
    "setpage" => 1,
    "setlimit" => 5000000
));
$_tbl1 = exec_db_temp_start($sql);

//_tbl2: tasklist (windows)
$sql = "
select 
    group_concat(if(a.col_n = 1, b.name, null)) as process_name,
    group_concat(if(a.col_n = 2, b.name, null)) as pid
from $_tbl1 a left join autoget_terms b on a.term_id = b.id
where a.command_id = 1 group by a.row_n, a.datetime
";
$_tbl2 = exec_db_temp_start($sql);

// _tbl3: netstat (windows) - IPv4, IPv6
$sql = "
select
    substring_index(group_concat(if(a.col_n = 2, b.name, null)), ':', 1) as address,
    substring_index(group_concat(if(a.col_n = 2, b.name, null)), ':', -1) as port,
    group_concat(if(a.col_n = 4, b.name, null)) as state,
    group_concat(if(a.col_n = 5, b.name, null)) as pid
from $_tbl1 a left join autoget_terms b on a.term_id = b.id
where a.command_id = 2 group by a.row_n, a.datetime
";
$_tbl3 = exec_db_temp_start($sql);

// _tbl4: netstat (linux) - IPv4, IPv6
$sql = "
select
    substring_index(group_concat(if(a.col_n = 2, b.name, null)), ':', 1) as address,
    substring_index(group_concat(if(a.col_n = 4, b.name, null)), ':', -1) as port,
    group_concat(if(col_n = 6, b.name, null)) as state,
    substring_index(group_concat(if(a.col_n = 7, b.name, null)), '/', 1) as pid,
    substring_index(group_concat(if(a.col_n = 7, b.name, null)), '/', -1) as process_name
from $_tbl1 a left join autoget_terms b on a.term_id = b.id
where command_id = 4 group by a.row_n, a.datetime
";
$_tbl4 = exec_db_temp_start($sql);

// _tbl5: step 1
$_tbl5 = "";
if($device['platform'] == "windows") {
    $sql = "
    select
        b.process_name as process_name,
        a.address as address,
        a.port as port,
        a.state as state,
        a.pid as pid
    from $_tbl3 a left join $_tbl2 b on a.pid = b.pid";
} elseif($device['platform'] == "linux")  {
    $sql = "select process_name, address, port, state, pid from $_tbl4";
}
$_tbl5 = exec_db_temp_start($sql);

// _tbl6: step 2
$sql = "select * from $_tbl5 group by port";
$_tbl6 = exec_db_temp_start($sql);

// format: json.datatables
if($format == "json.datatables") {
    $sql = "select * from $_tbl6";
    $rows = exec_db_fetch_all($sql);
    $_rows = array();
    foreach($rows as $row) {
        foreach($row as $k=>$v) {
            if($k != "pid" && strlen($v) < 2) {
                $row[$k] = "Unknown";
            }
        }
        $row = array_merge(array("rowid" => get_hashed_text(implode(",", $row))), $row);
        $_rows[] = $row;
    }
    header("Content-Type: application/json");
    echo json_encode(array("data" => $_rows));
    exit;
}

// make nodes
$nodes = array();
$relations = array();

// add devices to nodes
foreach($devices as $k=>$v) {
    $nodes['dv_' . $k] = $v;
}

// add IPv4 to nodes
$sql = "select address as ip from $_tbl6 group by ip";
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
$sql = "select concat(port, ',', process_name) as pp from $_tbl6";
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
$sql = "select address as ip, concat(port, ',', process_name) as pp, state from $_tbl6";
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
