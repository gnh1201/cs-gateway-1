<?php
loadHelper("string.utils");

$device_id = get_requested_value("device_id");
$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");
$adjust = get_requested_value("adjust");
$mode = get_requested_value("mode");

if(empty($device_id)) {
    set_error("device_id is required");
}

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($adjust)) {
    $adjust = "-1h";
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

if($mode == "background") {
    // get device information
    $bind = array(
        "id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind);
    $device = exec_db_fetch($sql, $bind);

    // get number of cores
    $_core = 0;
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_data_cpucore.zabbix", $bind);
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $_core += get_int($row['core']);
    }

    // get cpu usage
    if($device['platform'] == "windows") {
        $sql = get_bind_to_sql_select("autoget_sheets", false, array(
            "setwheres" => array(
                array("and", array("eq", "device_id", $device_id)),
                array("and", array("in", "pos_y", array(2, 3))),
                array("and", array("eq", "command_id", 47)),
                array("and", array("lte", "datetime", $end_dt)),
                array("and", array("gte", "datetime", $start_dt))
            )
        ));
        $_tbl1 = exec_db_temp_start($sql);

        $sql = "
        select a.pos_y as pos_y, if(a.pos_y = 2, ((a.pos_x + 1) / 6), (a.pos_x - 2)) as pos_x, a.term as term, a.datetime as datetime
            from $_tbl1 a where (pos_y = 2 and mod(pos_x + 1, 6) = 0) or (pos_y = 3 and pos_x - 2 > 0)
        ";
        $_tbl2 = exec_db_temp_start($sql);

        $sql = "select group_concat(if(pos_y = 2, term, null)) as name, avg(if(pos_y = 3, term, null)) as value, datetime from $_tbl2 group by pos_x, datetime";
        $_tbl3 = exec_db_temp_start($sql, false);

        $delimiters = array(" ", "(", ")");
        $stopwords = array("Idle", "_Total", "typeperf");
        $stopwords = array_merge(get_tokenized_text($device['computer_name'], $delimiters), $stopwords);

        $sql = sprintf("select name, round(avg(value) / {$_core}, 2) as value, datetime from $_tbl3 where name not in ('%s') group by name", implode("', '", $stopwords));
        $rows = exec_db_fetch_all($sql);
    } elseif($device['platform'] == "linux") {
        $sql = get_bind_to_sql_select("autoget_sheets", false, array(
            "setwheres" => array(
                array("and", array("eq", "device_id", $device_id)),
                array("and", array("in", "pos_x", array(2, 3, 11))),
                array("and", array("gt", "pos_y", 1)),
                array("and", array("eq", "command_id", 3)),
                array("and", array("lte", "datetime", $end_dt)),
                array("and", array("gte", "datetime", $start_dt))
            )
        ));
        $_tbl1 = exec_db_temp_start($sql);

        $sql = sprintf("
            select concat(c.name, '#', c.pid) as name, round(avg(c.value) / %s, 2) as value, c.datetime as datetime from (
                select
                    ifnull(group_concat(if(a.pos_x = 11, a.term, null)), 'Unknown') as name,
                    group_concat(if(a.pos_x = 3, a.term, null)) as value,
                    group_concat(if(a.pos_x = 2, a.term, null)) as pid,
                    a.datetime as datetime
                from $_tbl1 a
                group by a.pos_y, a.datetime
            ) c group by name
        ", $_core);
        
        if($device_id == 18) {
            write_debug_log($sql);
        }
        
        $rows = exec_db_fetch_all($sql);
    }

    // create table
    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "name" => array("varchar", 255),
        "value" => array("float", "5,2"),
        "basetime" => array("datetime")
    ), "autoget_data_cputime", array(
        "index_1" => array("device_id", "name", "basetime")
    ));
    
    // insert selected rows
    $bulkid = exec_db_bulk_start();
    foreach($rows as $row) {
        $bind = array(
            "device_id" => $device_id,
            "name" => $row['name'],
            "value" => $row['value'],
            "basetime" => $end_dt
        );
        //$sql = get_bind_to_sql_insert($tablename, $bind);
        //exec_db_query($sql, $bind);
        exec_db_bulk_push($bulkid, $bind);
    }
    exec_db_bulk_end($bulkid, $tablename, array("device_id", "name", "value", "basetime"));

    $data['success'] = true;
} else {
    $bind = array(
        "device_id" => $device_id,
        "start_dt" => $start_dt,
        "end_dt" => $end_dt
    );
    $sql = "
    select name, concat(round(avg(value), 2), '%') as value from autoget_data_cputime
        where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
        group by name
    ";
    $rows = exec_db_fetch_all($sql, $bind);
    
    $data['success'] = true;
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);
