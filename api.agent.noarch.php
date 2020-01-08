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
            "datetime" => get_current_datetime(),
            "last" => get_current_datetime()
        );
        $sql = get_bind_to_sql_insert("autoget_devices", $bind);
        exec_db_query($sql, $bind);
    }
}

if(array_key_equals("JOBKEY", $jobargs, "cmd")) {
    // set delimiters
    $delimiters = array(" ", "\t", "\",\"", "\"", "'", "\r\n", "\n", "(", ")", "\\");

    // get response
    $command_id = get_value_in_array("JOBSTAGE", $jobargs, "");
    $device_id = $device['id'];
    $response = base64_decode(get_value_in_array("DATA", $jobargs, ""));
    
    //write_common_log($jobargs['DATA'], 'base64');

    // tokenize response
    $terms = get_tokenized_text($response, $delimiters);
    foreach($terms as $term) {
        $term = trim($term);

        // add terms
        $bind = array(
            "term" => $term,
            "count" => 0,
            "datetime" => $now_dt,
            "last" => $now_dt
        );
        $sql = get_bind_to_sql_insert("autoget_terms", $bind, array(
            "ignore" => array(
                array("and", array("eq", "term", $term))
            )
        ));
        exec_db_query($sql, $bind);
    }

    // save response
    $bind = array(
        "command_id" => $command_id,
        "device_id" => $device_id,
        "response" => $response,
        "datetime" => $now_dt
    );
    $sql = get_bind_to_sql_insert("autoget_responses", $bind);
    exec_db_query($sql, $bind);
    $response_id = get_db_last_id();

    // update last
    $bind = array(
        "device_id" => $device_id,
        "command_id" => $command_id,
        "last" => $now_dt
    );
    $sql = "insert into autoget_lasts (device_id, command_id, last) value (:device_id, :command_id, :last) on duplicate key update device_id = :device_id, command_id = :command_id";
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
    $pos_y = 0;
    $pos_x = 0;
    $lines = split_by_line($response);
    foreach($lines as $line) {
        $pos_y++;
        $pos_x = 0;
        $terms = get_tokenized_text($line, $delimiters);
        foreach($terms as $term) {
            $pos_x++;
            $term = trim($term);

            if(!empty($term)) {

                // get term id
                $bind = array(
                    "term" => $term
                );
                $sql = get_bind_to_sql_select("autoget_terms", $bind);
                $row = exec_db_fetch($sql, $bind);
                $term_id = get_value_in_array("id", $row, 0);

                // count up
                exec_db_query(
                    "update autoget_terms set count = count + 1, last = :last where id = :id", array(
                        "id" => $term_id,
                        "last" => $now_dt
                    )
                );

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
            array("and", array("gte", "expired_on", get_current_datetime())),
            array("and", array("not", "is_read", 1))
        ),
        "setlimit" => 1,
        "setpage" => 1,
        "setorders" => array(
            array("asc", "id")
        )
    ));
    $rows = exec_db_fetch_all($sql, $bind);

    // if rows count is 0
    if(count($rows) == 0) {
        $_bind = false;
        $_sql = get_bind_to_sql_select("autoget_commands", $_bind, array(
            "setwheres" => array(
                array("and", array("not", "disabled", 1))
            ),
            "setorders" => array(
                array("asc", "id")
            )
        ));
        $_rows = exec_db_fetch_all($_sql, $_bind);
        foreach($_rows as $_row) {
            $_pos = strpos($device_os, strtolower($_row['platform']));
            if($_pos !== false) {
                // get the last
                $__bind = array(
                    "device_id" => $device_id,
                    "command_id" => $_row['id']
                );
                $__sql = get_bind_to_sql_select("autoget_lasts", $__bind);
                $__row = exec_db_fetch($_sql, $_bind);

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
                        "adjust" => sprintf("+%s seconds", intval($_row['period']) * 2)
                    ))
                );
                $__sql = get_bind_to_sql_insert("autoget_tx_queue", $__bind);
                exec_db_query($__sql, $__bind);
            }
        }

        // pull a empty job (ping)
        echo "jobkey: ping" . DOC_EOL;
        echo "jobstage: 0" . DOC_EOL;
        exit;
    }

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
        //echo get_db_binded_sql($_sql, $_bind);
        exec_db_query($_sql, $_bind);

        // if remote command execution
        if(array_key_equals("jobkey", $row, "cmd")) {
            echo sprintf("data.cmd: %s", $row['message']) . DOC_EOL;

            // update last datetime
            $_bind = array(
                "last" => get_current_datetime()
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
} else {
    set_error("Could not find your device ID");
    show_errors();
}

