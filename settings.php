<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('reports', new admin_externalpage(
        'local_audit',
        get_string('pluginname', 'local_audit'),
        new moodle_url('/local/audit/index.php'),
        'moodle/site:config'
    ));
}
