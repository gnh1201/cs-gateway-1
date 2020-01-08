<?php
/**
 * @file api.twilio.php
 * @date 2019-04-15
 * @author Go Namhyeon <gnh1201@gmail.com>
 * @brief Twilio API controller (or domestic API)
  */

loadHelper("twilio.api"); // for voice, or international
loadHelper("lguplus.api"); // for domestic
loadHelper("string.utils");
loadHelper("itsm.api"); // ITSM API interface

$action = get_requested_value("action", array("_JSON", "_ALL"));
$message = get_requested_value("message", array("_JSON", "_ALL"));
$to = get_requested_value("to", array("_JSON", "_ALL"));

$country = get_requested_value("country", array("_JSON", "_ALL"));
$is_domestic = array_key_equals("lguplus_country", $config, $country);

$response = false;

$to_list = array();
$to_list[] = array(
    "intl" => sprintf("+%s%s", $country, $to),
    "dmst" => $to
);

// get tokenized message
$terms = get_tokenized_text($message);

// match assets and staffs
$clients = itsm_get_data("clients");
$assets = itsm_get_data("assets");
$roles = itsm_get_data("roles");
$users = itsm_get_data("users");
$staffs = itsm_get_data("staff");

$roleids = array();
foreach($assets as $asset) {
    $roleids[] = $asset->roleid;
    if(!empty($asset->asset_notification) && in_array($asset->name, $terms)) {
        foreach($clients as $client) {
            if($client->id == $asset->clientid) {
                $roleids[] = $client->roleid;
                if(!empty($client->client_notification)) {
                    foreach($users as $user) {
                        if($client->id == $user->clientid && $user->roleid == "5") {
                            $to = trim(str_replace("-", "", $user->mobile));
                            $to_list[] = array(
                                "intl" => sprintf("+%s%s", $country, $to),
                                "dmst" => $to
                            );
                            break;
                        }
                    }

                    $roleids = array_filter($roleids);
                    foreach($users as $user) {
                        if(in_array($user->roleid, $roleids)) {
                            $to = trim(str_replace("-", "", $user->mobile));
                            $to_list[] = array(
                                "intl" => sprintf("+%s%s", $country, $to),
                                "dmst" => $to
                            );
                        }
                    }

                    foreach($staffs as $staff) {
                        if($staff->roleid == "6") {
                            $to = trim(str_replace("-", "", $staff->mobile));
                            $to_list[] = array(
                                "intl" => sprintf("+%s%s", $country, $to),
                                "dmst" => $to
                            );
                        }
                    }
                }
                break;
            }
        }
    }

    if(in_array($asset->name, $terms)) {
        foreach($clients as $client) {
            if($client->id == $asset->clientid) {
                $message = str_replace("/ Unknown /", sprintf("/ %s /", $client->name), $message);
                break;
            }
        }
    }
}

// split message
$messages = str_split($message, $config['textmsg_char_limit']);

// prevent stopwords
if(in_array("fuck", $terms) || in_array("bitch", $terms) || in_array("hell", $terms)) {
    $action = "denied";
}

// remove duplicate phone
$_to_index = array();
$_to_list = array();
foreach($to_list as $arr_to) {
    $_to_index_name = get_hashed_text($arr_to['intl']);
    if(!in_array($_to_index_name, $_to_index)) {
        $_to_list[] = $arr_to;
    }
    $_to_index[] = $to_index_name;
}
$to_list = $_to_list;

// send message
$responses = array();
foreach($to_list as $arr_to) {
    switch($action) {
        case "text":
            foreach($messages as $message) {
                if(!$is_domestic) {
                    $responses[] = twilio_send_message($message, $arr_to['intl']);
                } else {
                    $responses[] = lguplus_send_message($message, $arr_to['dmst']);
                }
            }
            break;

        case "voice":
            $responses[] = twilio_send_voice($message, $arr_to['intl']);
            break;

        case "denied":
            $responses[] = array("error" => "action is denied");
            break;

        default:
            $responses[] = array("error" => "action is required");
            break;
    }

    write_debug_log(sprintf("action: %s, message: %s, to: %s", $action, $message, $arr_to['intl']), "api.twilio");
}

header("Content-Type: application/json");
echo json_encode(array("success" => true, "data" => $responses));
