<?php

/**
 * Plugin Name: LearnDash Spaced Repetition
 * Description: Adds Anki-style spaced repetition to LearnDash quizzes with database tracking
 * Version: 3.0
 * Author: Your Name
 * 
 * Architecture:
 * - Database operations: includes/class-ld-sr-database.php
 * - Algorithm logic: includes/class-ld-sr-algorithm.php
 * - REST API: includes/class-ld-sr-rest-api.php
 * 
 * Database Design:
 * - Each answer attempt is saved as a new row (history tracking)
 * - Tracks: is_correct (1/0), rating (again/hard/good/easy), answer_time_ms
 * - Next review time calculated using Anki's SM-2 algorithm
 * - Card states: new, learning, review, relearning
 * 
 * Card Selection Priority:
 * 1. Due review cards (sorted by due date, then wrong count)
 * 2. New cards (questions never answered)
 * 3. All done → Show completion message
 * 
 * Anki SM-2 Algorithm:
 * - Again (wrong): interval=0, card→learning/relearning, ease-=0.2
 * - Hard: interval=1d (new) or 1.2x (review), ease-=0.15
 * - Good: interval=1d (new) or ease*interval (review)
 * - Easy: interval=4d (new) or 1.3*ease*interval (review), ease+=0.15
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

// Load required classes
require_once plugin_dir_path(__FILE__) . 'includes/class-ld-sr-database.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ld-sr-algorithm.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ld-sr-rest-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ld-sr-anki-importer.php';

class LearnDash_Spaced_Repetition
{

    private $db;
    private $api;

    public function __construct()
    {
        // Initialize database handler
        $this->db = new LD_SR_Database();

        // Initialize REST API handler
        $this->api = new LD_SR_REST_API($this->db);

        // Plugin hooks
        register_activation_hook(__FILE__, array($this->db, 'create_table'));
        add_shortcode('ld_spaced_repetition', array($this, 'render_shortcode'));
        add_shortcode('ld_anki_import', array($this, 'render_import_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ld_anki_upload', array($this, 'handle_anki_upload'));
        add_action('wp_ajax_ld_anki_preview', array($this, 'handle_anki_preview'));

        // Register REST API routes
        add_action('rest_api_init', array($this->api, 'register_routes'));
    }


    /**
     * Enqueue React app scripts and styles
     */
    public function enqueue_scripts()
    {
        // Read manifest.json to get the correct build file names
        $manifest_path = plugin_dir_path(__FILE__) . 'build/manifest.json';

        if (!file_exists($manifest_path)) {
            error_log('LearnDash SR: manifest.json not found. Please run "npm run build" in client-app folder.');
            return;
        }

        $manifest = json_decode(file_get_contents($manifest_path), true);

        if (empty($manifest)) {
            error_log('LearnDash SR: Invalid manifest.json format.');
            return;
        }

        // Find all entry points (isEntry: true)
        $entry_points = array();
        foreach ($manifest as $key => $data) {
            if (isset($data['isEntry']) && $data['isEntry'] === true) {
                $entry_points[$key] = $data;
            }
        }

        if (empty($entry_points)) {
            error_log('LearnDash SR: No entry points found in manifest.json.');
            return;
        }

        // Enqueue CSS files from _App (shared styles)
        foreach ($manifest as $key => $data) {
            if (isset($data['css']) && is_array($data['css'])) {
                foreach ($data['css'] as $css_file) {
                    wp_enqueue_style(
                        'ld-sr-' . sanitize_title($key) . '-style',
                        plugin_dir_url(__FILE__) . 'build/' . $css_file,
                        array(),
                        '3.0'
                    );
                }
            }
        }

        // Enqueue entry point JS files
        foreach ($entry_points as $key => $entry_data) {
            if (isset($entry_data['file'])) {
                $handle = 'ld-sr-' . sanitize_title($entry_data['name']);

                wp_enqueue_script(
                    $handle,
                    plugin_dir_url(__FILE__) . 'build/' . $entry_data['file'],
                    array(),
                    '3.0',
                    true
                );

                // Add type="module" attribute for ES modules
                add_filter('script_loader_tag', function ($tag, $handle_filter, $src) use ($handle) {
                    if ($handle_filter === $handle) {
                        $tag = str_replace('<script ', '<script type="module" ', $tag);
                    }
                    return $tag;
                }, 10, 3);

                // Pass WordPress data to React app (only once for the first entry)
                static $localized = false;
                if (!$localized) {
                    wp_localize_script($handle, 'ldSR', array(
                        'ajax_url' => admin_url('admin-ajax.php'),
                        'rest_url' => rest_url('ld-sr/v1'),
                        'nonce' => wp_create_nonce('ld_sr_nonce'),
                        'rest_nonce' => wp_create_nonce('wp_rest'),
                        'anki_nonce' => wp_create_nonce('ld_anki_import')
                    ));
                    $localized = true;
                }
            }
        }
    }


    /**
     * Render shortcode
     */
    public function render_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'quiz_id' => 0
        ), $atts);

        // Return container div - React will handle content
        // If quiz_id is 0, show quiz selection screen
        $quiz_id_attr = $atts['quiz_id'] ? 'data-quiz-id="' . esc_attr($atts['quiz_id']) . '"' : '';
        return '<div id="ld-sr-container" ' . $quiz_id_attr . '></div>';
    }

    /**
     * Render Anki import shortcode
     * Usage: [ld_anki_import]
     */
    public function render_import_shortcode($atts)
    {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to import Anki decks.</p>';
        }

        ob_start();
