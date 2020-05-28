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
 * Plugin upgrade steps are defined here.
 *
 * @package     local_oauth2
 * @category    upgrade
 * @copyright   2020 Morgan Harris <morgan.harris@unsw.edu.au>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute local_oauth2 upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_oauth2_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // For further information please read the Upgrade API documentation:
    // https://docs.moodle.org/dev/Upgrade_API
    //
    // You will also have to create the db/install.xml file by using the XMLDB Editor.
    // Documentation for the XMLDB Editor can be found at:
    // https://docs.moodle.org/dev/XMLDB_editor
    // 

    if ($oldversion < 2020052800) {

        // Define field redirecturi to be added to local_oauth2_tokens.
        $table = new xmldb_table('local_oauth2_tokens');
        $field = new xmldb_field('redirecturi', XMLDB_TYPE_TEXT, null, null, null, null, null, 'expires');

        // Conditionally launch add field redirecturi.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);

            // Because we want this to be not null, but TEXT type fields cannot have a default, we need to
            // do this kind of janky workaround.
            $DB->set_field('local_oauth2_tokens', 'redirecturi', '');

            $field->setNotNull();

            $dbman->change_field_notnull($table, $field);
        }

        // Oauth2 savepoint reached.
        upgrade_plugin_savepoint(true, 2020052800, 'local', 'oauth2');
    }    

    return true;
}
