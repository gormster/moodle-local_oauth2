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
 * Generate an access code
 *
 * @package     local_oauth2
 * @copyright   2020 Morgan Harris <morgan.harris@unsw.edu.au>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__.'/../../config.php');
require_once($CFG->libdir . '/externallib.php');

require_login(0, false);
if (isguestuser()) {
    require_logout();
    die();
    throw new moodle_exception('noguest');
}

$responsetype = required_param('response_type', PARAM_ALPHAEXT);
$clientid = required_param('client_id', PARAM_RAW);
$redirecturi = required_param('redirect_uri', PARAM_URL);
$serviceshortname = required_param('scope', PARAM_ALPHANUMEXT);
$state = optional_param('state', null, PARAM_RAW);

// Make sure this is a good client
$secret = $DB->get_record('local_oauth2_secrets', ['clientid' => $clientid]);
if (empty($secret)) {
    throw new moodle_exception('invalidclient', 'local_oauth2');
}

$client = $DB->get_record('local_oauth2_clients', ['id' => $secret->client]);
if (empty($client)) {
    throw new moodle_exception('invalidclient', 'local_oauth2');
}

$redirects = $DB->get_records('local_oauth2_redirects', ['client' => $client->id]);
$redirect = null;
foreach ($redirects as $r) {
    if ($r->redirecturi == $redirecturi) {
        $redirect = $r;
        break;
    }
}

if (empty($redirect)) {
    throw new moodle_exception('invalidredirect', 'local_oauth2', $redirecturi);
}

$service = $DB->get_record('external_services', ['shortname' => $serviceshortname, 'enabled' => 1]);
if (empty($service)) {
    throw new moodle_exception('servicenotavailable', 'webservice');
}

$token = external_generate_token_for_current_user($service);
external_log_token_request($token);

$now = time();
$expirationtime = 300; //get_config('expirationtime', 'local_oauth2')
$expires = $now + $expirationtime;

// Create a record in our tokens table
$rec = new stdClass();
$rec->client = $client->id;
$rec->code = random_string(30);
$rec->token = $token->token;
$rec->expires = $expires;
$rec->redirecturi = $redirecturi;

$DB->insert_record('local_oauth2_tokens', $rec);

// Sanity check: use the stored URI rather than the one supplied
$redirect = new moodle_url($redirect->redirecturi);
$redirect->param('code', $rec->code);
if (!is_null($state)) {
    $redirect->param('state', $state);
}

redirect($redirect);