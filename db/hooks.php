<?php
/**
 * Hook callbacks para local_audit.
 *
 * Registra un listener de before_footer_html_generation con prioridad alta
 * para forzar la inicialización del tema ANTES de que block_ai_chat (u otros
 * plugins) llamen a is_block_present() durante el footer, lo que provocaría
 * una coding_exception si el tema aún no está inicializado y los bloques ya
 * han sido cargados.
 */
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => \core\hook\output\before_footer_html_generation::class,
        'callback' => [\local_audit\hook_callbacks::class, 'init_theme_before_footer'],
        'priority' => 9999,
    ],
];
