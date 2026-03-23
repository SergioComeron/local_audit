<?php
/**
 * Auditoría de entregas — página principal.
 *
 * Permite consultar entregas de tipo assign filtrando por usuario y/o curso,
 * incluyendo usuarios suspendidos. Muestra los ficheros adjuntos a cada entrega
 * con enlace de descarga individual y opción de exportación CSV.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/locallib.php');

admin_externalpage_setup('local_audit');

$userid   = optional_param('userid',   0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$searched = optional_param('searched', 0, PARAM_INT);
$tab      = optional_param('tab', 'assign', PARAM_ALPHA);

// ── Datos de precarga para los selectores cuando la página tiene filtros ──
$useroptions   = ['' => ''];
$courseoptions = ['' => ''];

if ($userid > 0) {
    $preuser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
        'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, username');
    if ($preuser) {
        $useroptions[$userid] = fullname($preuser) . ' (' . $preuser->username . ')';
    }
}

if ($courseid > 0) {
    $precourse = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
    if ($precourse) {
        $courseoptions[$courseid] = $precourse->fullname . ' [' . $precourse->shortname . ']';
    }
}

// ── Inicializar core/form-autocomplete para ambos campos ──────────────────
$PAGE->requires->js_call_amd('core/form-autocomplete', 'enhance', [
    '#userid',
    false,
    'local_audit/usersearch',
    get_string('searchuser', 'local_audit'),
    false,
    true,
    get_string('noselection', 'local_audit'),
]);

$PAGE->requires->js_call_amd('core/form-autocomplete', 'enhance', [
    '#courseid',
    false,
    'local_audit/coursesearch',
    get_string('searchcourse', 'local_audit'),
    false,
    true,
    get_string('noselection', 'local_audit'),
]);

// ── Salida HTML ───────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_audit'));

// ── Formulario de búsqueda ────────────────────────────────────────────────
$searchurl = new moodle_url('/local/audit/index.php');

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $searchurl->out(false),
    'class'  => 'mb-4',
]);
echo html_writer::start_div('card');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('filterheading', 'local_audit'), ['class' => 'card-title']);

// Fila usuario.
echo html_writer::start_div('form-group row mb-3');
echo html_writer::tag('label', get_string('userid', 'local_audit'), [
    'for'   => 'userid',
    'class' => 'col-sm-2 col-form-label',
]);
echo html_writer::start_div('col-sm-6');
echo html_writer::select($useroptions, 'userid', (string)$userid, false, ['id' => 'userid', 'class' => 'form-control']);
echo html_writer::end_div();
echo html_writer::end_div();

// Fila curso.
echo html_writer::start_div('form-group row mb-3');
echo html_writer::tag('label', get_string('courseid', 'local_audit'), [
    'for'   => 'courseid',
    'class' => 'col-sm-2 col-form-label',
]);
echo html_writer::start_div('col-sm-6');
echo html_writer::select($courseoptions, 'courseid', (string)$courseid, false, ['id' => 'courseid', 'class' => 'form-control']);
echo html_writer::tag('small', get_string('coursehelp', 'local_audit'), ['class' => 'form-text text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'searched', 'value' => '1']);

echo html_writer::start_div('form-group row');
echo html_writer::start_div('col-sm-6 offset-sm-2');
echo html_writer::tag('button', get_string('search', 'local_audit'), [
    'type'  => 'submit',
    'class' => 'btn btn-primary',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // card-body
echo html_writer::end_div(); // card
echo html_writer::end_tag('form');

// Script mínimo: limpiar curso cuando cambia el usuario.
echo '<script>
document.getElementById("userid").addEventListener("change", function () {
    var sel = document.getElementById("courseid");
    sel.value = "";
    sel.dispatchEvent(new Event("change"));
});
</script>';

// ── Resultados ────────────────────────────────────────────────────────────
if ($searched) {
    if ($userid <= 0 && $courseid <= 0) {
        echo $OUTPUT->notification(get_string('nosearchcriterion', 'local_audit'), 'info');
    } else {
        // Ejecutar las tres consultas.
        $submissions   = local_audit_get_submissions($userid, $courseid);
        $quizattempts  = local_audit_get_quiz_attempts($userid, $courseid);
        $forumposts    = local_audit_get_forum_posts($userid, $courseid);

        // Parámetros comunes para URLs.
        $urlparams = ['userid' => $userid, 'courseid' => $courseid, 'searched' => 1];

        // ── Pestañas ─────────────────────────────────────────────────────
        $tabs = [
            new tabobject(
                'assign',
                new moodle_url('/local/audit/index.php', $urlparams + ['tab' => 'assign']),
                get_string('tabassign', 'local_audit') . ' (' . count($submissions) . ')'
            ),
            new tabobject(
                'quiz',
                new moodle_url('/local/audit/index.php', $urlparams + ['tab' => 'quiz']),
                get_string('tabquiz', 'local_audit') . ' (' . count($quizattempts) . ')'
            ),
            new tabobject(
                'forum',
                new moodle_url('/local/audit/index.php', $urlparams + ['tab' => 'forum']),
                get_string('tabforum', 'local_audit') . ' (' . count($forumposts) . ')'
            ),
        ];
        echo $OUTPUT->tabtree($tabs, $tab);

        // ── Pestaña Entregas ──────────────────────────────────────────────
        if ($tab === 'assign') {
            $csvurl = new moodle_url('/local/audit/export.php', $urlparams + ['type' => 'assign']);
            echo html_writer::div(
                html_writer::link($csvurl, '&#x1F4E5; ' . get_string('exportcsv', 'local_audit'),
                    ['class' => 'btn btn-secondary btn-sm mb-3']),
                'text-right'
            );

            if (empty($submissions)) {
                echo $OUTPUT->notification(get_string('noresults', 'local_audit'), 'warning');
            } else {
                $fs    = get_file_storage();
                $table = new html_table();
                $table->head = [
                    get_string('student',          'local_audit'),
                    get_string('username',         'local_audit'),
                    get_string('email',            'local_audit'),
                    get_string('userstatus',       'local_audit'),
                    get_string('course',           'local_audit'),
                    get_string('assignment',       'local_audit'),
                    get_string('submissionstatus', 'local_audit'),
                    get_string('timecreated',      'local_audit'),
                    get_string('timemodified',     'local_audit'),
                    get_string('files',            'local_audit'),
                ];
                $table->attributes = ['class' => 'generaltable table-sm'];

                foreach ($submissions as $sub) {
                    $userstatus = $sub->suspended
                        ? html_writer::tag('span', get_string('suspended', 'local_audit'), ['class' => 'badge badge-danger bg-danger text-white'])
                        : html_writer::tag('span', get_string('active',    'local_audit'), ['class' => 'badge badge-success bg-success text-white']);

                    $context = context_module::instance($sub->cmid);
                    $files   = $fs->get_area_files($context->id, 'assignsubmission_file',
                                   'submission_files', $sub->subid, 'filename', false);

                    $filecell = get_string('nofiles', 'local_audit');
                    if (!empty($files)) {
                        $filelist = [];
                        foreach ($files as $file) {
                            $dlurl  = new moodle_url('/local/audit/download.php', ['fileid' => $file->get_id()]);
                            $label  = html_writer::tag('strong', $file->get_filename());
                            $meta   = html_writer::tag('small',
                                ' (' . display_size($file->get_filesize()) . ', ' . $file->get_mimetype() . ')',
                                ['class' => 'text-muted']);
                            $dllink = html_writer::link($dlurl, get_string('download', 'local_audit'),
                                ['class' => 'btn btn-sm btn-outline-primary ml-1']);
                            $filelist[] = html_writer::tag('li', $label . $meta . $dllink, ['class' => 'mb-1']);
                        }
                        $filecell = html_writer::tag('ul', implode('', $filelist),
                            ['class' => 'list-unstyled mb-0', 'style' => 'min-width:220px']);
                    }

                    $row = new html_table_row([
                        html_writer::link(new moodle_url('/user/view.php',       ['id' => $sub->userid]),   fullname($sub)),
                        s($sub->username),
                        s($sub->email),
                        $userstatus,
                        html_writer::link(new moodle_url('/course/view.php',     ['id' => $sub->courseid]), s($sub->coursename)) .
                            html_writer::tag('br', html_writer::tag('small', s($sub->courseshortname), ['class' => 'text-muted'])),
                        html_writer::link(new moodle_url('/mod/assign/view.php', ['id' => $sub->cmid]),     s($sub->assignname)),
                        local_audit_status_label($sub->status),
                        $sub->timecreated  ? userdate($sub->timecreated)  : '—',
                        $sub->timemodified ? userdate($sub->timemodified) : '—',
                        $filecell,
                    ]);
                    $table->data[] = $row;
                }
                echo html_writer::table($table);
            }
        }

        // ── Pestaña Exámenes ──────────────────────────────────────────────
        if ($tab === 'quiz') {
            $csvurl = new moodle_url('/local/audit/export.php', $urlparams + ['type' => 'quiz']);
            echo html_writer::div(
                html_writer::link($csvurl, '&#x1F4E5; ' . get_string('exportcsv', 'local_audit'),
                    ['class' => 'btn btn-secondary btn-sm mb-3']),
                'text-right'
            );

            if (empty($quizattempts)) {
                echo $OUTPUT->notification(get_string('noresults', 'local_audit'), 'warning');
            } else {
                $table = new html_table();
                $table->head = [
                    get_string('student',         'local_audit'),
                    get_string('username',        'local_audit'),
                    get_string('email',           'local_audit'),
                    get_string('userstatus',      'local_audit'),
                    get_string('course',          'local_audit'),
                    get_string('quiz',            'local_audit'),
                    get_string('attemptnum',      'local_audit'),
                    get_string('quizstate',       'local_audit'),
                    get_string('timestart',       'local_audit'),
                    get_string('timefinish',      'local_audit'),
                    get_string('grade',           'local_audit'),
                ];
                $table->attributes = ['class' => 'generaltable table-sm'];

                foreach ($quizattempts as $att) {
                    $userstatus = $att->suspended
                        ? html_writer::tag('span', get_string('suspended', 'local_audit'), ['class' => 'badge badge-danger bg-danger text-white'])
                        : html_writer::tag('span', get_string('active',    'local_audit'), ['class' => 'badge badge-success bg-success text-white']);

                    $row = new html_table_row([
                        html_writer::link(new moodle_url('/user/view.php',      ['id' => $att->userid]),   fullname($att)),
                        s($att->username),
                        s($att->email),
                        $userstatus,
                        html_writer::link(new moodle_url('/course/view.php',    ['id' => $att->courseid]), s($att->coursename)) .
                            html_writer::tag('br', html_writer::tag('small', s($att->courseshortname), ['class' => 'text-muted'])),
                        html_writer::link(new moodle_url('/mod/quiz/view.php',  ['id' => $att->cmid]),     s($att->quizname)),
                        (int)$att->attemptnum,
                        local_audit_quiz_state_label($att->state),
                        $att->timestart  ? userdate($att->timestart)  : '—',
                        $att->timefinish ? userdate($att->timefinish) : '—',
                        local_audit_quiz_grade($att),
                    ]);
                    $table->data[] = $row;
                }
                echo html_writer::table($table);
            }
        }

        // ── Pestaña Foros ─────────────────────────────────────────────────
        if ($tab === 'forum') {
            $csvurl = new moodle_url('/local/audit/export.php', $urlparams + ['type' => 'forum']);
            echo html_writer::div(
                html_writer::link($csvurl, '&#x1F4E5; ' . get_string('exportcsv', 'local_audit'),
                    ['class' => 'btn btn-secondary btn-sm mb-3']),
                'text-right'
            );

            if (empty($forumposts)) {
                echo $OUTPUT->notification(get_string('noresults', 'local_audit'), 'warning');
            } else {
                $table = new html_table();
                $table->head = [
                    get_string('student',         'local_audit'),
                    get_string('username',        'local_audit'),
                    get_string('email',           'local_audit'),
                    get_string('userstatus',      'local_audit'),
                    get_string('course',          'local_audit'),
                    get_string('forum',           'local_audit'),
                    get_string('discussion',      'local_audit'),
                    get_string('postsubject',     'local_audit'),
                    get_string('timecreated',     'local_audit'),
                    get_string('message',         'local_audit'),
                ];
                $table->attributes = ['class' => 'generaltable table-sm'];

                foreach ($forumposts as $post) {
                    $userstatus = $post->suspended
                        ? html_writer::tag('span', get_string('suspended', 'local_audit'), ['class' => 'badge badge-danger bg-danger text-white'])
                        : html_writer::tag('span', get_string('active',    'local_audit'), ['class' => 'badge badge-success bg-success text-white']);

                    $discussionurl = new moodle_url('/mod/forum/discuss.php', ['d' => $post->discussionid]);
                    $forumurl      = new moodle_url('/mod/forum/view.php',    ['id' => $post->cmid]);

                    $row = new html_table_row([
                        html_writer::link(new moodle_url('/user/view.php',   ['id' => $post->userid]),   fullname($post)),
                        s($post->username),
                        s($post->email),
                        $userstatus,
                        html_writer::link(new moodle_url('/course/view.php', ['id' => $post->courseid]), s($post->coursename)) .
                            html_writer::tag('br', html_writer::tag('small', s($post->courseshortname), ['class' => 'text-muted'])),
                        html_writer::link($forumurl,      s($post->forumname)),
                        html_writer::link($discussionurl, s($post->discussionname)),
                        s($post->postsubject),
                        userdate($post->created),
                        html_writer::tag('small',
                            shorten_text(strip_tags($post->message), 120),
                            ['class' => 'text-muted']),
                    ]);
                    $table->data[] = $row;
                }
                echo html_writer::table($table);
            }
        }
    }
}

echo $OUTPUT->footer();
