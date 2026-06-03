<?php
/**
 * Endpoint AJAX: devuelve las sesiones Zoom de un usuario en un curso concreto.
 * Llamado desde la pestaña Tiempo del modo Individual.
 *
 * Definir AJAX_SCRIPT evita que Moodle genere HTML, por lo que
 * add_body_class() no lanza excepción aunque se llame internamente.
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();
require_capability('local/audit:view', context_system::instance());
require_sesskey();

$userid   = required_param('userid',   PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$mintime  = optional_param('mintime',  0, PARAM_INT);
$maxtime  = optional_param('maxtime',  0, PARAM_INT);

if (!local_audit_zoom_available()) {
    echo json_encode(['available' => false, 'sessions' => []]);
    die;
}

$raw = local_audit_get_zoom_sessions($userid, $courseid, $mintime, $maxtime);

// Añadir campos formateados para que JS no necesite hacer el formateo.
$sessions = [];
foreach ($raw as $zs) {
    $rectime = 0;
    foreach ($zs['grabaciones'] ?? [] as $g) {
        $rectime += $g['tiempoVisto'] ?? 0;
    }
    // tiempoSesion viene en minutos de la API; convertir a segundos para el formateador.
    $liveSecs = (int)($zs['tiempoSesion'] ?? 0) * 60;
    $zs['tiempoFmt']  = $liveSecs > 0 ? local_audit_format_secs($liveSecs) : null;
    $zs['rectimeFmt'] = $rectime > 0 ? local_audit_format_secs($rectime) : null;
    $sessions[] = $zs;
}

echo json_encode(['available' => true, 'sessions' => $sessions]);
