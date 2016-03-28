<?php
$messages = json_decode(file_get_contents("php://input"));

$jira_url = getenv('JIRA_URL');
$jira_username = getenv('JIRA_USERNAME');
$jira_password = getenv('JIRA_PASSWORD');
$jira_project = getenv('JIRA_PROJECT');
$jira_issue_type = getenv('JIRA_ISSUE_TYPE');
$jira_transition_id = getenv('JIRA_TRANSITION_ID');
$pd_subdomain = getenv('PAGERDUTY_SUBDOMAIN');
$pd_api_token = getenv('PAGERDUTY_API_TOKEN');

if ($messages) foreach ($messages->messages as $webhook) {
  $webhook_type = $webhook->type;

  switch ($webhook_type) {
    case "incident.trigger" || "incident.resolve":
      //Die if the lock file is in use or if it's a trigger from JIRA
      if(file_exists('lock.txt') && file_get_contents('lock.txt') > (time() - 5)) {
        die('Should not run!');
      }
      //Extract values from the PagerDuty webhook
      file_put_contents('lock.txt', time());
      $incident_id = $webhook->data->incident->id;
      $incident_number = $webhook->data->incident->incident_number;
      $ticket_url = $webhook->data->incident->html_url;
      $pd_requester_id = $webhook->data->incident->assigned_to_user->id;
      $service_name = $webhook->data->incident->service->name;
      $jira_issue_id = $webhook->data->incident->incident_key;
      $assignee = $webhook->data->incident->assigned_to_user->name;
      $trigger_summary_data = $webhook->data->incident->trigger_summary_data->description;
      $summary = "PagerDuty Service: $service_name, Incident #$incident_number, Summary: $trigger_summary_data";

      //Determine whether it's a trigger or resolve
      $verb = explode(".",$webhook_type)[1];

      error_log("jira_issue_id: " . $jira_issue_id);

      //Let's make sure the note wasn't already added (Prevents a 2nd Jira ticket in the event the first request takes long enough to not succeed according to PagerDuty)
      $url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
      $return = http_request($url, "", "GET", "token", "", $pd_api_token);
      if ($return['status_code'] == '200') {
        $response = json_decode($return['response'], true);
        if (array_key_exists("notes", $response)) {
          foreach ($response['notes'] as $value) {
            $startsWith = "JIRA ticket";
            if (substr($value['content'], 0, strlen($startsWith)) === $startsWith && $verb == "trigger") {
              break 2; //Skip it cause it would be a duplicate
            }
            //Extract the JIRA issue ID for incidents that did not originate in JIRA
            elseif (substr($value['content'], 0, strlen($startsWith)) === $startsWith && verb == "resolve") {
              preg_match('/JIRA ticket (.*) has.*/', $value['content'], $m);
              $jira_issue_id = $m[1];
            }
          }
        }
      }
      error_log("jira_issue_id 2: " . $jira_issue_id);
      error_log("transition_id: " . $jira_transition_id);

      $url = "$jira_url/rest/api/2/issue/";

      //Build the data JSON blobs to be sent to JIRA
      if ($verb == "trigger") {
        $note_verb = "created";
        $data = array('fields'=>array('project'=>array('key'=>"$jira_project"),'summary'=>"$summary",'description'=>"A new PagerDuty ticket as been created.  {$trigger_summary_data}. Please go to $ticket_url to view it.", 'issuetype'=>array('name'=>"$jira_issue_type")));
      }
      else if ($verb == "resolve") {
        $note_verb = "closed";
        $url = $url . $jira_issue_id . "/transitions";
        error_log($url);
        $data = array('update'=>array('comment'=>array('add'=>array('body'=>"PagerDuty incident #$incident_number has been resolved."))),'transition'=>array('id'=>"$jira_transition_id"));
      }

      //POST to JIRA
      $data_json = json_encode($data);
      error_log($data_json);
      $return = http_request($url, $data_json, "POST", "basic", $jira_username, $jira_password);
      $status_code = $return['status_code'];
      $response = $return['response'];
      $response_obj = json_decode($response);
      $response_key = $response_obj->key;

      $url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";

      if ($status_code == "201"  || $status_code == "204") {
        //Update the PagerDuty ticket with the JIRA ticket information.
        $data = array('note'=>array('content'=>"JIRA ticket $response_key has been $note_verb.  You can view it at $jira_url/browse/$response_key."),'requester_id'=>"$pd_requester_id");
        $data_json = json_encode($data);
        http_request($url, $data_json, "POST", "token", "", $pd_api_token);
      }
      else {
        //Update the PagerDuty ticket if the JIRA ticket isn't made.
        $data = array('note'=>array('content'=>"There was an issue communicating with JIRA. $response"),'requester_id'=>"$pd_requester_id");
        $data_json = json_encode($data);
        http_request($url, $data_json, "POST", "token", "", $pd_api_token);
      }
      break;
    default:
      continue;
  }
}

function http_request($url, $data_json, $method, $auth_type, $username, $token) {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  if ($auth_type == "token") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json),"Authorization: Token token=$token"));
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
  }
  else if ($auth_type == "basic") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json)));
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$token");
  }
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  if ($data_json != "") {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);
  }
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response  = curl_exec($ch);
  $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if(curl_errno($ch)){
    error_log('Curl error: ' . curl_error($ch));
  }
  curl_close($ch);
  return array('status_code'=>"$status_code",'response'=>"$response");
}
?>
