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

class LearnDash_Spaced_Repetition {
    
    private $db;
    private $api;
    
    public function __construct() {
        // Initialize database handler
        $this->db = new LD_SR_Database();
        
        // Initialize REST API handler
        $this->api = new LD_SR_REST_API($this->db);
        
        // Plugin hooks
        register_activation_hook(__FILE__, array($this->db, 'create_table'));
        add_shortcode('ld_spaced_repetition', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Register REST API routes
        add_action('rest_api_init', array($this->api, 'register_routes'));
    }
    
    
    /**
     * Enqueue React app scripts and styles
     */
    public function enqueue_scripts() {
        // Read manifest.json to get the correct build file names
        $manifest_path = plugin_dir_path(__FILE__) . 'build/manifest.json';
        
        if (!file_exists($manifest_path)) {
            error_log('LearnDash SR: manifest.json not found. Please run "npm run build" in client-app folder.');
            return;
        }
        
        $manifest = json_decode(file_get_contents($manifest_path), true);
        
        if (!isset($manifest['index.html'])) {
            error_log('LearnDash SR: Invalid manifest.json format.');
            return;
        }
        
        $index_data = $manifest['index.html'];
        
        // Enqueue CSS files
        if (isset($index_data['css']) && is_array($index_data['css'])) {
            foreach ($index_data['css'] as $css_file) {
                wp_enqueue_style(
                    'ld-sr-react-style',
                    plugin_dir_url(__FILE__) . 'build/' . $css_file,
                    array(),
                    '2.0'
                );
            }
        }
        
        // Enqueue JS file
        if (isset($index_data['file'])) {
            wp_enqueue_script(
                'ld-sr-react-script',
                plugin_dir_url(__FILE__) . 'build/' . $index_data['file'],
                array(),
                '2.0',
                true
            );
            
            // Pass WordPress data to React app
            wp_localize_script('ld-sr-react-script', 'ldSR', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => rest_url('ld-sr/v1'),
                'nonce' => wp_create_nonce('ld_sr_nonce'),
                'rest_nonce' => wp_create_nonce('wp_rest')
            ));
        }
    }
    
    
    /**
     * Render shortcode
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'quiz_id' => 0
        ), $atts);
        
        // Return container div - React will handle content
        // If quiz_id is 0, show quiz selection screen
        $quiz_id_attr = $atts['quiz_id'] ? 'data-quiz-id="' . esc_attr($atts['quiz_id']) . '"' : '';
        return '<div id="ld-sr-container" ' . $quiz_id_attr . '></div>';
    }
}

new LearnDash_Spaced_Repetition();
?>