<?php
/**
 * Función externa: obtiene tiempos de dedicación de un usuario.
 *
 * Parámetros:
 *   username        — nombre de usuario de Moodle.
 *   courseshortname — shortname del curso (vacío = todos los cursos matriculados).
 *   mintime         — timestamp de inicio del rango (0 = sin límite).
 *   maxtime         — timestamp de fin del rango   (0 = sin límite).
 */

namespace local_audit\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/audit/locallib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_multiple_structure;

class get_dedication extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username'         => new external_value(PARAM_USERNAME, 'Nombre de usuario de Moodle.'),
            'courseshortname'  => new external_value(PARAM_TEXT, 'Shortname del curso. Vacío = todos los cursos matriculados.', VALUE_DEFAULT, ''),
            'mintime'          => new external_value(PARAM_INT, 'Timestamp de inicio (0 = sin límite).', VALUE_DEFAULT, 0),
            'maxtime'          => new external_value(PARAM_INT, 'Timestamp de fin (0 = sin límite).',    VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(string $username, string $courseshortname = '', int $mintime = 0, int $maxtime = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'username'        => $username,
            'courseshortname' => $courseshortname,
            'mintime'         => $mintime,
            'maxtime'         => $maxtime,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/audit:view', $context);

        // Resolver usuario.
        $user = $DB->get_record('user', ['username' => $params['username'], 'deleted' => 0]);
        if (!$user) {
            throw new \moodle_exception('invaliduser', 'local_audit');
        }

        // Resolver curso (opcional).
        $courseid = 0;
        if (!empty($params['courseshortname'])) {
            $course = $DB->get_record('course', ['shortname' => $params['courseshortname']]);
            if (!$course) {
                throw new \moodle_exception('invalidcourse', 'local_audit');
            }
            $courseid = (int)$course->id;
        }

        // Comprobar que block_dedication está disponible.
        if (!local_audit_dedication_available()) {
            throw new \moodle_exception('dedicationnotavailable', 'local_audit');
        }

        $dedication = local_audit_get_dedication(
            (int)$user->id,
            $courseid,
            (int)$params['mintime'],
            (int)$params['maxtime']
        );

        $result = [];
        foreach ($dedication as $d) {
            $sessions = [];
            foreach ($d->sessions as $s) {
                $sessions[] = [
                    'start'    => (int)$s->start_date,
                    'duration' => (int)$s->dedicationtime,
                ];
            }
            $result[] = [
                'courseid'      => (int)$d->courseid,
                'coursename'    => $d->coursename,
                'shortname'     => $d->shortname,
                'timesecs'      => (int)$d->timesecs,
                'timeformatted' => $d->timeformatted,
                'sessioncount'  => count($sessions),
                'sessions'      => $sessions,
            ];
        }
        return $result;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'courseid'      => new external_value(PARAM_INT,  'ID del curso.'),
                'coursename'    => new external_value(PARAM_TEXT, 'Nombre completo del curso.'),
                'shortname'     => new external_value(PARAM_TEXT, 'Shortname del curso.'),
                'timesecs'      => new external_value(PARAM_INT,  'Tiempo total en segundos.'),
                'timeformatted' => new external_value(PARAM_TEXT, 'Tiempo total formateado.'),
                'sessioncount'  => new external_value(PARAM_INT,  'Número de sesiones.'),
                'sessions'      => new external_multiple_structure(
                    new external_single_structure([
                        'start'    => new external_value(PARAM_INT, 'Timestamp de inicio de sesión.'),
                        'duration' => new external_value(PARAM_INT, 'Duración de la sesión en segundos.'),
                    ]),
                    'Detalle de sesiones.'
                ),
            ])
        );
    }
}
