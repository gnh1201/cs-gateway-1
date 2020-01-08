<?php
loadHelper("webpagetool");
loadHelper("JSLoader.class");

$device_id = get_requested_value("device_id");
$before_dt = get_requested_value("before_dt");
$after_dt = get_requested_value("after_dt");

if(empty($device_id)) {
    set_error("device_id is required");
    show_errors();
}

if(empty($after_dt)) {
    $after_dt = get_current_datetime();
}

if(empty($before_dt)) {
    $before_dt = get_current_datetime(array(
        "now" => $after_dt,
        "adjust" => "-1 hour"
    ));
}

// range of dt0
$start_dt0 = get_current_datetime(array(
    "now" => $before_dt,
    "adjust" => "-1 hour"
));
$end_dt0 = $before_dt;

// range of dt1
$start_dt1 = get_current_datetime(array(
    "now" => $after_dt,
    "adjust" => "-1 hour"
));
$end_dt1 = $after_dt;

// before
$response = get_web_page(base_url(), "get", array(
    "route" => "api.portmap.dot",
    "device_id" => $device_id,
    "start_dt" => $start_dt0,
    "end_dt" => $end_dt0
));
$data['map0'] = write_storage_file($response['content'], array(
    "storage_type" => "temp",
    "url" => true
));

// before (table)
$response = get_web_page(base_url(), "get", array(
    "route" => "api.portmap.dot",
    "device_id" => $device_id,
    "start_dt" => $start_dt0,
    "end_dt" => $end_dt0,
    "format" => "json.datatables"
));
$data['tbl0'] = write_storage_file($response['content'], array(
    "storage_type" => "temp",
    "url" => true
));

// after
$response = get_web_page(base_url(), "get", array(
    "route" => "api.portmap.dot",
    "device_id" => $device_id,
    "start_dt" => $start_dt1,
    "end_dt" => $end_dt1
));
$data['map1'] = write_storage_file($response['content'], array(
    "storage_type" => "temp",
    "url" => true
));

// after (table)
$response = get_web_page(base_url(), "get", array(
    "route" => "api.portmap.dot",
    "device_id" => $device_id,
    "start_dt" => $start_dt1,
    "end_dt" => $end_dt1,
    "format" => "json.datatables"
));
$data['tbl1'] = write_storage_file($response['content'], array(
    "storage_type" => "temp",
    "url" => true
));

// make javascript
$jscontent = <<<EOF
    $("<link>").attr({
        "type": "text/css",
        "rel": "stylesheet",
        "href": "https://cdnjs.cloudflare.com/ajax/libs/vis/4.21.0/vis-network.min.css"
    }).appendTo("head");

    // draw port map (before)
    $("#map0").css("height", "500px");
    $.get("{$data['map0']}", function(res) {
        var container = document.getElementById("map0");
        var parsedData = vis.network.convertDot(res);
        var data = {
            nodes: parsedData.nodes,
            edges: parsedData.edges
        };
        var options = parsedData.options;
        var network = new vis.Network(container, data, options);
    }, "text");

    // draw port map (after)
    $("#map1").css("height", "500px");
    $.get("{$data['map1']}", function(res) {
        var container = document.getElementById("map1");
        var parsedData = vis.network.convertDot(res);
        var data = {
            nodes: parsedData.nodes,
            edges: parsedData.edges
        };
        var options = parsedData.options;
        var network = new vis.Network(container, data, options);
    }, "text");

    // draw port table (before)
    $("#tbl0").DataTable({
        "ajax": "{$data['tbl0']}",
        "rowId": "rowid",
        "pageLength": 100,
        "columns": [
            {"data": "process_name"},
            {"data": "address"},
            {"data": "port"},
            {"data": "state"},
            {"data": "pid"}
        ],
        rowCallback: function(row, data) {
            $.get("{$data['tbl1']}", function(res) {
                var is_highlighted = true;
                for(var i in res.data) {
                    //alert(res.data[i].rowid);
                    if($(row).attr("id") == res.data[i].rowid) {
                        is_highlighted = false;
                        break;
                    }
                }
                if(is_highlighted) {
                    $(row).css("background-color", "#ff6961");
                } 
            }, "json");
        }
    });

    // draw port table (after)
    $("#tbl1").DataTable({
        "ajax": "{$data['tbl1']}",
        "rowId": "rowid",
        "pageLength": 100,
        "columns": [
            {"data": "process_name"},
            {"data": "address"},
            {"data": "port"},
            {"data": "state"},
            {"data": "pid"}
        ],
        rowCallback: function(row, data) {
           $.get("{$data['tbl0']}", function(res) {
                var is_highlighted = true;
                for(var i in res.data) {
                    if($(row).attr("id") == res.data[i].rowid) {
                        is_highlighted = false;
                        break;
                    }
                }
                if(is_highlighted) {
                    $(row).css("background-color", "#77dd77");
                }
            }, "json");
        }
    });

    // datepicker
    $("#before_dt").datepicker({"format": "yyyy-mm-dd " + $("#before_dt").val().substring(11)});
    $("#after_dt").datepicker({"format": "yyyy-mm-dd " + $("#after_dt").val().substring(11)}); 
EOF;
$jsloader = new JSLoader();
$jsloader->add_scripts("https://cdnjs.cloudflare.com/ajax/libs/vis/4.21.0/vis-network.min.js");
$jsloader->add_scripts(write_storage_file($jscontent, array(
    "storage_type" => "temp",
    "url" => true
)));
$data['jsoutput'] = $jsloader->get_output();

$data['device_id'] = $device_id;
$data['before_dt'] = $before_dt;
$data['after_dt'] = $after_dt;

//renderView("templates/adminlte2/header", $data);
renderView("view_portmap.compare", $data);
//renderView("templates/adminlte2/footer", $data);

