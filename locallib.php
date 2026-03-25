<?php
/**
 * Funciones internas del plugin local_audit.
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Devuelve las entregas de tipo assign filtradas por usuario y/o curso.
 * Incluye tanto usuarios activos como suspendidos; excluye eliminados.
 *
 * @param int $userid   0 = sin filtro por usuario.
 * @param int $courseid 0 = sin filtro por curso.
 * @return array Filas de resultado (stdClass).
 */
function local_audit_get_submissions(int $userid, int $courseid): array {
    global $DB;

    $conditions = ['s.latest = 1', 'u.deleted = 0'];
    $params     = [];

    if ($userid > 0) {
        $conditions[] = 's.userid = :userid';
        $params['userid'] = $userid;
    }
    if ($courseid > 0) {
        $conditions[] = 'a.course = :courseid';
        $params['courseid'] = $courseid;
    }

    $where = implode(' AND ', $conditions);

    $sql = "SELECT s.id          AS subid,
                   s.userid,
                   s.assignment  AS assignid,
                   s.status,
                   s.timecreated,
                   s.timemodified,
                   s.attemptnumber,
                   u.firstname,
                   u.lastname,
                   u.firstnamephonetic,
                   u.lastnamephonetic,
                   u.middlename,
                   u.alternatename,
                   u.username,
                   u.email,
                   u.suspended,
                   a.name        AS assignname,
                   a.course      AS courseid,
                   c.fullname    AS coursename,
                   c.shortname   AS courseshortname,
                   cm.id         AS cmid
              FROM {assign_submission} s
              JOIN {user}             u  ON  u.id       = s.userid
              JOIN {assign}           a  ON  a.id       = s.assignment
              JOIN {course}           c  ON  c.id       = a.course
              JOIN {course_modules}   cm ON  cm.course  = a.course
                                         AND cm.instance = a.id
                                         AND cm.module   = (SELECT id FROM {modules} WHERE name = 'assign')
             WHERE {$where}
          ORDER BY c.fullname, a.name, u.lastname, u.firstname";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Devuelve una etiqueta HTML con el estado de una entrega.
 *
 * @param string|null $status Valor del campo mdl_assign_submission.status.
 * @return string HTML.
 */
function local_audit_status_label(?string $status): string {
    switch ($status) {
        case 'submitted':
            return html_writer::tag('span',
                get_string('statussubmitted', 'local_audit'),
                ['class' => 'badge badge-success bg-success text-white']);
        case 'draft':
            return html_writer::tag('span',
                get_string('statusdraft', 'local_audit'),
                ['class' => 'badge badge-warning bg-warning text-dark']);
        case 'reopened':
            return html_writer::tag('span',
                get_string('statusreopened', 'local_audit'),
                ['class' => 'badge badge-info bg-info text-white']);
        default:
            return html_writer::tag('span',
                get_string('statusnew', 'local_audit'),
                ['class' => 'badge badge-secondary bg-secondary text-white']);
    }
}

/**
 * Devuelve la etiqueta de texto plano (para CSV) del estado de una entrega.
 *
 * @param string|null $status
 * @return string
 */
function local_audit_status_text(?string $status): string {
    switch ($status) {
        case 'submitted': return get_string('statussubmitted', 'local_audit');
        case 'draft':     return get_string('statusdraft',     'local_audit');
        case 'reopened':  return get_string('statusreopened',  'local_audit');
        default:          return get_string('statusnew',       'local_audit');
    }
}

// ── Tiempo (block_dedication) ─────────────────────────────────────────────

/**
 * Comprueba si el plugin block_dedication está instalado y disponible.
 *
 * @return bool
 */
function local_audit_dedication_available(): bool {
    return class_exists('\\block_dedication\\lib\\utils');
}

/**
 * Devuelve el tiempo estimado de un usuario por curso usando block_dedication.
 *
 * Si $courseid > 0 devuelve un único elemento con el detalle de sesiones.
 * Si $courseid = 0 devuelve todos los cursos en los que el usuario está matriculado.
 *
 * @param int $userid   Obligatorio (> 0).
 * @param int $courseid 0 = todos los cursos matriculados.
 * @return array        Objetos con: courseid, coursename, shortname, timesecs,
 *                      timeformatted, sessions (array de sesiones).
 */
function local_audit_get_dedication(int $userid, int $courseid, int $mintime = 0, int $maxtime = 0): array {
    global $DB;

    if (!local_audit_dedication_available()) {
        return [];
    }

    if ($courseid > 0) {
        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.fullname, c.shortname, c.startdate
               FROM {course} c
               JOIN {enrol} e            ON e.courseid = c.id
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
              WHERE ue.userid = :userid AND c.id = :courseid",
            ['userid' => $userid, 'courseid' => $courseid]
        );
    } else {
        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.fullname, c.shortname, c.startdate
               FROM {course} c
               JOIN {enrol} e            ON e.courseid = c.id
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
              WHERE ue.userid = :userid AND c.id <> 1
           ORDER BY c.fullname",
            ['userid' => $userid]
        );
    }

    $user    = $DB->get_record('user', ['id' => $userid]);
    $results = [];

    foreach ($courses as $course) {
        $manager  = new \block_dedication\lib\manager($course, $mintime ?: null, $maxtime ?: null);
        $sessions = $manager->get_user_dedication($user, false);

        $total = 0;
        foreach ($sessions as $s) {
            $total += $s->dedicationtime;
        }

        $results[] = (object)[
            'courseid'      => $course->id,
            'coursename'    => $course->fullname,
            'shortname'     => $course->shortname,
            'timesecs'      => $total,
            'timeformatted' => \block_dedication\lib\utils::format_dedication($total),
            'sessions'      => $sessions,
        ];
    }

    // Ordenar por tiempo descendente.
    usort($results, function ($a, $b) { return $b->timesecs - $a->timesecs; });

    return $results;
}

