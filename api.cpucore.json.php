<?php
$device_id = get_requested_value("device_id");
$end_dt = get_requested_value("end_dt");
$start_dt = get_requested_value("start_dt");
$adjust = get_requested_value("adjust");
$mode = get_requested_value("mode");

$now_dt = get_current_datetime();

if(empty($device_id)) {
    set_error("device_id is required");
    show_errors();
}

if(empty($adjust)) {
    $adjust = "-10m";
}

if(empty($end_dt)) {
    $end_dt = $now_dt;
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
    // get device
    $bind = array(
        "id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind);
    $device = exec_db_fetch($sql, $bind);

    $rows = array();
    if($device['platform'] == "windows") {
        $bind = array(
            "device_id" => $device_id,
            "command_id" => 50
        );
        $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
            "setwheres" => array(
                array("and", array("lte", "datetime", $end_dt)),
                array("and", array("gte", "datetime", $start_dt))
            )
        ));
        $_tbl0 = exec_db_temp_start($sql, $bind);

        $sql = "select a.device_id as device_id, max(b.term) as core from $_tbl0 a, autoget_terms b where a.term_id = b.id group by a.device_id";
        $rows = exec_db_fetch_all($sql);
    } elseif($device['platform'] == "linux") {
        $bind = array(
            "device_id" => $device_id,
            "command_id" => 53
        );
        $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
            "setwheres" => array(
                array("and", array("lte", "datetime", $end_dt)),
                array("and", array("gte", "datetime", $start_dt))
            )
        ));
        $_tbl0 = exec_db_temp_start($sql, $bind);
        
        $sql = "select a.device_id as device_id, max(b.term) as core from $_tbl0 a, autoget_terms b where a.term_id = b.id group by a.device_id";
        $rows = exec_db_fetch_all($sql);
    }

    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "core" => array("int", 3),
        "basetime" => array("datetime")
    ), "autoget_data_cpucore", array(
        "setunique" => array(
            "unique_1" => array("device_id")
        )
    ));

    foreach($rows as $row) {
        $core = get_int($row['core']);
        if($core > 0) {
            $bind = array(
                "device_id" => $row['device_id'],
                "core" => $core,
                "basetime" => $now_dt
            );
            $sql = get_bind_to_sql_insert($tablename, $bind, array(
                "setkeys" => array("device_id")
            ));
            echo $sql;
            exec_db_query($sql, $bind);
        }
    }

    $data['success'] = true;
} else {
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_data_cpucore", $bind, array(
        "setwheres" => array(
            array("and", array("lte", "basetime", $end_dt)),
            array("and", array("gte", "basetime", $start_dt))
        )
    ));
    $rows = exec_db_query($sql, $bind);
    
    $data['success'] = true;
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);
