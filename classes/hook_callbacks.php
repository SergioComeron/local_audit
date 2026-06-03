<?php
/**
 * Hook callbacks para local_audit.
 */
namespace local_audit;

class hook_callbacks {

    /**
     * Fuerza la inicialización del tema ANTES de que otros hooks (p.ej. block_ai_chat)
     * accedan a $PAGE->theme durante el footer, lo que podría provocar una
     * coding_exception si el tema no está inicializado cuando los bloques ya
     * han sido cargados por block_manager.
     */
    public static function init_theme_before_footer(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE;
        try {
            // Acceder a $PAGE->theme dispara initialise_theme_and_output() si aún
            // no se ha ejecutado. Si setup_blocks() lanza porque ya se cargaron los
            // bloques, el tema ya habrá sido asignado a $PAGE->_theme antes del
            // throw, así que los accesos posteriores devolverán el tema directamente.
            $PAGE->theme;
        } catch (\Throwable $e) {
            // Silencioso: el objetivo era inicializar $_theme, que ya quedó asignado.
        }
    }
}
