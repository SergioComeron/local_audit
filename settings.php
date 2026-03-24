<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig || has_capability('local/audit:view', context_system::instance(), null, false)) {
    $ADMIN->add('reports', new admin_externalpage(
        'local_audit',
        get_string('pluginname', 'local_audit'),
        new moodle_url('/local/audit/index.php'),
        'local/audit:view'
    ));
}
