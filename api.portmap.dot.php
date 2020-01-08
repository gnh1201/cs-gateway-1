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
$nodes = array();
$relations = array();

// get device information
$bind = array(
    "id" => $device_id
);
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$rows = exec_db_fetch($sql, $bind);
foreach($rows as $row) {
    $nodekey = sprintf("dv_%s", $row['id']);
    $nodes[$nodekey] = $row['computer_name'];
}

// 1: tasklist (command_id = 1)
// 2: netstat -anof | findstr -v 127.0.0.1 | findstr -v UDP (command_id = 2)
// 4: netstat -ntp | grep -v 127.0.0.1 | grep -v ::1 (command_id = 4)
$sql = get_bind_to_sql_select("autoget_sheets", false, array(
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

$sql = "
select
    a.device_id as device_id,
    a.command_id as command_id,
    a.pos_y as pos_y,
    a.pos_x as pos_x,
    b.term as term
from $_tbl0 a
    left join autoget_terms b on a.term_id = b.id
";
$_tbl1 = exec_db_temp_start($sql);

// tasklist (windows)
$sql = "
select 
    group_concat(if(pos_x = 1, term, null)) as process_name,
    group_concat(if(pos_x = 2, term, null)) as pid
from $_tbl1
where command_id = 1
group by pos_y, datetime
";
$_tbl2 = exec_db_temp_start($sql);

// netstat (windows) - IPv4, IPv6
$sql = "
select
    substring_index(group_concat(if(pos_x = 2, term, null)), ':', 1) as address,
    substring_index(group_concat(if(pos_x = 2, term, null)), ':', -1) as port,
    group_concat(if(pos_x = 4, term, null)) as state,
    group_concat(if(pos_x = 5, term, null)) as pid
from $_tbl1
where command_id = 2
group by pos_y, datetime
";
$_tbl3 = exec_db_temp_start($sql);

// netstat (linux) - IPv4, IPv6
$sql = "
select
    substring_index(group_concat(if(pos_x = 2, term, null)), ':', 1) as address,
    substring_index(group_concat(if(pos_x = 4, term, null)), ':', -1) as port,
    group_concat(if(pos_x = 6, term, null)) as state,
    substring_index(group_concat(if(pos_x = 7, term, null)), '/', 1) as pid,
    substring_index(group_concat(if(pos_x = 7, term, null)), '/', -1) as process_name
from $_tbl1
where command_id = 4
group by pos_y, datetime
";
$_tbl4 = exec_db_temp_start($sql);

// step 1
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

// add IPv4 to nodes
$sql = "select address as ip from $_tbl6 group by ip";
$rows = exec_db_fetch_all($sql);
foreach($rows as $row) {
    if(strlen($row['ip']) < 2) {
        $row['ip'] = "Unknown";
    }
    if(!in_array($row['ip'], array("0.0.0.0", "Unknown"))) {
        $nodekey = sprintf("ip_%s", substr(get_hashed_text($row['ip']), 0, 8));
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
        $nodekey = sprintf("pp_%s", substr(get_hashed_text($row['pp']), 0, 8));
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
$_relations = array();
foreach($relations as $rel) {
    if(array_key_exists($rel[0], $nodes) && array_key_exists($rel[1], $nodes)) {
        $_relations[] = $rel;
    }
}
$relations = $_relations;

// pass to view
$data = array(
    "nodes" => $nodes,
    "relations" => $relations
);
`
renderView("view_api.portmap.dot", $data);

// close all temporary tables
exec_db_temp_end($_tbl6);
exec_db_temp_end($_tbl5);
exec_db_temp_end($_tbl4);
exec_db_temp_end($_tbl3);
exec_db_temp_end($_tbl2);
exec_db_temp_end($_tbl1);
exec_db_temp_end($_tbl0);
