<?php
/**
 * Formulario de filtro de fechas para la pestaña Tiempo.
 */

namespace local_audit\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class datefilter_form extends \moodleform {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('date_selector', 'datefrom',
            get_string('datefrom', 'local_audit'), ['optional' => true]);

        $mform->addElement('date_selector', 'dateto',
            get_string('dateto', 'local_audit'), ['optional' => true]);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'searched');
        $mform->setType('searched', PARAM_INT);

        $this->add_action_buttons(false, get_string('filterdates', 'local_audit'));
    }
}
