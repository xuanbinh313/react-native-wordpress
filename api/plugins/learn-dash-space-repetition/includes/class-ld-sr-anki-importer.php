<?php
/**
 * Anki Deck Importer for LearnDash
 * 
 * Imports Anki .apkg or .zip files into LearnDash questions
 * - Extracts zip file
 * - Reads collection.anki2 SQLite database
 * - Copies media files to user-specific directory
 * - Creates LearnDash quiz and questions
 * 
 * Anki Database Structure:
 * - notes table: Contains the actual flashcard content (fields separated by \x1f)
 * - cards table: Links to notes and contains scheduling info
 * - notetypes table: Defines the card model/template
 * - collection.media/: Contains audio/image files referenced in notes
 */

if (!defined('ABSPATH')) exit;

class LD_SR_Anki_Importer {
    
    private $upload_dir;
    private $plugin_dir;
    private $errors = array();
    
    public function __construct() {
        $this->plugin_dir = plugin_dir_path(dirname(__FILE__));
        
        // Create uploads directory structure
        $wp_upload_dir = wp_upload_dir();
        $this->upload_dir = $wp_upload_dir['basedir'] . '/ld-anki-imports';
        
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
    }
    
    /**
     * Import Anki deck from uploaded file
     * 
     * @param string $file_path Path to uploaded .apkg or .zip file
     * @param int $user_id WordPress user ID
     * @param string $quiz_title Title for the new quiz
     * @return array Result with success status, quiz_id, and statistics
     */
    public function import_deck($file_path, $user_id, $quiz_title = '') {
        $this->errors = array();
        
        // Validate file
        if (!file_exists($file_path)) {
            return $this->error_response('File not found');
        }
        
        // Create user-specific directory
        $user_import_dir = $this->upload_dir . '/user_' . $user_id;
        if (!wp_mkdir_p($user_import_dir)) {
            return $this->error_response('Failed to create import directory');
        }
        
        // Clean up old files in user directory before extracting new ones
        if (is_dir($user_import_dir)) {
            $files = glob($user_import_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                } elseif (is_dir($file)) {
                    $this->cleanup_directory($file);
                }
            }
        }
        
        // Extract zip file
        $extract_result = $this->extract_zip($file_path, $user_import_dir);
        if (!$extract_result['success']) {
            return $extract_result;
        }
        
        // Read Anki database
        $db_path = $user_import_dir . '/collection.anki2';
        error_log('Anki Import: Reading database from ' . $db_path);
        if (!file_exists($db_path)) {
            return $this->error_response('collection.anki2 not found in zip file');
        }
        
        $deck_data = $this->read_anki_database($db_path);
        if (!$deck_data['success']) {
            return $deck_data;
        }
        
        // Copy media files to WordPress uploads
        $media_dir = $user_import_dir . '/collection.media';
        $wp_media_dir = $this->copy_media_files($media_dir, $user_id);
        
        // Create LearnDash quiz and questions
        $import_result = $this->create_learndash_quiz(
            $deck_data['notes'],
            $user_id,
            $quiz_title,
            $wp_media_dir
        );
        
        // Clean up temporary directory (optional - keep for debugging)
        // $this->cleanup_directory($user_import_dir);
        
