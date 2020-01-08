<?php
loadHelper("string.utils");
loadHelper("webpagetool");

$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");
$adjust = get_requested_value("adjust");

$device_id = get_requested_value("device_id");

if(empty($device_id)) {
    set_error("device_id is required");
    show_errors();
}

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($adjust)) {
    $adjust = "-1 hour";
}

if(empty($start_dt)) {
    $start_dt = get_current_datetime(array(
        "now" => $end_dt,
        "adjust" => $adjust
    ));
}

$sql = get_bind_to_sql_select("autoget_responses", false, array(
    "setwheres" => array(
        array("and", array("eq", "device_id", $device_id)),
        array("and", array("eq", "command_id", 47)),
        array("and", array("gte", "datetime", $start_dt)),
        array("and", array("lte", "datetime", $end_dt))
    )
));
$rows = exec_db_fetch_all($sql, false);

$_tbl0 =  exec_db_temp_create(array(
    "name" => array("varchar", 255),
    "value" => array("float", "5,2")
));

foreach($rows as $row) {
    $itemnames = array();
    $itemvalues = array();
    $items = array(); 

    $terms = get_tokenized_text($row['response'], array(",", "\"", "\r\n", "\n"));

    $i = 0;
    foreach($terms as $term) {
        if($i > 0) {
            if(in_array(substr($term, 0, 2), array("\\\\", "(P"))) {
                $itemnames[] = $term;
            } else {
                $itemvalues[] = $term;
            }
        }
        $i++;
    }

    $i = 0; 
    foreach($itemnames as $name) {
        $d = get_tokenized_text($name, array("\\", "(", ")"));
        if(count($d) > 2) {
            $items[] = array(
                "name" => $d[2],
                "value" => floatval($itemvalues[$i])
            );
        }
        $i++;
    }

    foreach($items as $item) {
        $bind = $item;
        $sql = get_bind_to_sql_insert($_tbl0, $bind);
        exec_db_query($sql, $bind);
    }
}

// get number of cores - step 1
$num_cores = 1;
$_start_dt = get_current_datetime(array(
    "adjust" => "-1 hour" 
));
$sql = get_bind_to_sql_select("autoget_sheets", false, array(
    "setwheres" => array(
        array("and", array("eq", "device_id", $device_id)),
        array("and", array("eq", "command_id", 50)),
        array("and", array("gte", "datetime", $_start_dt))
    )
));
$_tbl1 = exec_db_fetch_all($sql, false);

// get number of cores - step 2
$num_cores = 1;
$sql = "select max(b.name) as num_cores from $_tbl1 a left join autoget_terms b on a.term_id = b.id";
$rows = exec_db_fetch_all($sql, false);
foreach($rows as $row) {
    $num_cores = $row['num_cores'];
}

// make output
$sql = "
    select name, concat(round(max(value) / {$num_cores}, 2), '%') as value
    from $_tbl0 where name not in('_Total', 'Idle')
    group by name
    order by value desc
";
$rows = exec_db_fetch_all($sql);

$data = array(
    "success" => true,
    "data" => $rows
);

header("Content-Type: application/json");
echo json_encode($data);

