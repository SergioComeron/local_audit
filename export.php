<?php
/**
 * Exportación a CSV.
 *
 * Parámetros GET:
 *   userid   (int)    — Filtro por ID de usuario (0 = todos).
 *   courseid (int)    — Filtro por ID de curso   (0 = todos).
 *   type     (alpha)  — 'assign' | 'quiz' | 'forum'  (por defecto 'assign').
 *
 * Solo accesible para administradores del sitio.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/locallib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$userid   = optional_param('userid',   0,        PARAM_INT);
$courseid = optional_param('courseid', 0,        PARAM_INT);
$type     = optional_param('type',     'assign', PARAM_ALPHA);

if ($userid <= 0 && $courseid <= 0) {
    redirect(new moodle_url('/local/audit/index.php'), get_string('nosearchcriterion', 'local_audit'));
}

// ── Cabeceras HTTP ────────────────────────────────────────────────────────
$filename = 'auditoria_' . $type . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel.

$out = fopen('php://output', 'w');

// ── Entregas (assign) ─────────────────────────────────────────────────────
if ($type === 'assign') {
    fputcsv($out, [
        'ID Entrega', 'ID Usuario', 'Nombre', 'Apellidos', 'Usuario', 'Correo',
        'Estado usuario', 'ID Curso', 'Curso', 'Cód. curso',
        'ID Actividad', 'Actividad', 'Estado entrega', 'Intento nº',
        'Fecha creación', 'Última modificación', 'Ficheros (nombre|tamaño|MIME)',
    ], ';');

    $fs = get_file_storage();
    foreach (local_audit_get_submissions($userid, $courseid) as $sub) {
        $context   = context_module::instance($sub->cmid);
        $files     = $fs->get_area_files($context->id, 'assignsubmission_file',
                         'submission_files', $sub->subid, 'filename', false);
        $fileparts = [];
        foreach ($files as $file) {
            $fileparts[] = $file->get_filename() . '|' . display_size($file->get_filesize()) . '|' . $file->get_mimetype();
        }
        fputcsv($out, [
            $sub->subid, $sub->userid, $sub->firstname, $sub->lastname,
            $sub->username, $sub->email,
            $sub->suspended ? get_string('suspended', 'local_audit') : get_string('active', 'local_audit'),
            $sub->courseid, $sub->coursename, $sub->courseshortname,
            $sub->assignid, $sub->assignname,
            local_audit_status_text($sub->status),
            $sub->attemptnumber,
            $sub->timecreated  ? date('Y-m-d H:i:s', $sub->timecreated)  : '',
            $sub->timemodified ? date('Y-m-d H:i:s', $sub->timemodified) : '',
            implode(' :: ', $fileparts),
        ], ';');
    }
}

// ── Exámenes (quiz) ───────────────────────────────────────────────────────
if ($type === 'quiz') {
    fputcsv($out, [
        'ID Intento', 'ID Usuario', 'Nombre', 'Apellidos', 'Usuario', 'Correo',
        'Estado usuario', 'ID Curso', 'Curso', 'Cód. curso',
        'ID Examen', 'Examen', 'Nº Intento', 'Estado',
        'Inicio', 'Fin', 'Puntuación',
    ], ';');

    foreach (local_audit_get_quiz_attempts($userid, $courseid) as $att) {
        fputcsv($out, [
            $att->attemptid, $att->userid, $att->firstname, $att->lastname,
            $att->username, $att->email,
            $att->suspended ? get_string('suspended', 'local_audit') : get_string('active', 'local_audit'),
            $att->courseid, $att->coursename, $att->courseshortname,
            $att->quizid, $att->quizname,
            $att->attemptnum,
            local_audit_quiz_state_text($att->state),
            $att->timestart  ? date('Y-m-d H:i:s', $att->timestart)  : '',
            $att->timefinish ? date('Y-m-d H:i:s', $att->timefinish) : '',
            local_audit_quiz_grade($att),
        ], ';');
    }
}

// ── Foros (forum) ─────────────────────────────────────────────────────────
if ($type === 'forum') {
    fputcsv($out, [
        'ID Post', 'ID Usuario', 'Nombre', 'Apellidos', 'Usuario', 'Correo',
        'Estado usuario', 'ID Curso', 'Curso', 'Cód. curso',
        'ID Foro', 'Foro', 'Discusión', 'Asunto post',
        'Fecha', 'Mensaje',
    ], ';');

    foreach (local_audit_get_forum_posts($userid, $courseid) as $post) {
        fputcsv($out, [
            $post->postid, $post->userid, $post->firstname, $post->lastname,
            $post->username, $post->email,
            $post->suspended ? get_string('suspended', 'local_audit') : get_string('active', 'local_audit'),
            $post->courseid, $post->coursename, $post->courseshortname,
            $post->forumid, $post->forumname,
            $post->discussionname, $post->postsubject,
            date('Y-m-d H:i:s', $post->created),
            strip_tags($post->message),
        ], ';');
    }
}

fclose($out);
exit;
