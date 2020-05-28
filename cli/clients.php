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
 * CLI script for local_oauth2.
 *
 * @package     local_oauth2
 * @subpackage  cli
 * @copyright   2020 Morgan Harris <morgan.harris@unsw.edu.au>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');

// Get the cli options.
list($options, $unrecognized) = cli_get_params(array(
    'list' => false,
    'setup' => false,
    'create' => null,
    'drop' => null,
    'rename' => null,
    'add-uri' => null,
    'drop-uri' => null,
    'add-secret' => false,
    'drop-secret' => null,
    'help' => false
),
array(
    'h' => 'help',
    'l' => 'list',
    's' => 'setup'
));

$help =
"
Manipulate the OAuth2 clients list. All commands can be run interactively or by passing command-line arguments.

-h
--help
Show this help text.

-s
--setup
Show the OAuth2 client setup information.

-l
--list
--list=[filter-string]
List out the clients and their registered redirect URIs, optionally filtered by name.

--create
--create=[name]
Create a new client with the given name.

The remaining commands operate on a client; you can pass the client's numeric ID or (partial) name.

--rename [client]
--rename=[newname] [client]
Change the client's name.

--drop [client]
Delete the client registration. Must be run interactively.

--add-uri [client]
--add-uri=[redirecturi] [client]
Add a new registered redirect URI for this client.

--drop-uri [client]
--drop-uri=[redirecturi] [client]
Delete the given redirect URI for this client. If passed on command line, can be partial as long as it matches
a particular URI uniquely.

--add-secret [client]
Add a new registered client ID/secret pair for this client. The secret is hashed and not stored. If you lose track of it,
create a new one.

--drop-secret [client]
--drop-secret=[redirecturi] [client]
Delete the given client ID/secret pair for this client. If passed on command line, can be partial as long as it matches
a particular client ID uniquely.
";

if (count(array_filter($options)) == 0) {
    $options['help'] = true;
} else if (count(array_filter($options)) > 1) {
    cli_error('More than one option selected, pick one action only');
}

function get_client($nameorid) {
    global $DB;
    $exc = null;
    try {
        if (is_numeric($nameorid)) {
            return $DB->get_record('local_oauth2_clients', ['id' => $nameorid], '*', MUST_EXIST);
        }
    } catch (dml_missing_record_exception $e) {
        $exc = $e;
    }

    try {
        return $DB->get_record('local_oauth2_clients', ['name' => $nameorid], '*', MUST_EXIST);    
    } catch (dml_missing_record_exception $e) {
        if ($exc) {
            throw $exc;
        } else {
            throw $e;
        }
    }
    
}

function opt_or_interactive($arg, $prompt, $default='', array $options=null) {
    if (is_string($arg)) {
        $rslt = $arg;
    } else {
        $rslt = cli_input($prompt, $default, $options);
    }
    return $rslt;
}

function interactive_pick_from_list($list, $prompt, $property = null) {
    if (empty($list)) {
        cli_writeln('No options to pick from.');
        die();
    }

    $prompts = [];
    $options = [];
    foreach (array_values($list) as $num => $value) {
        $num += 1;
        $options[$num] = $value;
        if (is_callable($property)) {
            $name = $property($value);
        } else if (is_array($value)) {
            $name = $value[$property];   
        } else if (is_object($value)) {
            $name = $value->$property;
        } else {
            $name = (string)$value;
        }
        $prompts[] = " [$num] $name";
    }
    $rslt = cli_input($prompt . PHP_EOL . implode(PHP_EOL, $prompts), '', array_merge(array_keys($options), ['']));
    if ($rslt == '') {
        die();
    }
    return $options[$rslt];
}

if ($options['help']) {
    cli_writeln($help);
    die();
}

if ($options['setup']) {
    cli_writeln('Grant type: Authorization Code');
    $authurl = new moodle_url($CFG->wwwroot . '/local/oauth2/authorize.php');
    cli_writeln('Authorization URL: ' .  $authurl->out(false));
    $tokenurl = new moodle_url($CFG->wwwroot . '/local/oauth2/token.php');
    cli_writeln('Access Token URL: ' . $tokenurl->out(false));
    die();
}

if ($options['list']) {
    $select = '';
    $params = [];

    if (is_string($options['list'])) {
        // Optionally filter the results.
        $select = $DB->sql_like('name', ':name');
        $params['name'] = '%' . $DB->sql_like_escape($options['list']) . '%';
    }
    
    $clients = $DB->get_records_select('local_oauth2_clients', $select, $params);
    if(empty($clients)) {
        cli_writeln(empty('select') ? 'No clients configured.' : 'No clients match filter string.');
        die();
    }
    foreach ($clients as $client) {
        printf('%8d | %s' . PHP_EOL, $client->id, $client->name);
        $redirecturis = $DB->get_records('local_oauth2_redirects', ['client' => $client->id]);
        foreach ($redirecturis as $redirect) {
            cli_writeln('    ' . $redirect->redirecturi);
        }
    }
    die();
}

