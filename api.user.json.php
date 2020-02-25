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

if($mode == "background") {
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

    //$rows = exec_db_fetch_all($sql, $bind);
    //var_dump($rows);

    $_tbl0 = exec_db_temp_start($sql, $bind);
    $sql = "
        select
            c.device_id as device_id,
            max(c.disabled) as disabled,
            c.username as username
        from (
            select
                a.device_id as device_id,
                group_concat(if(a.pos_x=1, b.term, null)) as disabled,
                group_concat(if(a.pos_x=2, b.term, null)) as username
            from $_tbl0 a left join autoget_terms b on a.term_id = b.id
            group by a.pos_y, a.datetime
        ) c group by c.username
    ";
    $rows = exec_db_fetch_all($sql, $bind);

    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "username" => array("varchar", 255),
        "disabled" => array("tinyint", 1),
        "basetime" => array("datetime")
    ), "autoget_data_user", array(
        "setindex" => array(
            "index_1" => array("device_id", "datetime")
        )
    ));

    foreach($rows as $row) {
        if(!empty($row['username'])) {
            $disabled = 0;
            $terms = get_tokenized_text(strtolower($row['disabled']), array(" ", "/"));

            if(in_array("true", $terms)) {
                $disabled = 1;
            }

            //if($disabled == 0 && !in_array("bash", $terms)) {
            //    $disabled = 1;
            //}

            $bind = array(
                "device_id" => $device_id,
                "username" => $row['username'],
                "disabled" => $disabled,
                "basetime" => $now_dt
            );
            $sql = get_bind_to_sql_insert($tablename, $bind);
            exec_db_query($sql, $bind);
        }
    }

    // do import

    // get device UUID
    $device_uuid  = "";
    $bind = array(
        "id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind);
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $device_uuid = $row['uuid'];
    }

    // get asset/client ID by device UUID
    $asset_id = 0;
    $client_id = 0;
    $assets = itsm_get_data("assets");
    foreach($assets as $asset) {
        $_device_uuid = get_property_value("102", $asset->customfields, "");
        if($device_uuid == $_device_uuid) {
            $asset_id = $asset->id;
            $client_id = $asset->clientid;
            break;
        }
    }

    // get data
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_data_user", $bind, array(
        "setwheres" => array(
            array("and", array("lte", "basetime", $end_dt)),
            array("and", array("gte", "basetime", $start_dt))
        ),
        "setgroups" => array("device_id", "username")
    ));
    $rows = exec_db_fetch_all($sql, $bind);

    // compare old credentials
    $credentials = itsm_get_data("credentials");
    foreach($rows as $row) {
        // check is exists duplicate record
        $is_duplicate = false;
        foreach($credentials as $credential) {
            if($asset_id == $credential->assetid && $row['username'] == $credential->username) {
                $is_duplicate = true;
                break;
            }
        }

        // check credential type (Disabled/Enabled)
        $credential_type = "";
        if($row['disabled'] == 1) {
            $credential_type = "Disabled";
        } else {
            $credential_type = "Enabled";
        }

        // if not duplicate
        if($is_duplicate == false) {
            $bind = array(
                "clientid" => $client_id,
                "assetid" => $asset_id,
                "type" => $credential_type,
                "username" => $row['username']
            );
            itsm_add_data("credentials", $bind);
        }
    }

    $data['success'] = true;
} else {
    // get data
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_data_user", $bind, array(
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

