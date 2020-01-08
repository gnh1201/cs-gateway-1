<?php
loadHelper("webpagetool");
loadHelper("JSLoader.class");

$device_id = get_requested_value("device_id");
if(empty($device_id)) {
    set_error("device_id is required");
    show_errors();
}

$response = get_web_json(get_route_link("api.cpu.json"), "get", array(
    "device_id" => $device_id
));

var_dump($response);

exit;