if ($options['create']) {
    $client = new stdClass();
    $client->name = opt_or_interactive($options['create'], 'Enter client name');
    $id = $DB->insert_record('local_oauth2_clients', $client);
    cli_writeln('Created ' . $client->name . ' with ID ' . $id);
    die();
}

// All other options require the client ID or name passed as a final param
if (empty($unrecognized)) {
    cli_error('Pass the client ID or name as the final argument');
}

$client = get_client($unrecognized[0]);

if ($options['drop']) {
    $rslt = cli_input('Are you sure you want to drop this client? Y/N', 'N', ['Y', 'N']);
    if ($rslt === 'Y') {
        $txn = $DB->start_delegated_transaction();
        $DB->delete_records('local_oauth2_redirects', ['client' => $client->id]);
        $DB->delete_records('local_oauth2_secrets', ['client' => $client->id]);
        $DB->delete_records('local_oauth2_tokens', ['client' => $client->id]);
        $DB->delete_records('local_oauth2_clients', ['id' => $client->id]);
        $txn->allow_commit();
    }
    die();
}

if ($options['rename']) {
    $rslt = opt_or_interactive($options['rename'], 'Rename ' . $client->name . ' to:');

    $rslt = trim($rslt);
    if (!empty($rslt)) {
        $client->name = $rslt;
        $DB->update_record('local_oauth2_clients', $client);
    } else {
        cli_error('Invalid name');
    }
    die();
}

if ($options['add-uri']) {
    $rslt = opt_or_interactive($options['add-uri'], 'Enter redirect URI');
    $rslt = clean_param($rslt, PARAM_URL);

    if (!empty($rslt)) {
        $rec = new stdClass();
        $rec->client = $client->id;
        $rec->redirecturi = $rslt;
        $DB->insert_record('local_oauth2_redirects', $rec);
        cli_writeln('Added ' . $rslt . ' for client ' . $client->name);
    } else {
        cli_error('Invalid URL format');
    }
    
    die();
}

if ($options['drop-uri']) {

    if (is_string($options['drop-uri'])) {
        $partial = $options['drop-uri'];
        $like = $DB->sql_like('redirecturi', ':redirectlike');
        $params = ['client' => $client->id];
        $params['redirectlike'] = '%' . $DB->sql_like_escape($partial) . '%';
        $todrop = $DB->get_record_select('local_oauth2_redirects', 'client = :client AND ' . $like, $params, '*', MUST_EXIST);
    } else {
        $redirecturis = array_values($DB->get_records('local_oauth2_redirects', ['client' => $client->id]));
        $todrop = interactive_pick_from_list($redirecturis, 'Which URI to drop?', 'redirecturi');
        // if (empty($redirecturis)) {
        //     cli_writeln('No redirect URIs for this client.');
        //     die();
        // }

        // $prompts = [];
        // foreach ($redirecturis as $num => $uri) {
        //     $prompts[] = " [$num] $uri->redirecturi";
        // }
        // $rslt = cli_input('Which URI to drop?' . PHP_EOL . implode(PHP_EOL, $prompts), '', array_keys($redirecturis));
        // if ($rslt == '') {
        //     die();
        // }
        // $todrop = $redirecturis[$rslt];   
    }
    $DB->delete_records('local_oauth2_redirects', ['id' => $todrop->id]);
    cli_writeln('Dropped URI ' . $todrop->redirecturi . ' for client ' . $client->name);
    
    die();
}

if ($options['add-secret']) {
    $clientid = random_string();
    $secret = random_string(64);

    $rec = new stdClass();
    $rec->client = $client->id;
    $rec->clientid = $clientid;
    $rec->clientsecret = password_hash($secret, PASSWORD_DEFAULT);

    $DB->insert_record('local_oauth2_secrets', $rec);

    cli_writeln('Created secret for client ' . $client->name);
    cli_writeln('     client_id | ' . $clientid);
    cli_writeln(' client_secret | ' . $secret);
    die();
}

if ($options['drop-secret']) {
    if (is_string($options['drop-secret'])) {
        $clientid = $options['drop-secret'];
        $todrop = $DB->get_record('local_oauth2_secrets', ['client' => $client->id, 'clientid' => $clientid], '*', MUST_EXIST);
    } else {
        $secrets = $DB->get_records('local_oauth2_secrets', ['client' => $client->id]);
        $todrop = interactive_pick_from_list($secrets, 'Which secret pair to drop?', 'clientid');
    }
    $DB->delete_records('local_oauth2_redirects', ['id' => $todrop->id]);
    cli_writeln('Dropped secret pair with client ID ' . $todrop->clientid . ' for client ' . $client->name);
    die();
}

cli_error('unrecognized option');
