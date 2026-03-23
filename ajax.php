<?php
/**
 * Endpoint AJAX para autocompletar usuarios y obtener cursos de un alumno.
 *
 * Acciones:
 *   type=user   + q        → Busca usuarios por nombre/usuario (mín. 2 chars).
 *   type=course + userid   → Devuelve todos los cursos del usuario (sin q).
 *
 * Devuelve JSON: [ { id, label } ]
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$type   = required_param('type',   PARAM_ALPHA);
$q      = optional_param('q',      '', PARAM_TEXT);
$userid = optional_param('userid', 0,  PARAM_INT);
$q      = trim($q);

$results = [];

header('Content-Type: application/json; charset=utf-8');

// ── Búsqueda de usuarios ──────────────────────────────────────────────────
if ($type === 'user') {
    if (strlen($q) < 2) {
        echo json_encode([]);
        exit;
    }

    $like   = '%' . $DB->sql_like_escape($q) . '%';
    $params = ['q1' => $like, 'q2' => $like, 'q3' => $like];
    $sql    = "SELECT id, firstname, lastname, username
                 FROM {user}
                WHERE deleted = 0
                  AND (" . $DB->sql_like('firstname', ':q1', false) . "
                       OR " . $DB->sql_like('lastname',  ':q2', false) . "
                       OR " . $DB->sql_like('username',  ':q3', false) . ")
             ORDER BY lastname, firstname
                LIMIT 20";

    foreach ($DB->get_records_sql($sql, $params) as $row) {
        $results[] = [
            'id'    => $row->id,
            'label' => $row->lastname . ', ' . $row->firstname . ' (' . $row->username . ') — ID: ' . $row->id,
        ];
    }

// ── Cursos matriculados de un usuario (listado completo) ──────────────────
} else if ($type === 'course') {
    if ($userid <= 0) {
        echo json_encode([]);
        exit;
    }

    $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname
              FROM {course} c
              JOIN {enrol} e            ON e.courseid  = c.id
              JOIN {user_enrolments} ue ON ue.enrolid  = e.id
             WHERE ue.userid = :userid
               AND c.id <> 1
          ORDER BY c.fullname";

    foreach ($DB->get_records_sql($sql, ['userid' => $userid]) as $row) {
        $results[] = [
            'id'        => $row->id,
            'fullname'  => $row->fullname,
            'shortname' => $row->shortname,
        ];
    }
}

echo json_encode($results);
