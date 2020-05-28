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
 * Shim layer over webservice_rest
 *
 * @package     local_oauth2
 * @copyright   2020 Morgan Harris <morgan.harris@unsw.edu.au>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oauth2;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->dirroot/webservice/rest/locallib.php");

class webservice_rest_server extends \webservice_rest_server {
    protected function parse_request() {
        // Retrieve and clean the POST/GET parameters from the parameters specific to the server.
        parent::set_web_service_call_settings();

        // Get GET and POST parameters.
        $methodvariables = array_merge($_GET, $_POST);

        // Retrieve REST format parameter - 'xml' (default) or 'json'.
        // Try to detect from Accept header
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            switch ($_SERVER['HTTP_ACCEPT']) {
                case 'application/json':
                    $this->restformat = 'json';
                    break;
                case 'application/xml':
                    $this->restformat = 'xml';
                    break;
            }
        }

        if (empty($this->restformat)) {
            $restformatisset = isset($methodvariables['moodlewsrestformat'])
                    && (($methodvariables['moodlewsrestformat'] == 'xml' || $methodvariables['moodlewsrestformat'] == 'json'));
            $this->restformat = $restformatisset ? $methodvariables['moodlewsrestformat'] : 'xml';
            unset($methodvariables['moodlewsrestformat']);
        }

        // Get the token from the Bearer header
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (empty($auth) && function_exists('apache_request_headers')) {
            $auth = apache_request_headers()['Authorization'] ?? null;
        }

        if (!empty($auth)) {
            $isbearer = substr($auth, 0, strlen('Bearer ')) === 'Bearer ';
            if ($isbearer) {
                $this->token = trim(substr($auth, strlen('Bearer ')));
            }
        }

        if (empty($this->token)) {
            $this->token = isset($methodvariables['wstoken']) ? $methodvariables['wstoken'] : null;
            unset($methodvariables['wstoken']);
        }

        $pathinfo = get_file_argument();
        debugging($pathinfo);
        if ($pathinfo) {
            $this->functionname = trim($pathinfo, '/');
        }

        if (empty($this->functionname)) {
            $this->functionname = isset($methodvariables['wsfunction']) ? $methodvariables['wsfunction'] : null;
            unset($methodvariables['wsfunction']);
        }

        $this->parameters = $methodvariables;

        try {
            $rawjson = file_get_contents('php://input');
            $input = json_decode($rawjson, true);
            if (!empty($input)) {
                $this->parameters = $input;
            }
        } catch (Exception $e) {
            // never mind
        }

    }
}
