<?php
loadHelper("networktool");
loadHelper("string.utils");
loadHelper("webpagetool");
loadHelper("colona.v1.format");

$requests = get_requests();

$ne = get_network_event();
$ua = $ne['agent'] . DOC_EOL;

$jobargs = decode_colona_format($requests['_RAW']);
$jobdata = decode_colona_format(base64_decode(get_value_in_array("DATA", $jobargs)));

$now_dt = get_current_datetime();

// copy to test server
/*
if(APP_DEVELOPMENT == false) {
    get_web_page("http://10.125.31.182/~gw/?route=api.agent.noarch", "rawdata.cmd", $requests['_RAW']);
}
*/

// get device
$device = array();
if(!array_key_empty("UUID", $jobargs)) {
    $bind = array(
        "uuid" => $jobargs['UUID']
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind);
    $device = exec_db_fetch($sql, $bind);
}

// init
if(array_key_equals("JOBKEY", $jobargs, "init")) {
    if(array_key_empty("uuid", $device)) {
		$platform = "General";
		$osnames = get_tokenized_text(strtolower($jobdata['OS']));
		if(in_array("windows", $osnames)) {
			$platform = "Windows";
		} elseif(in_array("linux", $osnames)) {
			$platform = "Linux";
		}
		
        $bind = array(
            "uuid" => $jobdata['UUID'],
            "is_elevated" => $jobdata['IsElevated'],
            "uri" => $jobdata['URI'],
            "computer_name" => $jobdata['ComputerName'],
            "os" => $jobdata['OS'],
            "arch" => $jobdata['Arch'],
            "cwd" => $jobdata['CWD'],
            "net_ip" => implode(",", split_by_line($jobdata['Net_IP'])),
            "net_mac" => implode(",", split_by_line($jobdata['Net_MAC'])),
            "platform" => $platform,
            "datetime" => $now_dt,
            "last" => $now_dt
        );
        $sql = get_bind_to_sql_insert("autoget_devices", $bind);
        exec_db_query($sql, $bind);
    }
}

// get device
if(!array_key_empty("id", $device)) {
    $device_os = strtolower($device['os']);
    $device_id = $device['id'];

    // update last datetime
    $bind = array(
        "id" => $device_id,
        "last" => $now_dt
    );
    $sql = get_bind_to_sql_update("autoget_devices", $bind, array(
        "setkeys" => array("id")
    ));
    exec_db_query($sql, $bind);

    // check TX queue
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_tx_queue", $bind, array(
        "setwheres" => array(
            array("and", array("gte", "expired_on", $now_dt)),
            array("and", array("not", "is_read", 1))
        ),
        "setlimit" => 1,
        "setpage" => 1,
        "setorders" => array(
            array("asc", "expired_on") // `asc:expired_on` means set high priority to close deadlines
        )
    ));
    $rows = exec_db_fetch_all($sql, $bind);

    // pull a job
    foreach($rows as $row) {
        echo sprintf("jobkey: %s", $row['jobkey']) . DOC_EOL;
        echo sprintf("jobstage: %s", $row['jobstage']) . DOC_EOL;
        //echo sprintf("data.cmd: %s", $row['message']) . DOC_EOL;

        // update is_read flag of queue
        $_bind = array(
            "id" => $row['id'],
            "is_read" => 1
        );
        $_sql = get_bind_to_sql_update("autoget_tx_queue", $_bind, array(
            "setkeys" => array("id")
        ));
        exec_db_query($_sql, $_bind);

        // if remote command execution
        if(array_key_equals("jobkey", $row, "cmd")) {
            echo sprintf("data.cmd: %s", $row['message']) . DOC_EOL;

            // update last datetime
            $_bind = array(
                "id" => $row['jobstage'],
                "last" => $now_dt
            );
            $_sql = get_bind_to_sql_update("autoget_commands", $_bind, array(
                "setkeys" => array("id")
            ));
            exec_db_query($_sql, $_bind);
        } else {
            echo sprintf("data.message: %s", $row['message']) . DOC_EOL;
        }
    }

    // if rows count is 0: pull a empty job (ping)
    if(count($rows) == 0) {
        echo "jobkey: ping" . DOC_EOL;
        echo "jobstage: 0" . DOC_EOL;
        exit;
    }
} else {
    set_error("Could not find your device ID");
    show_errors();
}

if(array_key_equals("JOBKEY", $jobargs, "cmd")) {
    // get response from client
    $command_id = get_value_in_array("JOBSTAGE", $jobargs, "");
    $device_id = $device['id'];
    $response = base64_decode(get_value_in_array("DATA", $jobargs, ""));

    // add response to database
    $bind = array(
        "command_id" => $command_id,
        "device_id" => $device_id,
        "response" => get_compressed_text($response),
        "datetime" => $now_dt
    );
    $sql = get_bind_to_sql_insert("autoget_responses", $bind);
    exec_db_query($sql, $bind);
    $response_id = get_db_last_id();

    // update the last of device_id and command_id
    $bind = array(
        "device_id" => $device_id,
        "command_id" => $command_id,
        "last" => $now_dt
    );
    $sql = get_bind_to_sql_insert("autoget_lasts", $bind, array(
        "setkeys" => array("device_id", "command_id")
    ));
    exec_db_query($sql, $bind);

    // last status up
    $bind = array(
        "jobkey" => $jobargs['JOBKEY'],
        "jobstage" => $jobargs['JOBSTAGE']
    );
    $sql = get_bind_to_sql_update("autoget_devices", $bind, array(
        "setwheres" => array(
            array("and", array("eq", "uuid", $jobargs['UUID']))
        )
    ));
    exec_db_query($sql, $bind);

    // make sheet
    $bind = array(
        "response_id" => $response_id
    );
    //$response = get_web_page(get_route_link("api.sheet.json"), "get", $bind);
    //$pid = get_int($response['content']);
}

