<?php
$filename = get_requested_value("filename");
$action = get_requested_value("action");

if(empty($filename)) {
    set_error("filename is required");
    show_errors();
}

$requests = get_requests();

if($action == "write") {
    $fw = write_storage_file($requests['_RAW'], array(
        "filename" => $filename
    ));
    echo $fw ? 'true' : 'false';
} else {
    $fr = read_storage_file($filename);
    echo $fr;
}

