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

$zoomavail = local_audit_zoom_available();

$rows = [];
foreach ($dedication as $d) {
    if (empty($d->sessions)) {
        continue;
    }

    $row = [
        'courseid'      => (int)$d->courseid,
        'coursename'    => $d->coursename,
        'shortname'     => $d->shortname,
        'timesecs'      => (int)$d->timesecs,
        'timeformatted' => $d->timeformatted,
        'sessioncount'  => count($d->sessions),
        'zoomtime'      => null,   // null = Zoom no disponible o no consultado
    ];

    // Zoom solo tiene sentido con curso concreto (una llamada a la API por fila).
    if ($zoomavail && $d->courseid > 0) {
        $zsessions        = local_audit_get_zoom_sessions($userid, (int)$d->courseid, $mintime, $maxtime);
        $row['zoomtime']  = local_audit_zoom_total_secs($zsessions);
        $row['zoomfmt']   = $row['zoomtime'] > 0 ? local_audit_format_secs($row['zoomtime']) : null;
    }

    $rows[] = $row;
}

echo json_encode([
    'userid'    => (int)$user->id,
    'fullname'  => fullname($user),
    'username'  => $user->username,
    'suspended' => (bool)$user->suspended,
    'zoomavail' => $zoomavail,
    'rows'      => $rows,
]);
