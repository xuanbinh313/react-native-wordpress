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
        add_shortcode('ld_anki_import', array($this, 'render_import_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ld_anki_upload', array($this, 'handle_anki_upload'));
        
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
    
    /**
     * Render Anki import shortcode
     * Usage: [ld_anki_import]
     */
    public function render_import_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to import Anki decks.</p>';
        }
        
        ob_start();
        ?>
        <div class="ld-anki-import-container">
            <h2>Import Anki Deck</h2>
            <p>Upload your Anki deck file (.apkg or .zip) to import questions into LearnDash.</p>
            
            <form id="ld-anki-upload-form" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="anki-file">Select Anki Deck File:</label>
                    <input type="file" id="anki-file" name="anki_file" accept=".apkg,.zip" required>
                </div>
                
                <div class="form-group">
                    <label for="quiz-title">Quiz Title (optional):</label>
                    <input type="text" id="quiz-title" name="quiz_title" placeholder="Leave blank for auto-generated title">
                </div>
                
                <button type="submit" class="button button-primary">Import Deck</button>
            </form>
            
            <div id="ld-anki-import-status" style="margin-top: 20px;"></div>
        </div>
        
        <style>
            .ld-anki-import-container {
                max-width: 600px;
                margin: 20px auto;
                padding: 20px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .form-group {
                margin-bottom: 15px;
            }
            .form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
            }
            .form-group input[type="file"],
            .form-group input[type="text"] {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            #ld-anki-import-status.success {
                padding: 15px;
                background: #d4edda;
                border: 1px solid #c3e6cb;
                border-radius: 4px;
                color: #155724;
            }
            #ld-anki-import-status.error {
                padding: 15px;
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                border-radius: 4px;
                color: #721c24;
            }
            #ld-anki-import-status.processing {
                padding: 15px;
                background: #d1ecf1;
                border: 1px solid #bee5eb;
                border-radius: 4px;
                color: #0c5460;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#ld-anki-upload-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData();
                var fileInput = document.getElementById('anki-file');
                var quizTitle = $('#quiz-title').val();
                
                if (fileInput.files.length === 0) {
                    alert('Please select a file');
                    return;
                }
                
                formData.append('action', 'ld_anki_upload');
                formData.append('anki_file', fileInput.files[0]);
                formData.append('quiz_title', quizTitle);
                formData.append('nonce', '<?php echo wp_create_nonce('ld_anki_import'); ?>');
                
                var statusDiv = $('#ld-anki-import-status');
                statusDiv.removeClass('success error').addClass('processing');
                statusDiv.html('<p>Uploading and processing... Please wait.</p>');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            statusDiv.removeClass('processing').addClass('success');
                            var html = '<h3>Import Successful!</h3>';
                            html += '<p><strong>Quiz Title:</strong> ' + response.data.quiz_title + '</p>';
                            html += '<p><strong>Quiz ID:</strong> ' + response.data.quiz_id + '</p>';
                            html += '<p><strong>Questions Created:</strong> ' + response.data.created_questions + '</p>';
                            html += '<p><strong>Questions Skipped:</strong> ' + response.data.skipped_questions + '</p>';
                            html += '<p><a href="/wp-admin/post.php?post=' + response.data.quiz_id + '&action=edit" class="button">Edit Quiz</a></p>';
                            statusDiv.html(html);
                            
                            // Reset form
                            $('#ld-anki-upload-form')[0].reset();
                        } else {
                            statusDiv.removeClass('processing').addClass('error');
                            statusDiv.html('<p><strong>Error:</strong> ' + response.data + '</p>');
                        }
                    },
                    error: function(xhr, status, error) {
                        statusDiv.removeClass('processing').addClass('error');
                        statusDiv.html('<p><strong>Upload Error:</strong> ' + error + '</p>');
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Handle Anki file upload via AJAX
     */
    public function handle_anki_upload() {
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
}

new LearnDash_Spaced_Repetition();
?>