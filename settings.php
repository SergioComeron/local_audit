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

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_audit_settings', get_string('pluginname', 'local_audit'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_audit/showzoom',
        get_string('showzoom',      'local_audit'),
        get_string('showzoom_desc', 'local_audit'),
        1   // activado por defecto
    ));
}
