<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_audit_get_dedication' => [
        'classname'    => 'local_audit\external\get_dedication',
        'methodname'   => 'execute',
        'description'  => 'Returns dedication time data for a user, optionally filtered by course shortname and date range.',
        'type'         => 'read',
        'capabilities' => 'moodle/site:config',
        'ajax'         => false,
        'loginrequired' => true,
    ],
];

$services = [
    'Submission Audit API' => [
        'shortname'       => 'local_audit',
        'functions'       => ['local_audit_get_dedication'],
        'restrictedusers' => 1,
        'enabled'         => 1,
    ],
];
