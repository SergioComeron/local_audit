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
$tab      = optional_param('tab', 'time', PARAM_ALPHA);
$page     = optional_param('page',     0, PARAM_INT);
$mintime  = optional_param('mintime',  0, PARAM_INT);
$maxtime  = optional_param('maxtime',  0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

define('LOCAL_AUDIT_PERPAGE', 50);

// ── Descarga nativa de tabla de tiempos (antes del header HTML) ───────────
if ($download && $tab === 'time' && $searched && $userid > 0 && local_audit_dedication_available()) {
    $tsort      = optional_param('tsort', '', PARAM_ALPHANUMEXT);
    $tdir       = optional_param('tdir', SORT_DESC, PARAM_INT);
    $dedication = local_audit_get_dedication($userid, $courseid, $mintime, $maxtime);

    if (!empty($dedication)) {
        if ($courseid > 0 && count($dedication) === 1) {
            // Tabla de sesiones individuales.
            $coursedata      = reset($dedication);
            $defaultsort     = $tsort ?: 'sessionstart';
            $sessions_sorted = array_values($coursedata->sessions);
            usort($sessions_sorted, function($a, $b) use ($defaultsort, $tdir) {
                $cmp = ($defaultsort === 'sessionduration')
                    ? ($a->dedicationtime <=> $b->dedicationtime)
                    : ($a->start_date <=> $b->start_date);
                return ($tdir == SORT_ASC) ? $cmp : -$cmp;
            });

            $dltable = new flexible_table('local-audit-sessions-' . $userid . '-' . $courseid);
            $dltable->define_columns(['sessionstart', 'sessionduration']);
            $dltable->define_headers([
                get_string('sessionstart',    'local_audit'),
                get_string('sessionduration', 'local_audit'),
            ]);
            $dltable->is_downloading($download, 'tiempos_sesiones');
            $dltable->setup();
            foreach ($sessions_sorted as $s) {
                $dltable->add_data([
                    userdate($s->start_date),
                    \block_dedication\lib\utils::format_dedication($s->dedicationtime),
                ]);
            }
            $dltable->finish_output();
        } else {
            // Tabla resumen por curso.
            $defaultsort = $tsort ?: 'totaltime';
            usort($dedication, function($a, $b) use ($defaultsort, $tdir) {
                switch ($defaultsort) {
                    case 'course':   $cmp = strcmp($a->coursename, $b->coursename); break;
                    case 'sessions': $cmp = count($a->sessions) <=> count($b->sessions); break;
                    default:         $cmp = $a->timesecs <=> $b->timesecs; break;
                }
                return ($tdir == SORT_ASC) ? $cmp : -$cmp;
            });

            $dltable = new flexible_table('local-audit-dedication-' . $userid);
            $dltable->define_columns(['course', 'shortname', 'totaltime', 'sessions']);
            $dltable->define_headers([
                get_string('course',    'local_audit'),
                get_string('shortname', 'local_audit'),
                get_string('totaltime', 'local_audit'),
                get_string('sessions',  'local_audit'),
            ]);
            $dltable->is_downloading($download, 'tiempos_resumen');
            $dltable->setup();
            foreach ($dedication as $d) {
                $dltable->add_data([
                    $d->coursename,
                    $d->shortname,
                    $d->timeformatted,
                    count($d->sessions),
                ]);
            }
            $dltable->finish_output();
        }
    }
}

// ── Descarga nativa de entregas ───────────────────────────────────────────
if ($download && $tab === 'assign' && $searched && ($userid > 0 || $courseid > 0)) {
    $submissions = local_audit_get_submissions($userid, $courseid);
    $fs  = get_file_storage();
    $dl  = new flexible_table('local-audit-assign-dl');
    $dl->define_columns(['student','username','email','userstatus','course','coursecode',
                         'assignment','submissionstatus','timecreated','timemodified','files']);
    $dl->define_headers([
        get_string('student','local_audit'), get_string('username','local_audit'),
        get_string('email','local_audit'),   get_string('userstatus','local_audit'),
        get_string('course','local_audit'),  get_string('shortname','local_audit'),
        get_string('assignment','local_audit'), get_string('submissionstatus','local_audit'),
        get_string('timecreated','local_audit'), get_string('timemodified','local_audit'),
        get_string('files','local_audit'),
    ]);
    $dl->is_downloading($download, 'entregas');
    $dl->setup();
    foreach ($submissions as $sub) {
        $context   = context_module::instance($sub->cmid);
        $files     = $fs->get_area_files($context->id, 'assignsubmission_file',
                         'submission_files', $sub->subid, 'filename', false);
        $fileparts = [];
        foreach ($files as $f) {
            $fileparts[] = $f->get_filename() . ' (' . display_size($f->get_filesize()) . ')';
        }
        $dl->add_data([
            fullname($sub), $sub->username, $sub->email,
            $sub->suspended ? get_string('suspended','local_audit') : get_string('active','local_audit'),
            $sub->coursename, $sub->courseshortname, $sub->assignname,
            local_audit_status_text($sub->status),
            $sub->timecreated  ? userdate($sub->timecreated)  : '',
            $sub->timemodified ? userdate($sub->timemodified) : '',
            implode('; ', $fileparts),
        ]);
    }
    $dl->finish_output();
}

// ── Descarga nativa de exámenes ───────────────────────────────────────────
if ($download && $tab === 'quiz' && $searched && ($userid > 0 || $courseid > 0)) {
    $quizattempts = local_audit_get_quiz_attempts($userid, $courseid);
    $dl = new flexible_table('local-audit-quiz-dl');
    $dl->define_columns(['student','username','email','userstatus','course','coursecode',
                         'quiz','attemptnum','quizstate','timestart','timefinish','grade']);
    $dl->define_headers([
        get_string('student','local_audit'),    get_string('username','local_audit'),
        get_string('email','local_audit'),      get_string('userstatus','local_audit'),
        get_string('course','local_audit'),     get_string('shortname','local_audit'),
        get_string('quiz','local_audit'),       get_string('attemptnum','local_audit'),
        get_string('quizstate','local_audit'),  get_string('timestart','local_audit'),
        get_string('timefinish','local_audit'), get_string('grade','local_audit'),
    ]);
    $dl->is_downloading($download, 'examenes');
    $dl->setup();
    foreach ($quizattempts as $att) {
        $dl->add_data([
            fullname($att), $att->username, $att->email,
            $att->suspended ? get_string('suspended','local_audit') : get_string('active','local_audit'),
            $att->coursename, $att->courseshortname, $att->quizname,
            (int)$att->attemptnum, local_audit_quiz_state_text($att->state),
            $att->timestart  ? userdate($att->timestart)  : '',
            $att->timefinish ? userdate($att->timefinish) : '',
            local_audit_quiz_grade($att),
        ]);
    }
    $dl->finish_output();
}

// ── Descarga nativa de foros ──────────────────────────────────────────────
if ($download && $tab === 'forum' && $searched && ($userid > 0 || $courseid > 0)) {
    $forumposts = local_audit_get_forum_posts($userid, $courseid);
    $dl = new flexible_table('local-audit-forum-dl');
    $dl->define_columns(['student','username','email','userstatus','course','coursecode',
                         'forum','discussion','postsubject','timecreated','message']);
    $dl->define_headers([
        get_string('student','local_audit'),     get_string('username','local_audit'),
        get_string('email','local_audit'),        get_string('userstatus','local_audit'),
        get_string('course','local_audit'),       get_string('shortname','local_audit'),
        get_string('forum','local_audit'),        get_string('discussion','local_audit'),
        get_string('postsubject','local_audit'),  get_string('timecreated','local_audit'),
        get_string('message','local_audit'),
    ]);
    $dl->is_downloading($download, 'foros');
    $dl->setup();
    foreach ($forumposts as $post) {
        $dl->add_data([
            fullname($post), $post->username, $post->email,
            $post->suspended ? get_string('suspended','local_audit') : get_string('active','local_audit'),
            $post->coursename, $post->courseshortname, $post->forumname,
            $post->discussionname, $post->postsubject,
            userdate($post->created),
            strip_tags($post->message),
        ]);
    }
    $dl->finish_output();
}

// ── Formulario de fechas (debe procesarse antes del header) ───────────────
$dateform = new \local_audit\form\datefilter_form(new moodle_url('/local/audit/index.php'));
$dateform->set_data([
    'userid'   => $userid,
    'courseid' => $courseid,
    'searched' => 1,
    'datefrom' => $mintime,
    'dateto'   => $maxtime,
]);
if ($formdata = $dateform->get_data()) {
    redirect(new moodle_url('/local/audit/index.php', [
        'userid'   => $formdata->userid,
        'courseid' => $formdata->courseid,
        'searched' => 1,
        'tab'      => 'time',
        'mintime'  => $formdata->datefrom ?: 0,
        'maxtime'  => $formdata->dateto   ?: 0,
    ]));
}

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
        // Ejecutar las consultas de las tres primeras pestañas.
        $submissions  = local_audit_get_submissions($userid, $courseid);
        $quizattempts = local_audit_get_quiz_attempts($userid, $courseid);
        $forumposts   = local_audit_get_forum_posts($userid, $courseid);

        // Parámetros comunes para URLs.
        $urlparams = ['userid' => $userid, 'courseid' => $courseid, 'searched' => 1,
                      'mintime' => $mintime, 'maxtime' => $maxtime];

        // ── Pestañas ─────────────────────────────────────────────────────
        $tabs = [
            new tabobject(
                'time',
                new moodle_url('/local/audit/index.php', $urlparams + ['tab' => 'time']),
                get_string('tabtime', 'local_audit')
            ),
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
            if (empty($submissions)) {
                echo $OUTPUT->notification(get_string('noresults', 'local_audit'), 'warning');
            } else {
                $fs    = get_file_storage();
                $table = new flexible_table('local-audit-assign-' . $userid . '-' . $courseid);
                $table->define_columns(['student','username','email','userstatus','course',
                                        'assignment','submissionstatus','timecreated','timemodified','files']);
                $table->define_headers([
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
                ]);
                $table->define_baseurl(new moodle_url('/local/audit/index.php', $urlparams + ['tab' => 'assign']));
                $table->pageable(true);
                $table->is_downloadable(true);
                $table->show_download_buttons_at([TABLE_P_BOTTOM]);
                $table->set_attribute('class', 'generaltable table-sm');
                $table->setup();
                $table->pagesize(LOCAL_AUDIT_PERPAGE, count($submissions));

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

                    $table->add_data([
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
                }
                $table->finish_output();
            }
        }

        // ── Pestaña Exámenes ──────────────────────────────────────────────
        if ($tab === 'quiz') {
            if (empty($quizattempts)) {
                echo $OUTPUT->notification(get_string('noresults', 'local_audit'), 'warning');
            } else {
                $table = new flexible_table('local-audit-quiz-' . $userid . '-' . $courseid);
                $table->define_columns(['student','username','email','userstatus','course',
                                        'quiz','attemptnum','quizstate','timestart','timefinish','grade']);
                $table->define_headers([
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
                ]);
                $table->define_baseurl(new moodle_url('/local/audit/index.php', $urlparams + ['tab' => 'quiz']));
                $table->pageable(true);
                $table->is_downloadable(true);
                $table->show_download_buttons_at([TABLE_P_BOTTOM]);
                $table->set_attribute('class', 'generaltable table-sm');
                $table->setup();
                $table->pagesize(LOCAL_AUDIT_PERPAGE, count($quizattempts));

                foreach ($quizattempts as $att) {
                    $userstatus = $att->suspended
                        ? html_writer::tag('span', get_string('suspended', 'local_audit'), ['class' => 'badge badge-danger bg-danger text-white'])
                        : html_writer::tag('span', get_string('active',    'local_audit'), ['class' => 'badge badge-success bg-success text-white']);

                    $table->add_data([
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
                }
                $table->finish_output();
            }
        }

        // ── Pestaña Tiempo ────────────────────────────────────────────────
        if ($tab === 'time') {
            if (!local_audit_dedication_available()) {
                echo $OUTPUT->notification(get_string('dedicationnotavailable', 'local_audit'), 'warning');
            } else if ($userid <= 0) {
                echo $OUTPUT->notification(get_string('dedicationneedsuser', 'local_audit'), 'info');
            } else {
                // Selector de rango de fechas nativo de Moodle.
                $dateform->display();

                $dedication = local_audit_get_dedication($userid, $courseid, $mintime, $maxtime);

                if (empty($dedication)) {
                    echo $OUTPUT->notification(get_string('noresults', 'local_audit'), 'warning');
                } else {
                    $sessionlimit = get_config('block_dedication', 'session_limit');
                    $limitmin     = round($sessionlimit / 60);
                    echo html_writer::tag('p',
                        get_string('dedicationinfo', 'local_audit', $limitmin),
                        ['class' => 'text-muted small']
                    );

                    $tsort = optional_param('tsort', '', PARAM_ALPHANUMEXT);
                    $tdir  = optional_param('tdir',  SORT_DESC, PARAM_INT);

                    // Si hay un único curso, mostrar también el detalle de sesiones.
                    if ($courseid > 0 && count($dedication) === 1) {
                        $coursedata = reset($dedication);

                        echo html_writer::tag('h5',
                            get_string('totaltime', 'local_audit') . ': ' .
                            html_writer::tag('strong', $coursedata->timeformatted)
                        );

                        if (!empty($coursedata->sessions)) {
                            $defaultsort = $tsort ?: 'sessionstart';
                            $sessions_sorted = array_values($coursedata->sessions);
                            usort($sessions_sorted, function($a, $b) use ($defaultsort, $tdir) {
                                $cmp = ($defaultsort === 'sessionduration')
                                    ? ($a->dedicationtime <=> $b->dedicationtime)
                                    : ($a->start_date <=> $b->start_date);
                                return ($tdir == SORT_ASC) ? $cmp : -$cmp;
                            });

                            $stable = new flexible_table('local-audit-sessions-' . $userid . '-' . $courseid);
                            $stable->define_columns(['sessionstart', 'sessionduration']);
                            $stable->define_headers([
                                get_string('sessionstart',    'local_audit'),
                                get_string('sessionduration', 'local_audit'),
                            ]);
                            $stable->define_baseurl(new moodle_url('/local/audit/index.php',
                                $urlparams + ['tab' => 'time', 'courseid' => $courseid]));
                            $stable->sortable(true, 'sessionstart', SORT_DESC);
                            $stable->pageable(true);
                            $stable->is_downloadable(true);
                            $stable->show_download_buttons_at([TABLE_P_BOTTOM]);
                            $stable->set_attribute('class', 'generaltable table-sm');
                            $stable->setup();
                            $stable->pagesize(LOCAL_AUDIT_PERPAGE, count($sessions_sorted));

                            foreach ($sessions_sorted as $session) {
                                $stable->add_data([
                                    userdate($session->start_date),
                                    \block_dedication\lib\utils::format_dedication($session->dedicationtime),
                                ]);
                            }
                            $stable->finish_output();
                        }
                    } else {
                        // Vista resumen: un curso por fila.
                        $defaultsort = $tsort ?: 'totaltime';
                        usort($dedication, function($a, $b) use ($defaultsort, $tdir) {
                            switch ($defaultsort) {
                                case 'course':   $cmp = strcmp($a->coursename, $b->coursename); break;
                                case 'sessions': $cmp = count($a->sessions) <=> count($b->sessions); break;
                                default:         $cmp = $a->timesecs <=> $b->timesecs; break;
                            }
                            return ($tdir == SORT_ASC) ? $cmp : -$cmp;
                        });

                        $table = new flexible_table('local-audit-dedication-' . $userid);
                        $table->define_columns(['course', 'totaltime', 'sessions']);
                        $table->define_headers([
                            get_string('course',    'local_audit'),
                            get_string('totaltime', 'local_audit'),
                            get_string('sessions',  'local_audit'),
                        ]);
                        $table->define_baseurl(new moodle_url('/local/audit/index.php',
                            $urlparams + ['tab' => 'time']));
                        $table->sortable(true, 'totaltime', SORT_DESC);
                        $table->no_sorting('sessions');
                        $table->pageable(true);
                        $table->is_downloadable(true);
                        $table->show_download_buttons_at([TABLE_P_BOTTOM]);
                        $table->set_attribute('class', 'generaltable table-sm');
                        $table->setup();
                        $table->pagesize(LOCAL_AUDIT_PERPAGE, count($dedication));

                        foreach ($dedication as $d) {
                            $courseurl = new moodle_url('/course/view.php', ['id' => $d->courseid]);
                            $detailurl = new moodle_url('/local/audit/index.php',
                                $urlparams + ['tab' => 'time', 'courseid' => $d->courseid]);

                            $table->add_data([
                                html_writer::link($courseurl, s($d->coursename)) .
                                    html_writer::tag('br',
                                        html_writer::tag('small', s($d->shortname), ['class' => 'text-muted'])),
                                html_writer::tag('strong', $d->timeformatted),
                                html_writer::tag('span', count($d->sessions)) . ' ' .
                                    html_writer::link($detailurl,
                                        get_string('viewsessions', 'local_audit'),
                                        ['class' => 'btn btn-sm btn-outline-secondary ml-2']),
                            ]);
                        }
                        $table->finish_output();
                    }
                }
            }
        }

        // ── Pestaña Foros ─────────────────────────────────────────────────
        if ($tab === 'forum') {
            if (empty($forumposts)) {
                echo $OUTPUT->notification(get_string('noresults', 'local_audit'), 'warning');
            } else {
                $table = new flexible_table('local-audit-forum-' . $userid . '-' . $courseid);
                $table->define_columns(['student','username','email','userstatus','course',
                                        'forum','discussion','postsubject','timecreated','message']);
                $table->define_headers([
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
                ]);
                $table->define_baseurl(new moodle_url('/local/audit/index.php', $urlparams + ['tab' => 'forum']));
                $table->pageable(true);
                $table->is_downloadable(true);
                $table->show_download_buttons_at([TABLE_P_BOTTOM]);
                $table->set_attribute('class', 'generaltable table-sm');
                $table->setup();
                $table->pagesize(LOCAL_AUDIT_PERPAGE, count($forumposts));

                foreach ($forumposts as $post) {
                    $userstatus = $post->suspended
                        ? html_writer::tag('span', get_string('suspended', 'local_audit'), ['class' => 'badge badge-danger bg-danger text-white'])
                        : html_writer::tag('span', get_string('active',    'local_audit'), ['class' => 'badge badge-success bg-success text-white']);

                    $discussionurl = new moodle_url('/mod/forum/discuss.php', ['d' => $post->discussionid]);
                    $forumurl      = new moodle_url('/mod/forum/view.php',    ['id' => $post->cmid]);

                    $table->add_data([
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
                }
                $table->finish_output();
            }
        }
    }
}

echo $OUTPUT->footer();
