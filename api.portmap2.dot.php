<?php
loadHelper("string.utils");

$type = get_requested_value("type");
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
    $adjust = "-30m";
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

// get device
$bind = array(
    "id" => $device_id
);
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$device = exec_db_fetch($sql, $bind);

// create table
$tablename = exec_db_table_create(array(
    "device_id" => array("int", 11),
    "process_name" => array("varchar", 255),
    "address" => array("varchar", 255),
    "address_ex" => array("varchar", 255),
    "port" => array("int", 11),
    "port_ex" => array("int", 11),
    "state" => array("varchar", 45),
    "pid" => array("int", 11),
    "flag_ipv6" => array("tinyint", 1),
    "basetime" => array("datetime")
), "autoget_data_portstate", array(
    "suffix" => ".r6",
    "setindex" => array(
        "index_1" => array("device_id", "port"),
        "index_2" => array("basetime")
    )
));

if($mode == "background") {
    $_tablename = "";

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
                        array("and", array("in", "pos_x", array(2, 3, 4, 5))),
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
                left(a.address_ex, length(a.address_ex) - length(a.port_ex) - 1) as address_ex,
                a.port_ex as port_ex,
                a.state as state,
                a.pid as pid
            from (
                select
                    group_concat(if(a.pos_x = 2, a.term, null)) as address,
                    substring_index(group_concat(if(a.pos_x = 2, a.term, null)), ':', -1) as port,
                    group_concat(if(a.pos_x = 3, a.term, null)) as address_ex,
                    substring_index(group_concat(if(a.pos_x = 3, a.term, null)), ':', -1) as port_ex,
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
                a.address_ex as address_ex,
                a.port_ex as port_ex,
                a.state as state,
                a.pid as pid
            from `$_tbl2` a left join `$_tbl1` b on a.pid = b.pid
        ";
        $_tbl3 = exec_db_temp_start($sql);
        $_tablename = $_tbl3;
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
                left(a.address_ex, length(a.address_ex) - length(a.port_ex) - 1) as address_ex,
                a.port_ex as port_ex,
                a.state as state,
                a.pid as pid,
                a.process_name as process_name
            from (
                select
                    group_concat(if(a.pos_x = 4, a.term, null)) as address,
                    substring_index(group_concat(if(a.pos_x = 4, a.term, null)), ':', -1) as port,
                    group_concat(if(a.pos_x = 5, a.term, null)) as address_ex,
                    substring_index(group_concat(if(a.pos_x = 5, a.term, null)), ':', -1) as port_ex,
                    group_concat(if(a.pos_x = 6, a.term, null)) as state,
                    substring_index(group_concat(if(a.pos_x = 7, a.term, null)), '/', 1) as pid,
                    substring_index(group_concat(if(a.pos_x = 7, a.term, null)), '/', -1) as process_name
                from $_tbl0 a
                where command_id = 4
                group by a.pos_y, a.datetime
            ) a
        ";
        $_tbl1 = exec_db_temp_start($sql);
        $_tablename = $_tbl1;
    }

    // detect IPv6
    $sql = "select a.*, IF(INSTR(a.address, ':') > 0 or INSTR(a.address_ex, ':') > 0, 1, 0) as flag_ipv6 from $_tablename a";
    $rows = exec_db_fetch_all($sql);

    $bulkid = exec_db_bulk_start();
    foreach($rows as $row) {
        $process_name = trim($row['process_name']);
        if(empty($process_name) || $process_name == '-') {
            $process_name = "Unknown";
        }

        $bind = array(
            "device_id" => $device_id,
            "process_name" => $process_name,
            "address" => $row['address'],
            "address_ex" => $row['address_ex'],
            "port" => $row['port'],
            "port_ex" => $row['port_ex'],
            "state" => $row['state'],
            "pid" => $row['pid'],
            "flag_ipv6" => $row['flag_ipv6'],
            "basetime" => $end_dt
        );
        exec_db_bulk_push($bulkid, $bind);
    }
    exec_db_bulk_end($bulkid, $tablename, array("device_id", "process_name", "address", "address_ex", "port", "port_ex", "state", "pid", "flag_ipv6", "basetime"));

    $data['success'] = true;
    header("Content-Type: application/json");
    echo json_encode($data);
} else {
    $bind = false;
    $sql = get_bind_to_sql_select($tablename, $bind, array(
        "setwheres" => array(
            array("and", array("eq", "device_id", $device_id)),
            array("and", array("lte", "basetime", $end_dt)),
            array("and", array("gte", "basetime", $start_dt)),
            array("and", array("in", "state", array("ESTABLISHED", "LISTENING", "LISTEN")))
        )
    ));
    $_tbl5 = exec_db_temp_start($sql, $bind)

    // # About port range
    //   * 0:1023 = well-known port
    //   * 1024:49151 = registered port
    //   * 49152:65535 = dynamic port

    $sql = "
        select
            a.device_id as device_id,
            if(a.process_name is null or a.process_name = '' or a.process_name = '-', 'Unknown', a.process_name) as process_name,
            a.address_ex as address,
            a.port as port,
            a.state as state,
            a.flag_ipv6 as flag_ipv6,
            a.pid as pid
        from $_tbl5 a where a.port < 49152
    ";
    $_tbl6 = exec_db_temp_start($sql);

    if($type == "datatables") {
        //$sql = "select * from $_tbl6 a where a.state in ('ESTABLISHED') group by port, pid, flag_ipv6";
        $sql = "select *, count(distinct pid) as cnt from $_tbl6 a where a.state in ('ESTABLISHED') group by port, address";
        $rows = exec_db_fetch_all($sql);
        $_rows = array();
        foreach($rows as $row) {
            $hostname = $row['address'];

            $__bind = array(
                "hostip" => $hostname
            );
            $__sql = get_bind_to_sql_select("autoget_data_hosts.zabbix", $__bind);
            $__rows = exec_db_fetch_all($__sql, $__bind);
            foreach($__rows as $__row) {
                    $hostname = str_replace(array(" ", "-"), "_", $__row['hostname']);
                    break;
            }
            
            $row['hostname'] = $hostname;

            $rowid_values = array($row['device_id'], $row['process_name'], $row['address'], $row['hostname'], $row['cnt'], $row['port'], $row['pid']);
            //$rowid_values = array($row['device_id'], $row['process_name'], $hostname, $row['port'], $row['state'], $row['pid']);
            $rowid = get_hashed_text(implode(",", $rowid_values));
            $row = array_merge(array("rowid" => $rowid), $row);
            $_rows[] = $row;
        }
        $rows = $_rows;

        $data['data'] = $rows;
        header("Content-Type: application/json");
        echo json_encode($data);
    }

    if($type == "datatables.ipversion") {
        //$sql = "select * from $_tbl6 a where a.state in ('LISTENING', 'LISTEN') group by port, pid, flag_ipv6";
        $sql = "select *, if(a.flag_ipv6 = 1, 'TCP6', 'TCP4') as protocol, count(a.port) as cnt from $_tbl6 a where a.state in ('LISTENING', 'LISTEN') group by a.port, a.flag_ipv6";
        $rows = exec_db_fetch_all($sql);
        $_rows = array();
        foreach($rows as $row) {
            $rowid_values = array($row['device_id'], $row['process_name'], $row['port'], $row['protocol'], $row['pid']);
            $rowid = get_hashed_text(implode(",", $rowid_values));
            $row = array_merge(array("rowid" => $rowid), $row);
            $_rows[] = $row;
        }
        $rows = $_rows;

        $data['data'] = $rows;
        header("Content-Type: application/json");
        echo json_encode($data);
    }

    elseif($type == "graphviz") {
        $nodes = array();
        $relations = array();
        $nodestyles = array();
        
        $computer_name = str_replace(array(" ", "-"), "_", $device['computer_name']);

        $nodes[] = $computer_name;
        $nodestyles[$computer_name] = array("fontcolor" => "white", "color" => "red");
        
        $sql = "select a.port as port, a.address as address, count(distinct a.pid) as cnt from $_tbl6 a where a.state in ('ESTABLISHED') group by a.port, a.address";
        $rows = exec_db_fetch_all($sql);
        foreach($rows as $row) {
            if(!in_array($row['port'], $nodes)) {
                $nodes[] = $row['port'];
                $nodestyles[$row['port']] = array("fontcolor" => "white", "color" => "green");
                $relations[] = array($computer_name, $row['port'], "");
            }

            if(!in_array($row['address'], $nodes)) {
                $nodes[] = $row['address'];
            }
            
            $relations[] = array($row['port'], $row['address'], $row['cnt']);
        }

/*
        $sql = "select a.port as port, group_concat(distinct a.address) as addresses, count(a.port) as cnt from $_tbl6 a where a.state in ('ESTABLISHED') group by a.port";
        $rows = exec_db_fetch_all($sql);
        foreach($rows as $row) {
            $addresses = explode(",", $row['addresses']);
            $relations[] = array($computer_name, $row['port'], $row['cnt']);
            $nodes[] = $row['port'];
            $nodestyles[$row['port']] = array("fontcolor" => "white", "color" => "green");
            foreach($addresses as $address) {
                $hostname = $address;

                $_bind = array(
                    "hostip" => $hostname
                );
                $_sql = get_bind_to_sql_select("autoget_data_hosts.zabbix", $_bind);
                $_rows = exec_db_fetch_all($_sql, $_bind);
                foreach($_rows as $_row) {
                        $hostname = str_replace(array(" ", "-"), "_", $_row['hostname']);
                        break;
                }

                $connected = 0;
                foreach($relations as $k=>$rel) {
                    if($rel[0] == $row['port'] && $rel[1] == $hostname) {
                        $connected++;
                    }
                }

                if($connected == 0) {
                    $relations[] = array($row['port'], $hostname, "");
                }

                $nodes[] = $hostname;
            }
        }
*/

        $data = array(
            "relations" => $relations,
            "nodes" => $nodes,
            "nodestyles" => $nodestyles
        );

        header("Content-Type: text/plain");
        renderView("view_api.portmap2.dot", $data);
    }
    
    elseif($type == "graphviz.ipversion") {
        $nodes = array();
        $relations = array();
        $nodestyles = array();

        $computer_name = str_replace(array(" ", "-"), "_", $device['computer_name']);
        $nodes[] = $computer_name;
        $nodestyles[$computer_name] = array("fontcolor" => "white", "color" => "red");
        $nodes[] = "TCP6";
        $nodestyles['TCP6'] = array("color" => "yellow");
        $nodes[] = "TCP";
        $nodestyles['TCP'] = array("color" => "yellow");
        $relations[] = array($computer_name, "TCP6", "");
        $relations[] = array($computer_name, "TCP", "");

        $sql = "select a.port as port, a.process_name as process_name, group_concat(distinct a.address) as addresses, count(a.port) as cnt from $_tbl6 a where a.state in ('LISTENING', 'LISTEN') group by a.port, a.flag_ipv6";
        $rows = exec_db_fetch_all($sql);
        foreach($rows as $row) {
            $addresses = explode(",", $row['addresses']);
            //$nodes[] = $row['port'];
            //$nodestyles[$row['port']] = array("fontcolor" => "white", "color" => "green");

            $process_name = pathinfo(str_replace(array(" ", "-"), "_", $row['process_name']), PATHINFO_FILENAME);
            //$nodes[] = $process_name;
            
            $nodekey = sprintf("%s:%s", $row['port'], $process_name);
            $nodes[] = $nodekey;
            $nodestyles[$nodekey] = array("fontcolor" => "white", "color" => "green");

            $ipversion = "TCP";
            foreach($addresses as $address) {
                $ipv6_seperated = explode(":", $address);
                if(count($ipv6_seperated) > 1) {
                    $ipversion = "TCP6";
                    break;
                }
            }

            //$relations[] = array($ipversion, $row['port'], "");
            //$relations[] = array($row['port'], $process_name, "");
            $relations[] = array($ipversion, $nodekey, "");
        }

        $data = array(
            "relations" => $relations,
            "nodes" => $nodes,
            "nodestyles" => $nodestyles
        );

        header("Content-Type: text/plain");
        renderView("view_api.portmap2.dot", $data);
    }

    // close all temporary tables
    exec_db_temp_end($_tbl5);
}
