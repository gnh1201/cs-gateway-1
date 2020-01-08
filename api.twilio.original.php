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

$action = get_requested_value("action", array("_JSON", "_ALL"));
$message = get_requested_value("message", array("_JSON", "_ALL"));
$to = get_requested_value("to", array("_JSON", "_ALL"));

$country = get_requested_value("country", array("_JSON", "_ALL"));
$is_domestic = array_key_equals("lguplus_country", $config, $country);

$response = false;
$arr_to = array(sprintf("+%s%s", $country, $to), $to);

// temporary filter (example)
$terms = get_tokenized_text($message);
if(in_array("fuck", $terms) || in_array("bitch", $terms) || in_array("hell", $terms)) {
    $action = "denied";
}

switch($action) {
  case "text":
    if(!$is_domestic) {
        $response = twilio_send_message($message, $arr_to[0]);
    } else {
        $response = lguplus_send_message($message, $arr_to[1]);
    }
    break;

  case "voice":
    $response = twilio_send_voice($message, $arr_to[0]);
    break;

  case "denied":
    $response = array("error" => "action is denied");
    break;

  default:
    $response = array("error" => "action is required");
    break;
}

write_common_log(sprintf("message: %s, to: %s", $message, $to), "api.twilio");

header("Content-Type: application/json");
echo json_encode($response);
