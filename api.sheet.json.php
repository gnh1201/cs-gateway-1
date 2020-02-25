<?php
loadHelper("string.utils");
loadHelper("perftool");

$response_id = get_requested_value("response_id");
$device_id = get_requested_value("device_id");
$end_dt = get_requested_value("end_dt");
$start_dt = get_requested_value("start_dt");
$adjust = get_requested_value("adjust");

write_debug_log("PID: $mypid, response_id: $response_id", "api.sheets.json");

$now_dt = get_current_datetime();

if(empty($response_id)) {
    set_error("response_id is required");
    show_errors();
}

if(empty($adjust)) {
    $adjust = "-1m";
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

// set cpu usage limit to this process (20%)
//set_cpu_usage_limit(0.2);

// wait a few seconds if minimum cpu idle or below (20%)
//set_min_cpu_idle(0.2);

$data = array(
    "success" => false
);

/*
$bind = false;
$sql = get_bind_to_sql_select("autoget_responses", $bind, array(
    "setwheres" => array(
        array("and", array("lte", "datetime", $end_dt)),
        array("and", array("gte", "datetime", $start_dt)),
        array("and", array("not", "is_read", 1))
    ),
    "setlimit" => 16,
    "setpage" => 1,
    "setorders" => array(
        array("asc", "datetime")
    )
));
*/

$bind = array(
    "id" => $response_id
);
$sql = get_bind_to_sql_select("autoget_responses", $bind);
$responses = exec_db_fetch_all($sql, $bind);

// set delimiters
$delimiters = array(" ", "\t", "\",\"", "\"", "'", "\r\n", "\n", "(", ")", "\\");

// set scheme
$scheme = array(
    "response_id" => array("bigint", 20),
    "command_id" => array("int", 11),
    "device_id" => array("int", 11),
    "pos_y" => array("int", 5),
    "pos_x" => array("int", 5),
    "term_id" => array("bigint", 20),
    "datetime" => array("datetime")
);

// set tablename
$tablename = exec_db_table_create($scheme, "autoget_sheets", array(
    "suffix" => sprintf(".%s%s", date("YmdH"), sprintf("%02d", floor(date("i") / 10) * 10)),
    "setindex" => array(
        "index_1" => array("command_id", "device_id"),
        "index_2" => array("pos_y", "datetime"),
        "index_3" => array("pos_x", "datetime")
    )
));

// set `is_read` to 1
foreach($responses as $response) {
    $bind = array(
        "id" => $response['id'],
        "is_read" => 1
    );
    $sql = get_bind_to_sql_update("autoget_responses", $bind, array(
        "setkeys" => array("id")
    ));
    exec_db_query($sql, $bind);
}

// set previous terms
$prev_terms = array();

// processing responses
foreach($responses as $response) {
    $response_text = get_uncompressed_text($response['response']);
    
    // make sheets
    $sheets = array();
    $pos_y = 0;  // position y axis
    $pos_x = 0;  // position x axis
    $lines = split_by_line($response_text);
    foreach($lines as $line) {
        $pos_y++;
        $pos_x = 0;
        $terms = get_tokenized_text($line, $delimiters);
        //write_debug_log(json_encode($terms));
        foreach($terms as $term) {
            $pos_x++;
            if(!empty($term)) {
                $sheets[] = array($pos_y, $pos_x, $term);
            }
        }
    }

    // start bulk of sheets
    $sheets_bulk_id = exec_db_bulk_start();

    // insert sheets
    foreach($sheets as $sheet) {
        // set term
        $term = $sheet[2];

        // get term_id from memory
        $term_id = array_search($term, $prev_terms);
        
        // get term_id from table
        if(empty($term_id)) {
            $bind = array(
                "term" => $term
            );
            $sql = get_bind_to_sql_select("autoget_terms", $bind);
            $row = exec_db_fetch($sql, $bind);
            $term_id = get_value_in_array("id", $row, 0);
            if(!empty($term_id)) {
                $prev_terms[$term_id] = $term;
            }
        }

        // add new term if empty term_id
        if(empty($term_id)) {
            $bind = array(
                "term" => $term,
                "count" => 0,
                "datetime" => $now_dt,
                "last" => $now_dt
            );
            $sql = get_bind_to_sql_insert("autoget_terms", $bind);
            exec_db_query($sql, $bind);
            $term_id = get_db_last_id();
            if(!empty($term_id)) {
                $prev_terms[$term_id] = $term;
            }
        }

        // if exists term ID
        if($term_id > 0) {
            // term count up
            $bind = array(
                "id" => $term_id,
                "last" => $now_dt
            );
            $sql = "update autoget_terms set count = count + 1, last = :last where id = :id";
            exec_db_query($sql, $bind);

            // add word to sheet
            $bind = array(
                "response_id" => $response['id'],
                "device_id" => $response['device_id'],
                "command_id" => $response['command_id'],
                "pos_y" => $sheet[0],
                "pos_x" => $sheet[1],
                "term_id" => $term_id,
                "datetime" => $now_dt
            );
            //$sql = get_bind_to_sql_insert($tablename, $bind);
            //exec_db_query($sql, $bind);
            exec_db_bulk_push($sheets_bulk_id, $bind);
        }
    }

    // end bulk of sheets
    $bindkeys = array("response_id", "device_id", "command_id", "pos_y", "pos_x", "term_id", "datetime");
    exec_db_bulk_end($sheets_bulk_id, $tablename, $bindkeys);
}

$data['success'] = true;

header("Content-Type: application/json");
echo json_encode($data);
