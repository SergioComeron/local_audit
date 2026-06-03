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

// ── POST del selector múltiple de usuarios del grupo → redirect GET ───────
// Debe ejecutarse antes de los optional_param porque groupuserids llega como
// array ($_POST['groupuserids'][]) y optional_param no admite arrays.
if (!empty($_POST['groupuserids']) && is_array($_POST['groupuserids'])) {
    $posted = array_values(array_filter(array_map('intval', $_POST['groupuserids'])));
    redirect(new moodle_url('/local/audit/index.php', [
        'mode'         => 'group',
        'courseid'     => (int)($_POST['courseid'] ?? 0),
        'searched'     => 1,
        'groupuserids' => implode(',', $posted),
        'mintime'      => (int)($_POST['mintime']  ?? 0),
        'maxtime'      => (int)($_POST['maxtime']  ?? 0),
    ]));
}

$mode     = optional_param('mode', 'individual', PARAM_ALPHA);
$userid   = optional_param('userid',   0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$searched = optional_param('searched', 0, PARAM_INT);
$tab      = optional_param('tab', 'time', PARAM_ALPHA);
$page     = optional_param('page',     0, PARAM_INT);
$mintime  = optional_param('mintime',  0, PARAM_INT);
$maxtime  = optional_param('maxtime',  0, PARAM_INT);
$download        = optional_param('download', '', PARAM_ALPHA);
$groupuserids_str = optional_param('groupuserids', '', PARAM_SEQUENCE);
$groupuserids     = $groupuserids_str
    ? array_values(array_filter(array_map('intval', explode(',', $groupuserids_str))))
    : [];

define('LOCAL_AUDIT_PERPAGE', 50);

// ── Descarga nativa de tabla de tiempos (antes del header HTML) ───────────
if ($download && $tab === 'time' && $searched && $userid > 0 && local_audit_dedication_available()) {
    $tsort      = optional_param('tsort', '', PARAM_ALPHANUMEXT);
    $tdir       = optional_param('tdir', SORT_DESC, PARAM_INT);
    $dedication = local_audit_get_dedication($userid, $courseid, $mintime, $maxtime);

    if (!empty($dedication)) {
        if ($courseid > 0 && count($dedication) === 1) {
            // Tabla unificada por día: Moodle + Zoom.
            // Seguro llamar a Zoom aquí — no hay output HTML todavía.
            $coursedata = reset($dedication);

            $moodleByDay = [];
            foreach ($coursedata->sessions as $session) {
                $day = date('Y-m-d', $session->start_date);
                $moodleByDay[$day] = ($moodleByDay[$day] ?? 0) + (int)$session->dedicationtime;
            }

            $zoomByDay = [];
            if (local_audit_zoom_available()) {
                foreach (local_audit_get_zoom_sessions($userid, $courseid, $mintime, $maxtime) as $zs) {
                    if (empty($zs['fechaInicio'])) continue;
                    $day = date('Y-m-d', $zs['fechaInicio']);
                    if (!isset($zoomByDay[$day])) $zoomByDay[$day] = ['live' => 0, 'rec' => 0];
                    $zoomByDay[$day]['live'] += (int)($zs['tiempoSesion'] ?? 0) * 60; // minutos → segundos
                    foreach ($zs['grabaciones'] ?? [] as $g) {
                        $zoomByDay[$day]['rec'] += (int)($g['tiempoVisto'] ?? 0);
                    }
                }
            }

            $hasZoom  = !empty($zoomByDay);
            $allDays  = array_unique(array_merge(array_keys($moodleByDay), array_keys($zoomByDay)));
            rsort($allDays);

            $dlcols = ['day', 'moodletime'];
            $dlhdrs = [get_string('day', 'local_audit'), get_string('moodletime', 'local_audit')];
            if ($hasZoom) {
                $dlcols[] = 'zoomlivetime';
                $dlcols[] = 'zoomrecordingtime';
                $dlhdrs[] = get_string('zoomlivetime',      'local_audit');
                $dlhdrs[] = get_string('zoomrecordingtime', 'local_audit');
            }

            $dltable = new flexible_table('local-audit-unified-' . $userid . '-' . $courseid);
            $dltable->define_columns($dlcols);
            $dltable->define_headers($dlhdrs);
            $dltable->is_downloading($download, 'tiempos_' . $courseid);
            $dltable->setup();

            $totM = $totL = $totR = 0;
            foreach ($allDays as $day) {
                $m = $moodleByDay[$day] ?? 0;
                $l = $zoomByDay[$day]['live'] ?? 0;
                $r = $zoomByDay[$day]['rec']  ?? 0;
                $totM += $m; $totL += $l; $totR += $r;
                $row = [
                    date('d/m/Y', strtotime($day)),
                    \block_dedication\lib\utils::format_dedication($m),
                ];
                if ($hasZoom) {
                    $row[] = $l > 0 ? local_audit_format_secs($l) : '—';
                    $row[] = $r > 0 ? local_audit_format_secs($r) : '—';
                }
                $dltable->add_data($row);
            }
            // Fila de totales.
            $totalRow = [get_string('total', 'moodle'), \block_dedication\lib\utils::format_dedication($totM)];
            if ($hasZoom) {
                $totalRow[] = $totL > 0 ? local_audit_format_secs($totL) : '—';
                $totalRow[] = $totR > 0 ? local_audit_format_secs($totR) : '—';
            }
            $dltable->add_data($totalRow);
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
                         'assignment','submissionstatus','timecreated','timemodified','files','feedbackfiles']);
    $dl->define_headers([
        get_string('student','local_audit'), get_string('username','local_audit'),
        get_string('email','local_audit'),   get_string('userstatus','local_audit'),
        get_string('course','local_audit'),  get_string('shortname','local_audit'),
        get_string('assignment','local_audit'), get_string('submissionstatus','local_audit'),
        get_string('timecreated','local_audit'), get_string('timemodified','local_audit'),
        get_string('files','local_audit'), get_string('feedbackfiles','local_audit'),
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
        $feedbackparts = [];
        foreach (local_audit_get_feedback_files($context->id, $sub->assignid, $sub->userid) as $f) {
            $feedbackparts[] = $f->get_filename() . ' (' . display_size($f->get_filesize()) . ')';
        }
        $dl->add_data([
            fullname($sub), $sub->username, $sub->email,
            $sub->suspended ? get_string('suspended','local_audit') : get_string('active','local_audit'),
            $sub->coursename, $sub->courseshortname, $sub->assignname,
            local_audit_status_text($sub->status),
            $sub->timecreated  ? userdate($sub->timecreated)  : '',
            $sub->timemodified ? userdate($sub->timemodified) : '',
            implode('; ', $fileparts),
            implode('; ', $feedbackparts),
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

// ── Descarga nativa del informe de grupo ─────────────────────────────────
if ($download && $mode === 'group' && $searched && !empty($groupuserids)) {
    $grouprows = local_audit_get_group_dedication($groupuserids, $courseid, $mintime, $maxtime);
    $dl = new flexible_table('local-audit-group-dl');
    $dl->define_columns(['student','username','email','userstatus','course','shortname','totaltime','sessions']);
    $dl->define_headers([
        get_string('student',    'local_audit'), get_string('username',  'local_audit'),
        get_string('email',      'local_audit'), get_string('userstatus','local_audit'),
        get_string('course',     'local_audit'), get_string('shortname', 'local_audit'),
        get_string('totaltime',  'local_audit'), get_string('sessions',  'local_audit'),
    ]);
    $dl->is_downloading($download, 'informe_grupo');
    $dl->setup();
    foreach ($grouprows as $r) {
        $dl->add_data([
            fullname($r), $r->username, '',
            $r->suspended ? get_string('suspended','local_audit') : get_string('active','local_audit'),
            $r->coursename, $r->shortname, $r->timeformatted, $r->sessioncount,
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

// ── Inicializar core/form-autocomplete según el modo ──────────────────────
if ($mode !== 'group') {
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
}

// ── Salida HTML ───────────────────────────────────────────────────────────
// Forzar inicialización del tema ANTES de que header() cargue los bloques.
// Sin esto, el hook before_footer_html_generation de block_ai_chat accede a
// $PAGE->theme durante el footer, lo que dispara initialise_theme_and_output()
// cuando los bloques ya están cargados y lanza una coding_exception.
$PAGE->theme;
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_audit'));

// ── Tabs de modo: Individual / Grupo ─────────────────────────────────────
$modetabs = [
    new tabobject('individual',
        new moodle_url('/local/audit/index.php', ['mode' => 'individual']),
        get_string('tabindividual', 'local_audit')
    ),
    new tabobject('group',
        new moodle_url('/local/audit/index.php', ['mode' => 'group']),
        get_string('tabgroup', 'local_audit')
    ),
];
echo $OUTPUT->tabtree($modetabs, $mode === 'group' ? 'group' : 'individual');

if ($mode !== 'group') {
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
        $urlparams = ['mode' => 'individual', 'userid' => $userid, 'courseid' => $courseid,
                      'searched' => 1, 'mintime' => $mintime, 'maxtime' => $maxtime];

        // ── Pestañas individuales ─────────────────────────────────────────
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
                                        'assignment','submissionstatus','timecreated','timemodified','files','feedbackfiles']);
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
                    get_string('feedbackfiles',    'local_audit'),
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

                    // Ficheros de corrección del profesor: ficheros subidos (assignfeedback_file)
                    // y/o PDF anotado de EditPDF, recogidos de todas las calificaciones del usuario.
                    $feedbackcell  = get_string('nofeedbackfiles', 'local_audit');
                    $feedbackfiles = local_audit_get_feedback_files($context->id, $sub->assignid, $sub->userid);
                    if (!empty($feedbackfiles)) {
                        $fblist = [];
                        foreach ($feedbackfiles as $file) {
                            $dlurl  = new moodle_url('/local/audit/download.php', ['fileid' => $file->get_id()]);
                            $label  = html_writer::tag('strong', $file->get_filename());
                            $meta   = html_writer::tag('small',
                                ' (' . display_size($file->get_filesize()) . ', ' . $file->get_mimetype() . ')',
                                ['class' => 'text-muted']);
                            $dllink = html_writer::link($dlurl, get_string('download', 'local_audit'),
                                ['class' => 'btn btn-sm btn-outline-success ml-1']);
                            $fblist[] = html_writer::tag('li', $label . $meta . $dllink, ['class' => 'mb-1']);
                        }
                        $feedbackcell = html_writer::tag('ul', implode('', $fblist),
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
                        $feedbackcell,
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

                        // ── Tabla unificada por día: Moodle + Zoom ────────────────────
                        // Agrupa las sesiones Moodle por día para combinarlas con Zoom.
                        $moodleByDay = [];
                        foreach ($coursedata->sessions as $session) {
                            $day = date('Y-m-d', $session->start_date);
                            if (!isset($moodleByDay[$day])) {
                                $moodleByDay[$day] = 0;
                            }
                            $moodleByDay[$day] += (int)$session->dedicationtime;
                        }

                        $unifiedStrs = json_encode([
                            'day'               => get_string('day',               'local_audit'),
                            'moodletime'        => get_string('moodletime',        'local_audit'),
                            'zoomlivetime'      => get_string('zoomlivetime',      'local_audit'),
                            'zoomrecordingtime' => get_string('zoomrecordingtime', 'local_audit'),
                            'totaltime'         => get_string('totaltime',         'local_audit'),
                            'total'             => get_string('total',             'moodle'),
                            'noresults'         => get_string('noresults',         'local_audit'),
                            'loading'           => get_string('loading',           'core'),
                        ]);
                        $moodleJson  = json_encode($moodleByDay);
                        $zoomAvailJs = local_audit_zoom_available() ? 'true' : 'false';
                        $zoomAjaxUrl = (new moodle_url('/local/audit/ajax_zoom.php'))->out(false);
                        $zoomSesskey = sesskey();

                        // Botones de descarga nativa de Moodle.
                        $dlBase = new moodle_url('/local/audit/index.php',
                            $urlparams + ['tab' => 'time', 'courseid' => $courseid, 'searched' => 1]);
                        echo html_writer::start_div('mt-2 mb-1');
                        foreach (['csv' => 'CSV', 'excel' => 'Excel', 'ods' => 'ODS'] as $fmt => $label) {
                            $dlBase->param('download', $fmt);
                            echo html_writer::link($dlBase,
                                $OUTPUT->pix_icon('t/download', '') . ' ' . $label,
                                ['class' => 'btn btn-sm btn-outline-secondary mr-1']);
                        }
                        echo html_writer::end_div();

                        echo <<<HTML
<div id="unified-table-wrap" class="mt-3">
  <span class="spinner-border spinner-border-sm mr-1"></span>
  <span class="text-muted" id="unified-loading-text"></span>
</div>
<script>
(function() {
    var strs      = {$unifiedStrs};
    var moodle    = {$moodleJson};   // {day: secs, ...}
    var zoomAvail = {$zoomAvailJs};
    var wrap      = document.getElementById('unified-table-wrap');

    // Formatea segundos → "Xh Ymin" / "Ymin" / "—"
    function fmt(s) {
        if (!s) return '—';
        var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
        return (h > 0 ? h + 'h ' : '') + m + 'min';
    }
    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function render(zoomSessions) {
        // Acumula Zoom por día
        var zoom = {};
        (zoomSessions || []).forEach(function(zs) {
            if (!zs.fechaInicio) return;
            var d   = new Date(zs.fechaInicio * 1000);
            var day = d.getFullYear() + '-'
                + String(d.getMonth()+1).padStart(2,'0') + '-'
                + String(d.getDate()).padStart(2,'0');
            if (!zoom[day]) zoom[day] = {live: 0, rec: 0};
            zoom[day].live += (zs.tiempoSesion || 0) * 60; // API devuelve minutos, fmt() espera segundos
            (zs.grabaciones || []).forEach(function(g) {
                zoom[day].rec += g.tiempoVisto || 0;
            });
        });

        // Combina todos los días
        var allDays = Object.keys(moodle).concat(Object.keys(zoom))
            .filter(function(v, i, a) { return a.indexOf(v) === i; })
            .sort().reverse();

        if (allDays.length === 0) {
            wrap.innerHTML = '<p class="text-muted">' + esc(strs.noresults) + '</p>';
            return;
        }

        var hasZoom = Object.keys(zoom).length > 0;
        var totMoodle = 0, totLive = 0, totRec = 0;

        var cols = '<th>' + esc(strs.day) + '</th>'
                 + '<th>' + esc(strs.moodletime) + '</th>';
        if (hasZoom) {
            cols += '<th>' + esc(strs.zoomlivetime) + '</th>'
                  + '<th>' + esc(strs.zoomrecordingtime) + '</th>';
        }

        var rows = '';
        allDays.forEach(function(day) {
            var m = moodle[day] || 0;
            var l = hasZoom ? (zoom[day] ? zoom[day].live : 0) : 0;
            var r = hasZoom ? (zoom[day] ? zoom[day].rec  : 0) : 0;
            totMoodle += m; totLive += l; totRec += r;

            // Formato de fecha legible
            var parts = day.split('-');
            var dateStr = parts[2] + '/' + parts[1] + '/' + parts[0];

            rows += '<tr>'
                + '<td>' + dateStr + '</td>'
                + '<td>' + fmt(m) + '</td>';
            if (hasZoom) {
                rows += '<td>' + fmt(l) + '</td>'
                      + '<td>' + fmt(r) + '</td>';
            }
            rows += '</tr>';
        });

        // Fila de totales
        var totalRow = '<tr class="font-weight-bold" style="border-top:2px solid #dee2e6">'
            + '<td><strong>' + esc(strs.total) + '</strong></td>'
            + '<td><strong>' + fmt(totMoodle) + '</strong></td>';
        if (hasZoom) {
            totalRow += '<td><strong>' + fmt(totLive) + '</strong></td>'
                      + '<td><strong>' + fmt(totRec)  + '</strong></td>';
        }
        totalRow += '</tr>';

        wrap.innerHTML = '<table class="generaltable table table-sm">'
            + '<thead><tr>' + cols + '</tr></thead>'
            + '<tbody>' + rows + totalRow + '</tbody>'
            + '</table>';
    }

    if (!zoomAvail) {
        render([]);
    } else {
        document.getElementById('unified-loading-text').textContent = strs.loading;
        var url = '{$zoomAjaxUrl}?userid={$userid}&courseid={$courseid}'
                + '&mintime={$mintime}&maxtime={$maxtime}&sesskey={$zoomSesskey}';
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) { render(data.sessions || []); })
            .catch(function() { render([]); });
    }
}());
</script>
HTML;
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
} else {
    // ── Modo Grupo ────────────────────────────────────────────────────────
    if (!local_audit_dedication_available()) {
        echo $OUTPUT->notification(get_string('dedicationnotavailable', 'local_audit'), 'warning');
    } else {
        $groupurlparams = ['mode' => 'group', 'courseid' => $courseid, 'searched' => 1,
                           'mintime' => $mintime, 'maxtime' => $maxtime,
                           'groupuserids' => $groupuserids_str];

        // Precargar las opciones de los usuarios ya seleccionados.
        $groupuseroptions = [];
        foreach ($groupuserids as $guid) {
            $gu = $DB->get_record('user', ['id' => $guid, 'deleted' => 0],
                'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, username');
            if ($gu) {
                $groupuseroptions[$guid] = fullname($gu) . ' (' . $gu->username . ')';
            }
        }

        // Formulario multi-usuario (POST → redirect GET).
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => (new moodle_url('/local/audit/index.php'))->out(false),
            'class'  => 'mb-3',
        ]);
        foreach (['courseid' => $courseid, 'mintime' => $mintime, 'maxtime' => $maxtime] as $k => $v) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
        }
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label',
            get_string('searchuser', 'local_audit'),
            ['for' => 'groupuserids', 'class' => 'col-form-label d-block mb-1']);
        echo html_writer::select(
            $groupuseroptions,
            'groupuserids[]',
            array_keys($groupuseroptions),
            false,
            ['id' => 'groupuserids', 'multiple' => 'multiple', 'class' => 'form-control']
        );
        echo html_writer::end_div();
        echo html_writer::tag('button',
            get_string('search', 'local_audit'),
            ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
        echo html_writer::end_tag('form');

        // Inicializar autocomplete múltiple para el selector de grupo.
        $PAGE->requires->js_call_amd('core/form-autocomplete', 'enhance', [
            '#groupuserids',
            false,
            'local_audit/usersearch',
            get_string('searchuser', 'local_audit'),
            false,
            true,
            get_string('noselection', 'local_audit'),
        ]);

        // Tabla de resultados cargada por AJAX (una petición por usuario en paralelo).
        if (!empty($groupuserids)) {
            $ajaxurl  = (new moodle_url('/local/audit/ajax_group.php'))->out(false);
            $wwwroot  = $CFG->wwwroot;
            $sesskey  = sesskey();

            // Cadenas traducidas que necesita el JS.
            $jsstrs = json_encode([
                'suspended'   => get_string('suspended',    'local_audit'),
                'active'      => get_string('active',       'local_audit'),
                'viewsessions'=> get_string('viewsessions', 'local_audit'),
                'noresults'   => get_string('noresults',    'local_audit'),
                'loading'     => get_string('loading',      'core'),
                'zoomtime'    => get_string('zoomtime',     'local_audit'),
            ]);
            $zoomavail = local_audit_zoom_available() ? 'true' : 'false';

            // Esqueleto de tabla — el cuerpo lo rellena JS.
            // La columna Zoom se añade dinámicamente si el servidor la devuelve.
            echo html_writer::start_div('', ['id' => 'group-loading', 'class' => 'text-muted mb-2']);
            echo html_writer::tag('span', '', ['class' => 'spinner-border spinner-border-sm mr-1']);
            echo html_writer::tag('span', '', ['id' => 'group-progress']);
            echo html_writer::end_div();

            echo html_writer::start_tag('table', ['class' => 'generaltable table-sm w-100', 'id' => 'group-table']);
            echo html_writer::start_tag('thead');
            echo html_writer::start_tag('tr', ['id' => 'group-thead-row']);
            foreach (['student', 'username', 'userstatus', 'course', 'totaltime', 'sessions'] as $col) {
                echo html_writer::tag('th', get_string($col, 'local_audit'));
            }
            // La cabecera Zoom se añade desde JS cuando se confirma disponibilidad.
            echo html_writer::end_tag('tr');
            echo html_writer::end_tag('thead');
            echo html_writer::start_tag('tbody', ['id' => 'group-tbody']);
            echo html_writer::end_tag('tbody');
            echo html_writer::end_tag('table');

            // JS inline: carga cada usuario en paralelo y rellena la tabla.
            $useridsJson = json_encode($groupuserids);
            echo <<<HTML
<script>
(function() {
    var userids   = {$useridsJson};
    var courseid  = {$courseid};
    var mintime   = {$mintime};
    var maxtime   = {$maxtime};
    var ajaxUrl   = '{$ajaxurl}';
    var wwwroot   = '{$wwwroot}';
    var sesskey   = '{$sesskey}';
    var strs      = {$jsstrs};
    var zoomavail = {$zoomavail};
    var total     = userids.length;
    var done      = 0;
    var zoomColAdded = false;

    var tbody    = document.getElementById('group-tbody');
    var theadRow = document.getElementById('group-thead-row');
    var progress = document.getElementById('group-progress');
    var loading  = document.getElementById('group-loading');

    progress.textContent = '0 / ' + total;

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function addZoomHeader() {
        if (!zoomColAdded) {
            zoomColAdded = true;
            var th = document.createElement('th');
            th.textContent = strs.zoomtime;
            theadRow.appendChild(th);
        }
    }

    function loadUser(userid) {
        var params = 'userid=' + userid
            + '&courseid=' + courseid
            + '&mintime='  + mintime
            + '&maxtime='  + maxtime
            + '&sesskey='  + encodeURIComponent(sesskey);
        return fetch(ajaxUrl + '?' + params)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                done++;
                progress.textContent = done + ' / ' + total;

                if (data.error || !data.rows) return;

                var badge = data.suspended
                    ? '<span class="badge badge-danger bg-danger text-white">'   + esc(strs.suspended) + '</span>'
                    : '<span class="badge badge-success bg-success text-white">' + esc(strs.active)    + '</span>';

                // Si Zoom está disponible, añadir cabecera una sola vez.
                if (data.zoomavail) addZoomHeader();

                data.rows.forEach(function(row) {
                    var detailParams = 'mode=individual'
                        + '&userid='   + data.userid
                        + '&courseid=' + row.courseid
                        + '&searched=1&tab=time'
                        + '&mintime='  + mintime
                        + '&maxtime='  + maxtime;
                    var detailUrl = wwwroot + '/local/audit/index.php?' + detailParams;

                    var zoomCell = '';
                    if (data.zoomavail) {
                        zoomCell = '<td>' + (row.zoomfmt ? '<strong>' + esc(row.zoomfmt) + '</strong>' : '—') + '</td>';
                    }

                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td><a href="' + wwwroot + '/user/view.php?id=' + data.userid + '">' + esc(data.fullname) + '</a></td>' +
                        '<td>' + esc(data.username) + '</td>' +
                        '<td>' + badge + '</td>' +
                        '<td><a href="' + wwwroot + '/course/view.php?id=' + row.courseid + '">' + esc(row.coursename) + '</a>' +
                            '<br><small class="text-muted">' + esc(row.shortname) + '</small></td>' +
                        '<td><strong>' + esc(row.timeformatted) + '</strong></td>' +
                        '<td>' + row.sessioncount +
                            ' <a href="' + detailUrl + '" class="btn btn-sm btn-outline-secondary ml-1">' + esc(strs.viewsessions) + '</a></td>' +
                        zoomCell;
                    tbody.appendChild(tr);
                });
            });
    }

    Promise.all(userids.map(loadUser)).then(function() {
        loading.remove();
        if (tbody.children.length === 0) {
            var colspan = zoomColAdded ? 7 : 6;
            var tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="' + colspan + '" class="text-center text-muted">' + esc(strs.noresults) + '</td>';
            tbody.appendChild(tr);
        }
    });
}());
</script>
HTML;
        }
    }
}

echo $OUTPUT->footer();
