<?php
loadHelper("networktool");
loadHelper("string.utils");
loadHelper("colona.v1.format");

$requests = get_requests();

$ne = get_network_event();
$ua = $ne['agent'] . DOC_EOL;

$jobargs = decode_colona_format($requests['_RAW']);
$jobdata = decode_colona_format(base64_decode(get_value_in_array("DATA", $jobargs)));

$now_dt = get_current_datetime();

//write_common_log($requests['_RAW'], "api.agent.noarch");

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
            array("asc", "id")
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
            "is_read" => 1
        );
        $_sql = get_bind_to_sql_update("autoget_tx_queue", $_bind, array(
            "setwheres" => array(
                array("and", array("eq", "id", $row['id']))
            )
        ));
        exec_db_query($_sql, $_bind);

        // if remote command execution
        if(array_key_equals("jobkey", $row, "cmd")) {
            echo sprintf("data.cmd: %s", $row['message']) . DOC_EOL;

            // update last datetime
            $_bind = array(
                "last" => $now_dt
            );
            $_sql = get_bind_to_sql_update("autoget_commands", $_bind, array(
                "setwheres" => array(
                    array("and", array("eq", "id", $row['jobstage']))
                )
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
    // set delimiters
    $delimiters = array(" ", "\t", "\",\"", "\"", "'", "\r\n", "\n", "(", ")", "\\");

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

    // update the last of device_id/command_id
    $bind = array(
        "device_id" => $device_id,
        "command_id" => $command_id,
        "last" => $now_dt
    );
    $sql = get_bind_to_sql_insert("autoget_lasts", $bind, array(
        "setkeys" => array("device_id", "command_id")
    ));
    exec_db_query($sql, $bind);

    // create new sheet table
    $schemes = array(
        "response_id" => array("bigint", 20),
        "command_id" => array("int", 11),
        "device_id" => array("int", 11),
        "pos_y" => array("int", 5),
        "pos_x" => array("int", 5),
        "term_id" => array("bigint", 20),
        "datetime" => array("datetime")
    );
    $sheet_tablename = exec_db_table_create($schemes, "autoget_sheets", array(
        "suffix" => sprintf(".%s%s", date("YmdH"), sprintf("%02d", floor(date("i") / 10) * 10)),
        "setindex" => array(
            "index_1" => array("command_id", "device_id"),
            "index_2" => array("pos_y", "datetime"),
            "index_3" => array("pos_x", "datetime")
        )
    ));

    // make sheet
    $pos_y = 0;  // position y axis
    $pos_x = 0;  // position x axis
    $lines = split_by_line($response);
    foreach($lines as $line) {
        $pos_y++;
        $pos_x = 0;
        $terms = get_tokenized_text($line, $delimiters);
        foreach($terms as $term) {
            $pos_x++;

            // check term is empty
            if(empty($term)) continue;

            // check term is exists
            $bind = array(
                "term" => $term
            );
            $sql = get_bind_to_sql_select("autoget_terms", $bind, array(
                "getcount" => true
            ));
            $row = exec_db_fetch($sql, $bind);

            // add new term
            if($row['value'] == 0) {
                $bind = array(
                    "term" => $term,
                    "count" => 0,
                    "datetime" => $now_dt,
                    "last" => $now_dt
                );
                $sql = get_bind_to_sql_insert("autoget_terms", $bind);
                exec_db_query($sql, $bind);
            }

            // get term_id
            $bind = array(
                "term" => $term
            );
            $sql = get_bind_to_sql_select("autoget_terms", $bind);
            $row = exec_db_fetch($sql, $bind);
            $term_id = get_value_in_array("id", $row, 0);

            // check term_id is not empty
            if(!empty($term_id)) {
                // term count up
                $bind = array(
                    "id" => $term_id,
                    "last" => $now_dt
                );
                $sql = "update autoget_terms set count = count + 1, last = :last where id = :id";
                exec_db_query($sql, $bind);

                // add word to sheet
                $bind = array(
                    "response_id" => $response_id,
                    "command_id" => $command_id,
                    "device_id" => $device_id,
                    "pos_y" => $pos_y,
                    "pos_x" => $pos_x,
                    "term_id" => $term_id,
                    "datetime" => $now_dt
                );
                $sql = get_bind_to_sql_insert($sheet_tablename, $bind);
                exec_db_query($sql, $bind);
            }
        }
    }

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
}
