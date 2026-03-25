<?php
/**
 * Endpoint AJAX: devuelve los tiempos de dedicación de UN usuario.
 * Llamado desde el modo Grupo de index.php.
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();
require_capability('local/audit:view', context_system::instance());
require_sesskey();

$userid   = required_param('userid',   PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$mintime  = optional_param('mintime',  0, PARAM_INT);
$maxtime  = optional_param('maxtime',  0, PARAM_INT);

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
    'id, firstname, lastname, firstnamephonetic, lastnamephonetic,
     middlename, alternatename, username, suspended');

if (!$user) {
    echo json_encode(['error' => 'User not found']);
    die;
}

$dedication = local_audit_get_dedication($userid, $courseid, $mintime, $maxtime);

$rows = [];
foreach ($dedication as $d) {
    if (empty($d->sessions)) {
        continue;
    }
    $rows[] = [
        'courseid'      => (int)$d->courseid,
        'coursename'    => $d->coursename,
        'shortname'     => $d->shortname,
        'timesecs'      => (int)$d->timesecs,
        'timeformatted' => $d->timeformatted,
        'sessioncount'  => count($d->sessions),
    ];
}

echo json_encode([
    'userid'    => (int)$user->id,
    'fullname'  => fullname($user),
    'username'  => $user->username,
    'suspended' => (bool)$user->suspended,
    'rows'      => $rows,
]);