// ── Quiz ──────────────────────────────────────────────────────────────────

/**
 * Devuelve los intentos de quiz filtrados por usuario y/o curso.
 * Incluye usuarios suspendidos; excluye eliminados.
 *
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function local_audit_get_quiz_attempts(int $userid, int $courseid): array {
    global $DB;

    $conditions = ['u.deleted = 0'];
    $params     = [];

    if ($userid > 0) {
        $conditions[] = 'qa.userid = :userid';
        $params['userid'] = $userid;
    }
    if ($courseid > 0) {
        $conditions[] = 'q.course = :courseid';
        $params['courseid'] = $courseid;
    }

    $where = implode(' AND ', $conditions);

    $sql = "SELECT qa.id           AS attemptid,
                   qa.userid,
                   qa.attempt      AS attemptnum,
                   qa.state,
                   qa.timestart,
                   qa.timefinish,
                   qa.sumgrades,
                   q.sumgrades     AS maxsumgrades,
                   q.grade         AS maxgrade,
                   u.firstname,
                   u.lastname,
                   u.firstnamephonetic,
                   u.lastnamephonetic,
                   u.middlename,
                   u.alternatename,
                   u.username,
                   u.email,
                   u.suspended,
                   q.id            AS quizid,
                   q.name          AS quizname,
                   q.course        AS courseid,
                   c.fullname      AS coursename,
                   c.shortname     AS courseshortname,
                   cm.id           AS cmid
              FROM {quiz_attempts} qa
              JOIN {user}           u  ON  u.id       = qa.userid
              JOIN {quiz}           q  ON  q.id       = qa.quiz
              JOIN {course}         c  ON  c.id       = q.course
              JOIN {course_modules} cm ON  cm.course  = q.course
                                      AND  cm.instance = q.id
                                      AND  cm.module   = (SELECT id FROM {modules} WHERE name = 'quiz')
             WHERE {$where}
          ORDER BY c.fullname, q.name, u.lastname, u.firstname, qa.attempt";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Etiqueta HTML para el estado de un intento de quiz.
 *
 * @param string|null $state
 * @return string HTML
 */
function local_audit_quiz_state_label(?string $state): string {
    switch ($state) {
        case 'finished':
            return html_writer::tag('span',
                get_string('quizstatefinished', 'local_audit'),
                ['class' => 'badge badge-success bg-success text-white']);
        case 'inprogress':
            return html_writer::tag('span',
                get_string('quizstateinprogress', 'local_audit'),
                ['class' => 'badge badge-warning bg-warning text-dark']);
        case 'abandoned':
            return html_writer::tag('span',
                get_string('quizstateabandoned', 'local_audit'),
                ['class' => 'badge badge-danger bg-danger text-white']);
        default:
            return html_writer::tag('span',
                $state ?? '—',
                ['class' => 'badge badge-secondary bg-secondary text-white']);
    }
}

/**
 * Etiqueta de texto plano para el estado de un intento de quiz (para CSV).
 *
 * @param string|null $state
 * @return string
 */
function local_audit_quiz_state_text(?string $state): string {
    switch ($state) {
        case 'finished':    return get_string('quizstatefinished',    'local_audit');
        case 'inprogress':  return get_string('quizstateinprogress',  'local_audit');
        case 'abandoned':   return get_string('quizstateabandoned',   'local_audit');
        default:            return $state ?? '';
    }
}