        return $import_result;
    }
    
    /**
     * Extract zip file
     */
    private function extract_zip($zip_path, $extract_to) {
        if (!class_exists('ZipArchive')) {
            return $this->error_response('ZipArchive class not available');
        }
        
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return $this->error_response('Failed to open zip file');
        }
        
        if (!$zip->extractTo($extract_to)) {
            $zip->close();
            return $this->error_response('Failed to extract zip file');
        }
        
        $zip->close();
        
        return array('success' => true);
    }
    
    /**
     * Read Anki SQLite database
     */
    private function read_anki_database($db_path) {
        try {
            $db = new PDO("sqlite:$db_path");
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get all notes
            $notes_query = $db->query("SELECT id, mid, flds, tags FROM notes ORDER BY id");
            $notes = $notes_query->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($notes)) {
                return $this->error_response('No notes found in Anki database');
            }
            
            // Parse notes fields
            $parsed_notes = array();
            foreach ($notes as $note) {
                // Fields are separated by \x1f character
                $fields = explode("\x1f", $note['flds']);
                
                // Typically: Field 0 = Question, Field 1 = Answer, Field 2 = Audio/Extra
                $parsed_notes[] = array(
                    'id' => $note['id'],
                    'question' => isset($fields[0]) ? $fields[0] : '',
                    'answer' => isset($fields[1]) ? $fields[1] : '',
                    'extra' => isset($fields[2]) ? $fields[2] : '',
                    'tags' => trim($note['tags']),
                    'raw_fields' => $fields
                );
            }
            
            return array(
                'success' => true,
                'notes' => $parsed_notes,
                'count' => count($parsed_notes)
            );
            
        } catch (Exception $e) {
            return $this->error_response('Database error: ' . $e->getMessage());
        }
    }
    
    /**
     * Copy media files to WordPress uploads directory
     */
    private function copy_media_files($source_media_dir, $user_id) {
        if (!is_dir($source_media_dir)) {
            return false;
        }
        
        $wp_upload_dir = wp_upload_dir();
        $target_media_dir = $wp_upload_dir['basedir'] . '/ld-anki-media/user_' . $user_id;
        
        if (!file_exists($target_media_dir)) {
            wp_mkdir_p($target_media_dir);
        }
        
        // Copy all media files
        $files = glob($source_media_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $filename = basename($file);
                copy($file, $target_media_dir . '/' . $filename);
            }
        }
        
        return array(
            'path' => $target_media_dir,
            'url' => $wp_upload_dir['baseurl'] . '/ld-anki-media/user_' . $user_id
        );
    }
    
    /**
     * Create LearnDash quiz and questions from Anki notes
     */
    private function create_learndash_quiz($notes, $user_id, $quiz_title, $media_info) {
        global $wpdb;
        
        if (empty($quiz_title)) {
            $quiz_title = 'Imported Anki Deck - ' . date('Y-m-d H:i:s');
        }
        
        // Create LearnDash Quiz post
        $quiz_id = wp_insert_post(array(
            'post_title' => $quiz_title,
            'post_content' => 'Imported from Anki deck',
            'post_status' => 'publish',
            'post_type' => 'sfwd-quiz',
            'post_author' => $user_id
        ));
        
        if (is_wp_error($quiz_id)) {
            return $this->error_response('Failed to create quiz: ' . $quiz_id->get_error_message());
        }
        
        // Create quiz in pro_quiz table
        $quiz_pro_id = $this->create_quiz_pro($quiz_title);
        
        if (!$quiz_pro_id) {
            wp_delete_post($quiz_id, true);
            return $this->error_response('Failed to create quiz pro entry');
        }
        
        // Link quiz post to pro quiz
        update_post_meta($quiz_id, 'quiz_pro_id', $quiz_pro_id);
        update_post_meta($quiz_id, 'quiz_pro_id_' . $quiz_pro_id, $quiz_pro_id);
        
        // Create questions
        $created_questions = 0;
        $skipped_questions = 0;
        
        foreach ($notes as $index => $note) {
            // Skip if question is empty
            if (empty(trim(strip_tags($note['question'])))) {
                $skipped_questions++;
                continue;
            }
            
            // Process media references in question and answer
            $question_text = $this->process_media_references($note['question'], $media_info);
            $answer_text = $this->process_media_references($note['answer'], $media_info);
            
            // Create LearnDash question
            $question_result = $this->create_learndash_question(
                $quiz_id,
                $quiz_pro_id,
                $question_text,
                $answer_text,
                $index,
                $note['tags']
            );
            
            if ($question_result) {
                $created_questions++;
            } else {
                $skipped_questions++;
            }
        }
        
        return array(
            'success' => true,
            'quiz_id' => $quiz_id,
            'quiz_pro_id' => $quiz_pro_id,
            'quiz_title' => $quiz_title,
            'total_notes' => count($notes),
            'created_questions' => $created_questions,
            'skipped_questions' => $skipped_questions,
            'media_url' => $media_info ? $media_info['url'] : null
        );
    }
    
    /**
     * Create quiz pro entry
     */
    private function create_quiz_pro($title) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'learndash_pro_quiz_master';
        
        // Get table columns to check what's available in this LearnDash version
        $columns = $wpdb->get_col("DESCRIBE {$table}");
        
        // Base fields that should exist in all versions
        $quiz_data = array(
            'name' => $title,
            'text' => 'Imported from Anki',
        );
        
        // Optional fields - only add if column exists
        $optional_fields = array(
            'title_hidden' => 0,
            'btn_restart_quiz_hidden' => 0,
            'btn_view_question_hidden' => 0,
            'question_random' => 0,
            'sort_categories' => 0,
            'time_limit' => 0,
            'statistics_on' => 1,
            'statistics_ip_lock' => 0,
            'show_points' => 1,
            'quiz_run_once' => 0,
            'quiz_run_once_type' => 0,
            'quiz_run_once_cookie' => 0,
            'numbered_answer' => 0,
            'hide_answer_message_box' => 0,
            'disabled_answer_mark' => 0,
            'show_review_question' => 0,
            'quiz_summary_hide' => 0,
            'skip_question_disabled' => 0,
            'email_notification' => 0,
            'user_email_notification' => 0,
            'show_category_score' => 0,
            'hide_result_correct_question' => 0,
            'hide_result_quiz_time' => 0,
            'hide_result_points' => 0,
            'autostart' => 0,
            'forcing_question_solve' => 0,
            'hide_question_position_overview' => 0,
            'hide_question_numbering' => 0,
            'form_activated' => 0,
            'form_show_position' => 0,
            'start_only_registered_user' => 0,
            'questions_per_page' => 1,
            'show_average_result' => 0,
            'prerequisite' => 0,
            'toplist_activated' => 0,
            'toplist_data_addPermissions' => 1,
            'toplist_data_sort' => 0,
            'toplist_data_addMultiple' => 0,
            'toplist_data_addBlock' => 0,
            'toplist_data_showLimit' => 0,
            'toplist_data_showIn' => 0,
            'toplist_data_captcha' => 0,
            'show_result_after_answer' => 0,
            'question_answer_type' => 0
        );
        
        // Only add fields that exist in the table
        foreach ($optional_fields as $field => $value) {
            if (in_array($field, $columns)) {
                $quiz_data[$field] = $value;
            }
        }
        
        $result = $wpdb->insert($table, $quiz_data);
        
        if ($result === false) {
            error_log('Anki Import: Failed to create quiz pro entry. Error: ' . $wpdb->last_error);
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Create LearnDash question using the correct ld_create_single_question function
     */
    private function create_learndash_question($quiz_id, $quiz_pro_id, $question_text, $answer_text, $sort_order, $tags) {
        // Check if the function exists
        if (!function_exists('ld_create_single_question')) {
            error_log('LD Anki Import: ld_create_single_question function not found. Make sure learndash-api-custom plugin is active.');
            // Fallback to direct insertion if function doesn't exist
            return $this->create_learndash_question_fallback($quiz_id, $quiz_pro_id, $question_text, $answer_text, $sort_order, $tags);
        }
        
        // Get question title (strip HTML and limit length)
        $title = wp_strip_all_tags($question_text);
        if (strlen($title) > 100) {
            $title = substr($title, 0, 100) . '...';
        }
        
        // Prepare question data in the format expected by ld_create_single_question
        $question_data = array(
            'title' => $title,
            'content' => $question_text . "\n\n<hr>\n\n<strong>Answer:</strong>\n" . $answer_text,
            'answers' => array(), // Empty for essay type
            'points' => 1,
            'type' => 'essay', // Essay type allows HTML content
            'media' => '' // No media reference needed, already embedded in content
        );
        
        // Call the correct function
        $result = ld_create_single_question($quiz_id, $quiz_pro_id, $question_data, '', array());
        
        if (is_wp_error($result)) {
            error_log('LD Anki Import: Failed to create question - ' . $result->get_error_message());
            return false;
        }
        
        // Add tags if provided
        if (!empty($tags) && isset($result['question_id'])) {
            wp_set_post_terms($result['question_id'], explode(' ', $tags), 'post_tag');
        }
        
        return isset($result['pro_id']) ? $result['pro_id'] : false;
    }
    
    /**
     * Fallback method for creating questions if ld_create_single_question is not available
     */
    private function create_learndash_question_fallback($quiz_id, $quiz_pro_id, $question_text, $answer_text, $sort_order, $tags) {
        global $wpdb;
        
        $title = wp_strip_all_tags(substr($question_text, 0, 100));
        $content = $question_text . "\n\n<hr>\n\n<strong>Answer:</strong>\n" . $answer_text;
        $current_user_id = get_current_user_id() ?: 1;
        
        // Get next sort order
        $max_sort = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sort) FROM {$wpdb->prefix}learndash_pro_quiz_question WHERE quiz_id = %d",
            $quiz_pro_id
        ));
        $sort_order = intval($max_sort) + 1;
        
        // Serialize answers for essay type
        $answer_data = array(
            (object) array(
                '_mapper' => null,
                '_answer' => $answer_text,
                '_html' => true,
                '_points' => 0.0,
                '_correct' => false,
                '_sortString' => '',
                '_sortStringHtml' => false,
                '_graded' => false,
                '_gradingProgression' => 'not-graded-none',
                '_gradedType' => null,
                '__PHP_Incomplete_Class_Name' => 'WpProQuiz_Model_AnswerTypes',
            )
        );
        
        // Insert into wp_learndash_pro_quiz_question
        $result = $wpdb->insert(
            $wpdb->prefix . 'learndash_pro_quiz_question',
            array(
                'quiz_id' => $quiz_pro_id,
                'online' => 1,
                'sort' => $sort_order,
                'title' => $title,
                'points' => 1,
                'question' => $content,
                'correct_msg' => '',
                'incorrect_msg' => '',
                'correct_same_text' => 0,
                'tip_enabled' => 0,
                'tip_msg' => '',
                'answer_type' => 'cloze_answer',
                'show_points_in_box' => 0,
                'answer_points_activated' => 0,
                'answer_data' => serialize($answer_data),
                'category_id' => 0,
                'answer_points_diff_modus_activated' => 0,
                'disable_correct' => 0,
                'matrix_sort_answer_criteria_width' => 20,
            ),
            array('%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%d')
        );
        
        if (!$result) {
            return false;
        }
        
        $pro_id = $wpdb->insert_id;
        
        // Create WordPress post
        $question_id = wp_insert_post(array(
            'post_title' => $title,
            'post_content' => $content,
            'post_type' => 'sfwd-question',
            'post_status' => 'publish',
            'post_author' => $current_user_id,
        ));
        
        if (is_wp_error($question_id)) {
            $wpdb->delete($wpdb->prefix . 'learndash_pro_quiz_question', array('id' => $pro_id), array('%d'));
            return false;
        }
        
        // Update post meta
        update_post_meta($question_id, '_edit_last', $current_user_id);
        update_post_meta($question_id, 'quiz_id', $quiz_id);
        update_post_meta($question_id, '_sfwd-question', array(
            'sfwd-question_quiz' => strval($quiz_id),
        ));
        update_post_meta($question_id, 'question_pro_id', $pro_id);
        update_post_meta($question_id, 'question_points', 1);
        update_post_meta($question_id, 'question_type', 'essay');
        update_post_meta($question_id, 'question_pro_category', '0');
        update_post_meta($question_id, '_edit_lock', time() . ':' . $current_user_id);
        
        // Update quiz metadata
        $quiz_questions = get_post_meta($quiz_id, 'ld_quiz_questions', true);
        if (!is_array($quiz_questions)) {
            $quiz_questions = array();
        }
        $quiz_questions[$question_id] = $pro_id;
        update_post_meta($quiz_id, 'ld_quiz_questions', $quiz_questions);
        
        // Mark quiz dirty and delete dirty flag
        update_post_meta($quiz_id, 'ld_quiz_questions_dirty', $quiz_id);
        
        $meta_id = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->prefix}postmeta WHERE meta_key = 'ld_quiz_questions_dirty' AND post_id = %d",
            $quiz_id
        ));
        if ($meta_id) {
            $wpdb->delete($wpdb->prefix . 'postmeta', array('meta_id' => $meta_id), array('%d'));
        }
        
        // Add tags if provided
        if (!empty($tags)) {
            wp_set_post_terms($question_id, explode(' ', $tags), 'post_tag');
        }
        
        // Clear caches
        wp_cache_delete($quiz_id, 'post_meta');
        wp_cache_delete($question_id, 'post_meta');
        clean_post_cache($quiz_id);
        clean_post_cache($question_id);
        
        return $pro_id;
    }
    
    /**
     * Process media references in text
     * Convert [sound:filename.mp3] to WordPress audio shortcode
     * Convert <img src="filename.jpg"> to full WordPress URL
     */
    private function process_media_references($text, $media_info) {
        if (!$media_info || empty($text)) {
            return $text;
        }
        
        $media_url = $media_info['url'];
        
        // Convert [sound:filename.mp3] to audio shortcode
        $text = preg_replace_callback(
            '/\[sound:([^\]]+)\]/',
            function($matches) use ($media_url) {
                $filename = $matches[1];
                $file_url = $media_url . '/' . $filename;
                return '[audio src="' . esc_url($file_url) . '"]';
            },
            $text
        );
        
        // Update relative image paths to full URLs
        $text = preg_replace_callback(
            '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i',
            function($matches) use ($media_url) {
                $full_match = $matches[0];
                $src = $matches[1];
                
                // If it's already a full URL, skip
                if (preg_match('/^https?:\/\//', $src)) {
                    return $full_match;
                }
                
                // Replace with full URL
                $new_src = $media_url . '/' . basename($src);
                return str_replace($src, $new_src, $full_match);
            },
            $text
        );
        
        return $text;
    }
    
    /**
     * Error response helper
     */
    private function error_response($message) {
        return array(
            'success' => false,
            'error' => $message
        );
    }
    
    /**
     * Clean up temporary directory
     */
    private function cleanup_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanup_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
