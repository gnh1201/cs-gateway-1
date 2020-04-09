<?php
// . > ||

loadHelper("webpagetool");
loadHelper("itsm.api");
loadHelper("string.utils");

$callback = get_requested_value("callback");
if(empty($callback)) {
    $callback = "callback";
}

$data = array();

$hosts = itsm_get_data("monitoring_hosts");
foreach($hosts as $host) {
    $ipaddress = gethostbyname($host->address);

    if(!(
        strlike($ipaddress, "127.%")  ||
        strlike($ipaddress, "10.%") ||
        strlike($ipaddress, "192.168.%") ||  
        strlike($ipaddress, "172.16.%") ||
        strlike($ipaddress, "172.17.%") ||
        strlike($ipaddress, "172.18.%") ||
        strlike($ipaddress, "172.19%") ||
        strlike($ipaddress, "172.20.%") ||
        strlike($ipaddress, "172.21.%") ||
        strlike($ipaddress, "172.22.%") ||
        strlike($ipaddress, "172.23.%") ||
        strlike($ipaddress, "172.24.%") ||
        strlike($ipaddress, "172.25.%") ||
        strlike($ipaddress, "172.26.%") ||
        strlike($ipaddress, "172.27.%") ||
        strlike($ipaddress, "172.28.%") ||
        strlike($ipaddress, "172.29.%") ||
        strlike($ipaddress, "172.30.%") ||
        strlike($ipaddress, "172.31.%")
    )) {
        $url = get_web_binded_url("https://www.iplocate.io/api/lookup/:ipaddress", array(
            "ipaddress" => $ipaddress
        ));
        $response = get_web_json($url, "get.cache");
        $data[] = array(
            "key" => "HOST-" . $host->id,
            "latitude" => $response->latitude,
            "longitude" => $response->longitude,
            "name" => $host->name
        );
    }
}

header("Content-Type: application/json");
echo sprintf("%s(%s)", $callback, json_encode($data));
