<?php

$requests = get_requests();

write_storage_file($requests['_RAW'] . "\r\n", array(
    "filename" => "api.agent.win32.log",
    "storage_type" => "logs",
    "mode" => "a"
));

$data = array(
    "jobkey" => "cmd",
    "data" => array("cmd" => "netstat -an"),
);

echo json_encode($data);