/**
 * Formatea la puntuación de un intento (sumgrades / maxsumgrades).
 *
 * @param stdClass $attempt
 * @return string
 */
function local_audit_quiz_grade(stdClass $attempt): string {
    if ($attempt->maxsumgrades == 0 || $attempt->sumgrades === null) {
        return '—';
    }
    return round($attempt->sumgrades, 2) . ' / ' . round($attempt->maxsumgrades, 2);
}

// ── Foros ─────────────────────────────────────────────────────────────────

/**
 * Devuelve los posts de foro filtrados por usuario y/o curso.
 * Incluye usuarios suspendidos; excluye eliminados.
 *
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function local_audit_get_forum_posts(int $userid, int $courseid): array {
    global $DB;

    $conditions = ['u.deleted = 0', 'fp.deleted = 0'];
    $params     = [];

    if ($userid > 0) {
        $conditions[] = 'fp.userid = :userid';
        $params['userid'] = $userid;
    }
    if ($courseid > 0) {
        $conditions[] = 'f.course = :courseid';
        $params['courseid'] = $courseid;
    }

    $where = implode(' AND ', $conditions);

    $sql = "SELECT fp.id           AS postid,
                   fp.userid,
                   fp.created,
                   fp.modified,
                   fp.subject      AS postsubject,
                   fp.message,
                   u.firstname,
                   u.lastname,
                   u.firstnamephonetic,
                   u.lastnamephonetic,
                   u.middlename,
                   u.alternatename,
                   u.username,
                   u.email,
                   u.suspended,
                   fd.id           AS discussionid,
                   fd.name         AS discussionname,
                   f.id            AS forumid,
                   f.name          AS forumname,
                   f.course        AS courseid,
                   c.fullname      AS coursename,
                   c.shortname     AS courseshortname,
                   cm.id           AS cmid
              FROM {forum_posts}       fp
              JOIN {user}              u  ON  u.id       = fp.userid
              JOIN {forum_discussions} fd ON  fd.id      = fp.discussion
              JOIN {forum}             f  ON  f.id       = fd.forum
              JOIN {course}            c  ON  c.id       = f.course
              JOIN {course_modules}    cm ON  cm.course  = f.course
                                         AND  cm.instance = f.id
                                         AND  cm.module   = (SELECT id FROM {modules} WHERE name = 'forum')
             WHERE {$where}
          ORDER BY c.fullname, f.name, fp.created DESC";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Devuelve todos los tiempos de dedicación de los miembros de una cohorte,
 * opcionalmente filtrados por curso y rango de fechas.
 *
 * @param int $cohortid  ID de la cohorte.
 * @param int $courseid  0 = todos los cursos matriculados.
 * @param int $mintime   Timestamp de inicio (0 = sin límite).
 * @param int $maxtime   Timestamp de fin   (0 = sin límite).
 * @return array  Fila por usuario×curso con userid, name fields, courseid, timesecs, etc.
 */
function local_audit_get_group_dedication(int $cohortid, int $courseid = 0, int $mintime = 0, int $maxtime = 0): array {
    global $DB;

    if (!local_audit_dedication_available()) {
        return [];
    }

    $members = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                u.middlename, u.alternatename, u.username, u.suspended
           FROM {cohort_members} cm
           JOIN {user} u ON u.id = cm.userid AND u.deleted = 0
          WHERE cm.cohortid = :cohortid
       ORDER BY u.lastname, u.firstname",
        ['cohortid' => $cohortid]
    );

    if (empty($members)) {
        return [];
    }

    $result = [];
    foreach ($members as $user) {
        $dedication = local_audit_get_dedication($user->id, $courseid, $mintime, $maxtime);
        foreach ($dedication as $d) {
            $row                    = new stdClass();
            $row->userid            = (int)$user->id;
            $row->firstname         = $user->firstname;
            $row->lastname          = $user->lastname;
            $row->firstnamephonetic = $user->firstnamephonetic;
            $row->lastnamephonetic  = $user->lastnamephonetic;
            $row->middlename        = $user->middlename;
            $row->alternatename     = $user->alternatename;
            $row->username          = $user->username;
            $row->suspended         = $user->suspended;
            $row->courseid          = $d->courseid;
            $row->coursename        = $d->coursename;
            $row->shortname         = $d->shortname;
            $row->timesecs          = $d->timesecs;
            $row->timeformatted     = $d->timeformatted;
            $row->sessioncount      = count($d->sessions);
            $result[] = $row;
        }
    }
    return $result;
}
