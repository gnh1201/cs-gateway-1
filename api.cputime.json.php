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
    $sql = get_bind_to_sql_select("autoget_data_cpucore", $bind);
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $_core += get_int($row['core']);
    }

    /*
    // if 0(zero) core, set average cores of all computers
    if(!($_core > 0)) {
        $sql = "select round(avg(core), 0) as core from autoget_data_cpucore";
        $rows = exec_db_fetch_all($sql);
        foreach($rows as $row) {
            $_core += get_int($row['core']);
        }
    }
    */

    // get cpu usage
    $sql = get_bind_to_sql_select("autoget_sheets", false, array(
        "setwheres" => array(
            array("and", array("eq", "device_id", $device_id)),
            array("and", array("in", "pos_y", array(2, 3))),
            array("and", array("eq", "command_id", 47)),
            array("and", array("lte", "datetime", $end_dt)),
            array("and", array("gte", "datetime", $start_dt))
        )
    ));
    $_tbl1 = exec_db_temp_start($sql, false);

    $sql = "
    select a.pos_y as pos_y, if(a.pos_y = 2, ((a.pos_x + 1) / 6), (a.pos_x - 2)) as pos_x, b.term as term, a.datetime as datetime
        from $_tbl1 a left join autoget_terms b on a.term_id = b.id
            where (pos_y = 2 and mod(pos_x + 1, 6) = 0) or (pos_y = 3 and pos_x - 2 > 0)
    ";
    $_tbl2 = exec_db_temp_start($sql, false);

    $sql = "select group_concat(if(pos_y = 2, term, null)) as name, avg(if(pos_y = 3, term, null)) as value, datetime from $_tbl2 group by pos_x, datetime";
    $_tbl3 = exec_db_temp_start($sql, false);

    $delimiters = array(" ", "(", ")");
    $stopwords = array("Idle", "_Total", "typeperf");
    $stopwords = array_merge(get_tokenized_text($device['computer_name'], $delimiters), $stopwords);

    $sql = sprintf("select name, round(avg(value) / {$_core}, 2) as value from $_tbl3 where name not in ('%s') group by name", implode("', '", $stopwords));
    $rows = exec_db_fetch_all($sql);

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
    foreach($rows as $row) {
        $bind = array(
            "device_id" => $device_id,
            "name" => $row['name'],
            "value" => $row['value'],
            "basetime" => $end_dt,
        );
        $sql = get_bind_to_sql_insert($tablename, $bind);
        exec_db_query($sql, $bind);
    }

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

exec_db_temp_end($_tbl3);
exec_db_temp_end($_tbl2);
exec_db_temp_end($_tbl1);

