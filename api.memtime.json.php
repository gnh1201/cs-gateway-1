<?php
loadHelper("string.utils");

$end_dt = get_requested_value("end_dt");
$start_dt = get_requested_value("start_dt");
$adjust = get_requested_value("adjust");
$device_id = get_requested_value("device_id");
$mode = get_requested_value("mode");

$data = array(
    "success" => false
);

if(empty($adjust)) {
    $adjust = "-1h";
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

// get device
$bind = array(
    "id" => $device_id
);
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$device = exec_db_fetch($sql, $bind);

if($mode == "background") {
    // get total of memory
    $_total = 0;
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_data_memtotal.zabbix", $bind);
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $_total = $row['total'];
    }

    if($device['platform'] == "windows") {
        // get memory usage by process (from tasklist)
        $sql = get_bind_to_sql_select("autoget_sheets", false, array(
            "setwheres" => array(
                array("and", array("eq", "command_id", 1)),
                array("and", array("eq", "device_id", $device_id)),
                array("and", array("gt", "pos_y", 3)),
                array("and", array("in", "pos_x", array(1, 5))),
                array("and", array("gte", "datetime", $start_dt)),
                array("and", array("lte", "datetime", $end_dt))
            )
        ));
        $_tbl2 = exec_db_temp_start($sql);

        $sql = "
            select
                group_concat(if(pos_x = 1, a.term, null)) as name,
                avg(if(pos_x = 5, replace(a.term, ',', ''), null)) as _value, 
                round((max(if(pos_x = 5, replace(a.term, ',', ''), null)) / {$_total} ) * 100, 5) as value,
                a.datetime as datetime
            from $_tbl2 a
            group by a.pos_y, a.datetime
        ";
        $_tbl3 = exec_db_temp_start($sql);

        //$sql = "select name, concat(avg(_value), 'KB') as _value, concat(avg(value), '%') as value from $_tbl3 group by name";
        $sql = "select name, avg(_value) as _value, avg(value) as value from $_tbl3 where name not in ('typeperf') group by name";
        $rows = exec_db_fetch_all($sql);
    } elseif($device['platform'] == "linux") {
        // get memory usage by process (from tasklist)
        $sql = get_bind_to_sql_select("autoget_sheets", false, array(
            "setwheres" => array(
                array("and", array("eq", "command_id", 3)),
                array("and", array("eq", "device_id", $device_id)),
                array("and", array("gt", "pos_y", 1)),
                array("and", array("in", "pos_x", array(2, 4, 11))),
                array("and", array("gte", "datetime", $start_dt)),
                array("and", array("lte", "datetime", $end_dt))
            )
        ));
        $_tbl2 = exec_db_temp_start($sql);

        $sql = "
            select
                concat(c.name, '#', c.pid) as name,
                avg(c.value) as value,
                ({$_total} * (avg(c.value) / 100)) as _value,
                c.datetime as datetime
            from (
                select
                    ifnull(group_concat(if(a.pos_x = 11, a.term, null)), 'Unknown') as name,
                    group_concat(if(a.pos_x = 2, a.term, null)) as pid,
                    group_concat(if(a.pos_x = 4, a.term, null)) as value,
                    a.datetime as datetime
                from $_tbl2 a
                group by a.pos_y, a.datetime
            ) c group by name
        ";
        $rows = exec_db_fetch_all($sql);
    }
    
    // create table
    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "name" => array("varchar", 255),
        "_value" => array("int", "45"),
        "value" => array("float", "5,2"),
        "basetime" => array("datetime")
    ), "autoget_data_memtime", array(
        "suffix" => sprintf(".%s", date("Ymd")),
        "setindex" => array(
            "index_1" => array("device_id"),
            "index_2" => array("basetime")
        )
    ));
    
    // insert selected rows
    $bulkid = exec_db_bulk_start();
    foreach($rows as $row) {
        $bind = array(
            "device_id" => $device_id,
            "name" => $row['name'],
            "_value" => $row['_value'],
            "value" => $row['value'],
            "basetime" => $end_dt,
        );
        //$sql = get_bind_to_sql_insert($tablename, $bind);
        //exec_db_query($sql, $bind);
        exec_db_bulk_push($bulkid, $bind);
    }
    exec_db_bulk_end($bulkid, $tablename, array("device_id", "name", "_value", "value", "basetime"));

    $data['success'] = true;
} else {
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_data_memtime", $bind, array(
        "setwheres"  => array(
            array("and", array("gte", "basetime", $start_dt)),
            array("and", array("lte", "basetime", $end_dt)),
        ),
        "setcreatedtime" => array(
            "end" => $end_dt,
            "start" => get_current_datetime(array("now" => $end_dt, "adjust" => "-24h"))
        )
    ));
    $sql = "select name, concat(format(avg(_value) / 1024, 2), 'MB') as _value, concat(round(avg(value), 2), '%') as value from ($sql) a group by name";
    $rows = exec_db_fetch_all($sql, $bind);

    $data['success'] = true;
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);
