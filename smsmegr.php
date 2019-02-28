<?php
defined('_SMSNOTIFY') or die('Restricted access');
//--------------------------SMSME.gr API-----------------------------------------
function smsmegr_gatewaydetails()
{
    $details = array();
    $details["name"] = "SMSMe.gr";
    $details["country"] = "Greece / International";
    $details["site"] = "https://www.smsme.gr";
    $details["pricelist"] = "https://smsme.gr/timokatalogos.aspx";
    $details["developer"] = "pRieStaKos | info@cubric.gr";
    $details["schedulesms"] = true; /*set true if gateway supports sms scheduling*/
    $details["unicodesupport"] = false; /*set true if gateway supports Unicode sms*/
    $details["params"]["customsender"] = array("type" => "text", "value" => "", "name" => "Originator", "description" => "If you leave it empty it will get the global SenderID value from Settings");

    return $details;
}

function smsmegr_sendsms($params)
{
    $params["senderid"] = (!empty($params["customsender"])) ? trim($params["customsender"]) : trim($params["senderid"]);
    $params["to"] = urlencode($params["to"]); // Phone(s) separated by commas

    // If your gateway supports SMS Scheduling
    $schedule = "";
    if ($params["schedule_time"] != '' && $params["schedule_date"] != '') {
        $datetime = date('yyyy-mm-dd HH:mm:ss', strtotime($params["schedule_date"] . ' ' . $params["schedule_time"])); // change date format
        $schedule = '&smsDate=' . $datetime; // add param
    }
    if ($params["longsms"] != 'yes') $params["message"] = smscut($params["message"], 160); // short sms
    if ($params["unicode"] == "yes") $params["message"] = unicode($params["message"]); // unicode() converts ascii text to unicode data

    #EXAMPLE
    $url = "http://webservice.smsme.gr/SendBulkSmsRequest.aspx?Username=" . $params["username"] . "&Password=" . $params["password"] . "&Originator=" . $params["senderid"];
    $url .= "&Mobile=" . $params["to"] . "&Body=" . $params["message"];
    if (!empty($schedule)) $url .= $schedule; // if supported
    if ($params["unicode"] == "yes") $url .= '&unicode=1'; // if supported

    $data = getRemoteData($url); // Send request
    /* Returns
       $data["response"]; => The response
       $data["error"]; => Connections errors (rare)
    */

    // Communications with gateway API server error check
    if (!empty($data['error']) || empty($data["response"])) return array('error' => $data['error'], 'smsid' => time()); // stop because of communication error

    // Example $data["response"] =
    // OK: 1 12345:306912345678
    // or
    // BAD USER (δεν βρέθηκε ο χρήστης με το συγκεκριμένο username και password)
    // ERROR PASSWORD (δεν δόθηκε password)
    // ERROR USERNAME (δεν δόθηκε username)
    // ERROR ORIGINATOR (δεν δόθηκε αποστολές μηνύματος)
    // ERROR Mobile (δεν δόθηκε παραλήπτης)
    // ERROR BODY (δεν δόθηκε περιεχόμενο μηνύματος)
    // NOT ENOUGH CREDITS (Ο πελάτης δεν έχει αρκετά χρήματα στο λογαριασμό του)
    // EXCEPTION ERROR (παρουσιάστηκε πρόβλημα κατά την αποστολή παρακαλώ δοκιμάστε ξανά.)
    // WRONG NUMBER (Ο αριθμός παραλήπτη που δόθηκε δεν ήταν σωστός)
    // NO CREDITS (Ο πελάτης δεν έχει χρήματα στο λογαριασμό του)
    // BAD ORIGINATOR (ο αποστολές δεν είναι σωστός - πάνω απο 11 χαρακτήρες)
    // BAD DATE (η ημερομηνία που δόθηκε στο πεδίο smsDate δεν έχει τη σωστή μορφή - yyyy-mm-dd HH:mm:ss)

    $values = array();
    $parts = explode(' ', $data["response"]);
    if ($parts[0] == 'OK:') {
        $values["smsid"] = $parts[3]; //// REQUIRED to able to get the status smsmegr_getsmsstatus()
    } else {
        $values['error'] = $data["response"]; // Error reason-response
    }
    logger('send.smsmegr', $url, $data, array_merge($values, $params), array($params["username"], $params["password"])); // ModuleLogging

    return $values;
}

function smsmegr_getSmsBalance($params)
{
    $url = "http://webservice.smsme.gr/login.aspx?Username=" . urlencode($params["username"]) . "&Password=" . urlencode($params["password"]);
    $data = getRemoteData($url);

    // Communications with gateway API server error check
    if (!empty($data['error'])) return array('error' => $data['error'], 'credits' => 0); // stop because of communication error

    // Υπόλοιπο Λογαριασμού σε ευρώ με 3 δεκαδικά ψηφία
    // -- $data["response"]="0,932" ή
    // Κείμενο Σφάλματος
    // --- BAD USER (δεν βρέθηκε ο χρήστης με το συγκεκριμένο username και password)
    // --- ERROR PASSWORD (δεν δόθηκε password)
    // --- ERROR USERNAME (δεν δόθηκε username)

    $values["credits"] = $data["response"];

    return $values;
}

function smsmegr_getsmsstatus($params)
{
    $datetime = date('yyyy-mm-dd HH:mm:ss', strtotime($params["schedule_date"] . ' ' . $params["schedule_time"]));

    $url = "http://webservice.smsme.gr/Reports.aspx?Username=" . urlencode($params["username"]) . "&Password=" . urlencode($params["password"]) . "&Sdate=" . $datetime . "&Edate=" . $datetime;
    $url .= "&Mobile=" . $params["to"];
    $data = getRemoteData($url);

    // $data["response"]; => The response
    // $data["error"]; => Connections errors (rare)

    // Communications with gateway API server error check
    if (!empty($data['error'])) return array('status' => 2, 'cost' => 0); // stop because of communication error,we don't pass the error because may it's temporary so we set it as Pending (status=2)

    $xml = simplexml_load_string($data["response"]);

    $status = $xml->ReportStatus;
    $messid = $xml->ReportID;
    $cost = $xml->Cost;

    if (empty($data["error"]) && !$data["response"]) {
        $values["smsid"] = $messid;
    } else {
        $values["error"] = $data["error"];
    }
    return $status;
}

//Helper Function!
function smsmegr_errorcodes($code)
{
    $error = '';
    if ($code == '005') {
        $error = 'No credits';
    } elseif ($code == '006') {
        $error = 'Invalid destination';
    }
    //etc
    return $error;
}