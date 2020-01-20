<?php
loadHelper("string.utils");

$data = array(
    "success" => false
);

// get devices
$bind = false;
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$rows = exec_db_fetch_all($sql, $bind);

foreach($devices as $device)
    // set variable
    $device_os = strtolower($device['os']);
    $device_id = $device['id'];

    // add new queue
    $_bind = array(
        "platform" => $device['platform']
    );
    $_sql = get_bind_to_sql_select("autoget_commands", $_bind, array(
        "setwheres" => array(
            array("and", array("not", "disabled", 1))
        ),
        "setorders" => array(
            array("asc", "last") // `asc:last` means set high priority to old commands
        )
    ));
    $_rows = exec_db_fetch_all($_sql, $_bind);

    foreach($_rows as $_row) {
        $_pos = strpos($device_os, strtolower($_row['platform']));
        if($_pos !== false)
            // get the last
            $__bind = array(
                "device_id" => $device_id,
                "command_id" => $_row['id']
            );
            $__sql = get_bind_to_sql_select("autoget_lasts", $__bind);
            $__row = exec_db_fetch($__sql, $__bind);

            // compare now and last
            $last_dt = get_value_in_array("last", $__row, "");

            if(!empty($last_dt)) {
                $__bind = array(
                    "now_dt" => $now_dt,
                    "last_dt" => $last_dt
                );
                $__sql = sprintf("select (%s - time_to_sec(timediff(:now_dt, :last_dt))) as dtf", intval($_row['period']));
                $__row = exec_db_fetch($__sql, $__bind);
                $dtf = intval(get_value_in_array("dtf", $__row, 0));
                if($dtf > 0) {
                    //write_common_log("## skip. dtf: " . $dtf, "api.agent.noarch");
                    continue;
                } else {
                    //write_common_log("## next. dtf: " . $dtf, "api.agent.noarch");
                }
            }

            // add to queue
            $__bind = array(
                "device_id" => $device_id,
                "jobkey" => "cmd",
                "jobstage" => $_row['id'],
                "message" => $_row['command'],
                "created_on" => get_current_datetime(),
                "expired_on" => get_current_datetime(array(
                    "now" => $now_dt,
                    "adjust" => "10m"
                ))
            );
            $__sql = get_bind_to_sql_insert("autoget_tx_queue", $__bind);
            exec_db_query($__sql, $__bind);
        }
    }
}

$data['success'] = true;

header("Content-Type: application/json");
echo json_encode($data);
