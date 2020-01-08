<?php
loadHelper("webpagetool");
loadHelper("JSLoader.class");
loadHelper("json.format");

$data = array();

$device_id = get_requested_value("device_id");
$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");

if(empty($device_id)) {
    set_error("device_id is required");
    show_errors();
}

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($start_dt)) {
    $start_dt = get_current_datetime(array(
        "now" => $end_dt,
        "adjust" => "-24 hours"
    ));
}

$response = get_web_json(base_url(), "get", array(
    "route" => "api.hotfix.json",
    "device_id" => $device_id,
    "start_dt" => $start_dt,
    "end_dt" => $end_dt
));

$map0 = array();
foreach($response->data as $row) {
    $map0[] = array(
        "id" => get_hashed_text($row->hotfix_id),
        "content" => $row->hotfix_id,
        "start" => substr($row->datetime, 0, 10),
        "tooltip" => $row->hotfix_name
    );
}
$data['map0'] = write_storage_file(json_encode_ex($map0), array(
    "storage_type" => "temp",
    "url" => true,
    "extension" => "json"
));

$tbl0 = array();
foreach($response->data as $row) {
    $tbl0[] = array(
       "rowid" => get_hashed_text($row->hotfix_id),
       "date" => substr($row->datetime, 0, 10),
       "hotfix_id" => $row->hotfix_id,
       "hotfix_name" => $row->hotfix_name
    );

    $data['tbl0'] = write_storage_file(json_encode_ex(array(
        "data" => $tbl0
    )), array(
        "storage_type" => "temp",
        "url" => true,
        "extension" => "json"
    ));
}
$data['tbl0'] = write_storage_file(json_encode_ex(array(
    "data" => $tbl0
)), array(
    "storage_type" => "temp",
    "url" => true,
    "extension" => "json"
));

// make javascript content
$jscontent = <<<EOF
    $("<link>").attr({
        "type": "text/css",
        "rel": "stylesheet",
        "href": "https://cdnjs.cloudflare.com/ajax/libs/vis/4.21.0/vis-timeline-graph2d.min.css"
    }).appendTo("head");

    $.get("{$data['map0']}", function(res) {
        var container = document.getElementById("map0");
        var items = new vis.DataSet(res);
        var options = {
            //dataAttributes: "all",
            "height": "600px"
        };
        var timeline = new vis.Timeline(container, items, options);
    }, "json");

    $("#tbl0").DataTable({
        "ajax": "{$data['tbl0']}",
        "rowId": "rowid",
        "pageLength": 100,
        "columns": [
            {"data": "date"},
            {"data": "hotfix_id"},
            {"data": "hotfix_name"}
        ]
    });
EOF;
$jsloader = new JSLoader();
$jsloader->add_scripts("https://cdnjs.cloudflare.com/ajax/libs/vis/4.21.0/vis-timeline-graph2d.min.js");
$jsloader->add_scripts(write_storage_file($jscontent, array(
    "storage_type" => "temp",
    "url" => true,
    "extension" => "js"
)));
$data['jsoutput'] = $jsloader->get_output();

renderView("view_hotfix.timeline", $data);

