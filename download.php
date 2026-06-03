<?php
/**
 * Descarga de un fichero adjunto a una entrega de assign o a su corrección.
 *
 * Parámetros GET:
 *   fileid (int) — ID del registro en mdl_files.
 *
 * Solo accesible para administradores del sitio.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('local/audit:view', context_system::instance());

$fileid = required_param('fileid', PARAM_INT);

$fs   = get_file_storage();
$file = $fs->get_file_by_id($fileid);

$allowed = [
    'assignsubmission_file'  => 'submission_files',  // Fichero entregado por el alumno.
    'assignfeedback_file'    => 'feedback_files',    // Fichero de corrección del profesor.
    'assignfeedback_editpdf' => 'download',          // PDF anotado generado por EditPDF.
];

if (!$file
    || $file->is_directory()
    || !isset($allowed[$file->get_component()])
    || $allowed[$file->get_component()] !== $file->get_filearea()
) {
    throw new moodle_exception('filenotfound', 'local_audit');
}

// Enviamos el fichero forzando la descarga (forcedownload = true).
send_stored_file($file, 0, 0, true);
