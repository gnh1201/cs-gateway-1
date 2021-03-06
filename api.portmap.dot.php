<?php
loadHelper("string.utils");

$format = get_requested_value("format");
$device_id = get_requested_value("device_id");
$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");
$adjust = get_requested_value("adjust");
$mode = get_requested_value("mode");

if(empty($device_id)) {
    set_error("device_id is required");
    show_errors();
}

if(empty($adjust)) {
    $adjust = "-20m";
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

$data = array(
    "success" => false
);

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

if($mode == "background") {
    $tablename = "";

    if($device['platform'] == "windows") {
        // 1: tasklist (command_id = 1)
        // 2: netstat -anof | findstr -v 127.0.0.1 | findstr -v UDP (command_id = 2)
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
                group_concat(if(pos_x = 1, a.term, null)) as process_name,
                group_concat(if(pos_x = 2, a.term, null)) as pid
            from $_tbl0 a
            where a.command_id = 1
            group by a.pos_y, a.datetime
        ";
        $_tbl1 = exec_db_temp_start($sql);
        
        // netstat (windows, TCP only)
        $sql = "
            select
                left(a.address, length(a.address) - length(a.port) - 1) as address,
                a.port as port,
                a.state as state,
                a.pid as pid
            from (
                select
                    group_concat(if(a.pos_x = 2, a.term, null)) as address,
                    substring_index(group_concat(if(a.pos_x = 2, a.term, null)), ':', -1) as port,
                    group_concat(if(a.pos_x = 4, a.term, null)) as state,
                    group_concat(if(a.pos_x = 5, a.term, null)) as pid
                from $_tbl0 a
                where a.command_id = 2
                group by a.pos_y, a.datetime
            ) a
        ";
        $_tbl2 = exec_db_temp_start($sql);

        // join tasklist and netstat (windows)
        $sql = "
            select
                b.process_name as process_name,
                a.address as address,
                a.port as port,
                a.state as state,
                a.pid as pid
            from `$_tbl2` a left join `$_tbl1` b on a.pid = b.pid
        ";
        $_tbl3 = exec_db_temp_start($sql);
        $tablename = $_tbl3;
    } elseif($device['platform'] == "linux") {
        // 4: netstat -ntp | grep -v 127.0.0.1 | grep -v ::1 (command_id = 4)
        $bind = false;
        $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
            "setwheres" => array(
                array("and", array("eq", "command_id", 4)),
                //array("and", array("in", "pos_x", array(4, 6, 7))),
                array("and", array("gt", "pos_y", 2)),
                array("and", array("gte", "datetime", $start_dt)),
                array("and", array("lte", "datetime", $end_dt)),
                array("and", array("eq", "device_id", $device_id))
            )
        ));
        $_tbl0 = exec_db_temp_start($sql);

        // netstat (linux)
        $sql = "
            select
                left(a.address, length(a.address) - length(a.port) - 1) as address,
                a.port as port,
                a.state as state,
                a.pid as pid,
                a.process_name as process_name
            from (
                select
                    group_concat(if(a.pos_x = 4, a.term, null)) as address,
                    substring_index(group_concat(if(a.pos_x = 4, a.term, null)), ':', -1) as port,
                    group_concat(if(a.pos_x = 6, a.term, null)) as state,
                    substring_index(group_concat(if(a.pos_x = 7, a.term, null)), '/', 1) as pid,
                    substring_index(group_concat(if(a.pos_x = 7, a.term, null)), '/', -1) as process_name
                from $_tbl0 a
                where command_id = 4
                group by a.pos_y, a.datetime
            ) a
        ";
        $_tbl1 = exec_db_temp_start($sql);
        $tablename = $_tbl1;
    }

    // group by port
    $sql = "select * from $tablename group by port";
    $rows = exec_db_fetch_all($sql);

    // create table
    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "process_name" => array("varchar", 255),
        "address" => array("varchar", 255),
        "port" => array("int", 11),
        "state" => array("varchar", 45),
        "pid" => array("int", 11),
        "basetime" => array("datetime")
    ), "autoget_data_portstate", array(
        "setindex" => array(
            "index_1" => array("device_id", "port", "basetime")
        )
    ));

    $bulkid = exec_db_bulk_start();
    foreach($rows as $row) {
        $bind = array(
            "device_id" => $device_id,
            "process_name" => $row['process_name'],
            "address" => $row['address'],
            "port" => $row['port'],
            "state" => $row['state'],
            "pid" => $row['pid'],
            "basetime" => $end_dt
        );
        //$sql = get_bind_to_sql_insert($tablename, $bind);
        //exec_db_query($sql, $bind);
        exec_db_bulk_push($bulkid, $bind);
    }
    exec_db_bulk_end($bulkid, $tablename, array("device_id", "process_name", "address", "port", "state", "pid", "basetime"));

    $data['success'] = true;
    header("Content-Type: application/json");
    echo json_encode($data);
} else {
    $bind = array(
        "device_id" => $device_id,
        "start_dt" => $start_dt,
        "end_dt" => $end_dt
    );
    $sql = "
        select * from autoget_data_portstate
            where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
            group by port
    ";
    write_common_log(get_db_binded_sql($sql, $bind));

    $_tbl5 = exec_db_temp_start($sql, $bind);

    if($format == "json.datatables") {
        $sql = "select * from $_tbl5";
        $rows = exec_db_fetch_all($sql);
        $_rows = array();
        foreach($rows as $row) {
            foreach($row as $k=>$v) {
                if(empty($v)) {
                    $row[$k] = "Unknown";
                }
            }
            $rowid_values = array($row['device_id'], $row['process_name'], $row['address'], $row['port'], $row['state'], $row['pid']);
            $rowid = get_hashed_text(implode(",", $rowid_values));
            $row = array_merge(array("rowid" => $rowid), $row);
            $_rows[] = $row;
        }
        $rows = $_rows;

        $data['data'] = $rows;
        header("Content-Type: application/json");
        echo json_encode($data);
    } else {
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

        header("Content-Type: text/plain");
        renderView("view_api.portmap.dot", $data);
    }

    // close all temporary tables
    exec_db_temp_end($_tbl5);
}
