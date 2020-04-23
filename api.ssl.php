<?php
loadHelper("SSL.class");

$host = get_requested_value("host");
if(empty($host)) {
    set_error("host is required");
    show_errors();
}

$certInfo = SSL::getSSLinfo($host);

header("Content-Type: application/json");
echo json_encode($certInfo);
