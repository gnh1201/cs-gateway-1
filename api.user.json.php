<?php
loadHelper("itsm.api");
loadHelper("string.utils");

$device_id = get_requested_value("device_id");
$end_dt = get_requested_value("end_dt");
$start_dt = get_requested_value("start_dt");
$adjust = get_requested_value("end_dt");
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

// get device information
$bind = array(
    "id" => $device_id
);
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$device = exec_db_fetch($sql, $bind);

// make table
$tablename = exec_db_table_create(array(
    "device_id" => array("int", 11),
    "username" => array("varchar", 255),
    "disabled" => array("tinyint", 1),
    "description" => array("text"),
    "basetime" => array("datetime")
), "autoget_data_user", array(
    "suffix" => ".r2",
    "setindex" => array(
        "index_1" => array("datetime")
    ),
    "setunique" => array(
        "unique_1" => array("device_id", "username")
    )
));

if($mode == "background") {
    // get disabled/enabled users
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
        "setwheres" => array(
            array("and", array(
                array("or", array(
                    array("and", array("eq", "command_id", 51)),
                    array("and", array("gt", "pos_y", 1))
                )),
                array("or", array(
                    array("and", array("eq", "command_id", 52))
                ))
            )),
            array("and", array("lte", "datetime", $end_dt)),
            array("and", array("gte", "datetime", $start_dt))
        )
    ));
    $_tbl0 = exec_db_temp_start($sql, $bind);
    
    $sql = "
        select
            c.device_id as device_id,
            max(c.disabled) as disabled,
            c.username as username
        from (
            select
                a.device_id as device_id,
                group_concat(if(a.pos_x=1, a.term, null)) as disabled,
                group_concat(if(a.pos_x=2, a.term, null)) as username
            from $_tbl0 a
            group by a.pos_y, a.datetime
        ) c group by c.username
    ";
    $_tbl1 = exec_db_temp_start($sql);
    
    // initialize
    $_tbl2 = false;
    $_tbl3 = false;

    // get user descriptions
    if($device['platform'] == "linux") {

        $bind = array(
            "device_id" => $device_id
        );
        $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
            "setwheres" => array(
                array("and", array("eq", "command_id", 59)),
                array("and", array("lte", "datetime", $end_dt)),
                array("and", array("gte", "datetime", $start_dt))
            )
        ));
        $_tbl2 = exec_db_temp_start($sql, $bind);

        $sql = "
            select
                c.username as username,
                c.description as description
            from (
                select
                    a.device_id as device_id,
                    group_concat(if(a.pos_x = 1, a.term, null)) as username,
                    group_concat(if(a.pos_x >= 2, a.term, null) order by a.pos_x asc separator ' ') as description
                from $_tbl2 a
                group by a.pos_y, a.datetime
            ) c group by c.username
        ";
        $_tbl3 = exec_db_temp_start($sql);

    } elseif($device['platform'] == "windows") {
        
        $bind = array(
            "device_id" => $device_id
        );
        $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
            "setwheres" => array(
                array("and", array("eq", "command_id", 58)),
                array("and", array("gt", "pos_y", 1)),
                array("and", array("lte", "datetime", $end_dt)),
                array("and", array("gte", "datetime", $start_dt))
            )
        ));
        $_tbl2 = exec_db_temp_start($sql, $bind);
        
        $sql = "
            select
                a.device_id as device_id, 
                substring_index(group_concat(a.term order by a.pos_x asc separator ' '), ' ', -1) as username,
                replace(group_concat(a.term order by a.pos_x asc separator ' '), substring_index(group_concat(a.term order by a.pos_x asc separator ' '), ' ', -1), '') as description
            from $_tbl2 a
            group by a.pos_y, a.datetime
        ";
        $_tbl3 = exec_db_temp_start($sql);
    }
    
    $sql = "select * from $_tbl1 a left join $_tbl3 b on a.username = b.username";
    $rows = exec_db_fetch_all($sql);
    foreach($rows as $row) {
        if(!empty($row['username'])) {
            $disabled = 0;
            $terms = get_tokenized_text(strtolower($row['disabled']), array(" ", "/"));

            if($device['platform'] == "windows" && in_array("true", $terms)) {
                $disabled = 1;
            }

            if($device['platform'] == "linux" && !in_array("bash", $terms)) {
                $disabled = 1;
            }

            $bind = array(
                "device_id" => $device_id,
                "username" => $row['username'],
                "disabled" => $disabled,
                "description" => $row['description'],
                "basetime" => $now_dt
            );
            $sql = get_bind_to_sql_insert($tablename, $bind, array(
                "setkeys" => array("device_id", "username")
            ));
            exec_db_query($sql, $bind);
        }
    }

    $data['success'] = true;
} elseif($mode == "itsm.import") {
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select($tablename, $bind);
    $rows = exec_db_fetch_all($sql, $bind);

    $assetid = $device['itsm_assetid'];
    foreach($rows as $row) {
        itsm_add_data("credentials", array(
            "clientid" => 1,
            "assetid" => $assetid,
            "type" => ($row['disabled'] == '1' ? 'Disabled' : 'Enabled'),
            "username" => $row['username'],
            "password" => "",
        ), array(
            1 => "",
            2 => $row['description']
        ));
    }

    $data['success'] = true;
} else {
    // get data
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select($tablename, $bind, array(
        "setwheres" => array(
            array("and", array("lte", "basetime", $end_dt)),
            array("and", array("gte", "basetime", $start_dt))
        ),
        "setgroups" => array("device_id")
    ));
    $rows = exec_db_fetch_all($sql, $bind);

    $data['success'] = true;
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);

