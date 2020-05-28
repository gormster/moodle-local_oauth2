<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 * Get the user token
 *
 * @package     local_oauth2
 * @copyright   2020 Morgan Harris <morgan.harris@unsw.edu.au>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('REQUIRE_CORRECT_ACCESS', true);

require_once(__DIR__.'/../../config.php');

header('Content-type: application/json');
header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
header('Pragma: no-cache');
header('Accept-Ranges: none');

function exception_handler($ex) {
    abort_all_db_transactions();
    $errorobject = new stdClass;
    $errorobject->exception = get_class($ex);
    if (isset($ex->errorcode)) {
        $errorobject->errorcode = $ex->errorcode;
    }
    $errorobject->message = $ex->getMessage();
    if (debugging() and isset($ex->debuginfo)) {
        $errorobject->debuginfo = $ex->debuginfo;
    }
    $error = json_encode($errorobject);
    echo json_encode($error);
    exit(1);
}
set_exception_handler('exception_handler');

// Return the access token we generated in the authorization request

$granttype = required_param('grant_type', PARAM_ALPHAEXT);
$code = required_param('code', PARAM_RAW);
$redirecturi = required_param('redirect_uri', PARAM_URL);

// This can be sent with HTTP Basic Auth
$clientid = optional_param('client_id', null, PARAM_RAW);
$clientsecret = optional_param('client_secret', null, PARAM_RAW);

if (empty($clientid) && empty($clientsecret)) {
    $clientid = $_SERVER['PHP_AUTH_USER'];
    $clientsecret = $_SERVER['PHP_AUTH_PW'];
}

if (empty($clientid) || empty($clientsecret)) {
    throw new moodle_exception('missingparam');
}

if($granttype != 'authorization_code') {
    throw new invalid_parameter_exception('grant type must be authorization_code');
}

$secret = $DB->get_record('local_oauth2_secrets', ['clientid' => $clientid]);
if (empty($secret)) {
    throw new moodle_exception('invalidclient', 'local_oauth2');
}

$verified = password_verify($clientsecret, $secret->clientsecret);
if (!$verified) {
    throw new moodle_exception('invalidclient', 'local_oauth2');
}

$client = $DB->get_record('local_oauth2_clients', ['id' => $secret->client]);
if (empty($client)) {
    throw new moodle_exception('invalidclient', 'local_oauth2');
}

$token = $DB->get_record('local_oauth2_tokens', ['code' => $code, 'client' => $client->id]);
if (empty($token)) {
    throw new moodle_exception('invalidtoken', 'webservice');
}

// The token is used.
$DB->delete_records('local_oauth2_tokens', ['id' => $token->id]);

if ($token->expires < time()) {
    throw new moodle_exception('invalidtimedtoken', 'webservice');
}

$output = [
  "access_token" => $token->token,
  "token_type" => "bearer"
];

echo json_encode($output);