?>
        <!-- React component will render here -->
        <div id="ld-anki-import-container"></div>
<?php
        return ob_get_clean();
    }

    /**
     * Handle Anki file upload via AJAX
     */
    public function handle_anki_upload()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ld_anki_import')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in');
            return;
        }

        // Check file upload
        if (!isset($_FILES['anki_file']) || $_FILES['anki_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('File upload failed');
            return;
        }

        $file = $_FILES['anki_file'];
        $quiz_title = isset($_POST['quiz_title']) ? sanitize_text_field($_POST['quiz_title']) : '';
        $user_id = get_current_user_id();

        // Validate file type
        $allowed_extensions = array('zip', 'apkg');
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_extension, $allowed_extensions)) {
            wp_send_json_error('Invalid file type. Only .zip and .apkg files are allowed');
            return;
        }

        // Move uploaded file to temporary location
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['basedir'] . '/ld-anki-temp-' . uniqid() . '.' . $file_extension;

        if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
            wp_send_json_error('Failed to save uploaded file');
            return;
        }

        // Import the deck
        $importer = new LD_SR_Anki_Importer();
        $result = $importer->import_deck($temp_file, $user_id, $quiz_title);

        // Clean up temp file
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }

        // Send response
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error']);
        }
    }

    /**
     * Handle Anki file preview via AJAX
     * Returns deck structure without creating questions
     */
    public function handle_anki_preview()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ld_anki_import')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in');
            return;
        }

        // Check file upload
        if (!isset($_FILES['anki_file']) || $_FILES['anki_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('File upload failed');
            return;
        }

        $file = $_FILES['anki_file'];
        $user_id = get_current_user_id();

        // Validate file type
        $allowed_extensions = array('zip', 'apkg');
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_extension, $allowed_extensions)) {
            wp_send_json_error('Invalid file type. Only .zip and .apkg files are allowed');
            return;
        }

        // Move uploaded file to temporary location
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['basedir'] . '/ld-anki-temp-' . uniqid() . '.' . $file_extension;

        if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
            wp_send_json_error('Failed to save uploaded file');
            return;
        }

        // Preview the deck structure
        $importer = new LD_SR_Anki_Importer();
        $result = $importer->preview_deck($temp_file, $user_id);

        // Clean up temp file
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }

        // Send response
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(isset($result['error']) ? $result['error'] : $result['message']);
        }
    }
}

new LearnDash_Spaced_Repetition();
?>