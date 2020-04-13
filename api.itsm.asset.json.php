<?php
loadHelper("itsm.api");

$data = array();

$targets = get_requested_value("targets", "_JSON");

$hosts = itsm_get_data("assets");

foreach($targets as $target) {
    if($target->data == true) {
        $target_name = $target->target;
        foreach($hosts as $host) {
            if($host->name == $target_name) {
                $host_description = get_property_value(42, $host->customfields);
                
                if(empty($host_description)) {
                   $host_description = "Not specified"; 
                }
                
                $data[] = array(
                    "target" => $target_name,
                    "datapoints" => array(
                        array($host_description, get_current_timestamp())
                    )
                );

                break;
            }
        }
    }
}

header("Content-Type: application/json");
echo json_encode($data);
