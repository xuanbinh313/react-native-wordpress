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

class LD_SR_Anki_Importer
{

    private $upload_dir;
    private $plugin_dir;
    private $errors = array();

    public function __construct()
    {
        $this->plugin_dir = plugin_dir_path(dirname(__FILE__));

        // Create uploads directory structure
        $wp_upload_dir = wp_upload_dir();
        $this->upload_dir = $wp_upload_dir['basedir'] . '/ld-anki-imports';

        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
    }

    /**
     * Preview Anki deck - extract and return deck structure without creating questions
     * 
     * @param string $file_path Path to uploaded .apkg or .zip file
     * @param int $user_id WordPress user ID
     * @return array Result with decks and notes grouped by model ID
     */
    public function preview_deck($file_path, $user_id)
    {
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

        // Get deck structure with notes grouped by model
        $deck_structure = $this->get_deck_structure($db_path, $user_id);

        if (!$deck_structure['success']) {
            return $deck_structure;
        }

        return array(
            'success' => true,
            'user_id' => $user_id,
            'extract_path' => $user_import_dir,
            'decks' => $deck_structure['decks'],
            'total_notes' => $deck_structure['total_notes'],
            'total_decks' => count($deck_structure['decks'])
        );
    }

    /**
     * Import Anki deck from uploaded file
     * 
     * @param string $file_path Path to uploaded .apkg or .zip file
     * @param int $user_id WordPress user ID
     * @param string $quiz_title Title for the new quiz
     * @param array $deck_configs Deck configurations with field mappings and question types
     * @return array Result with success status, quiz_id, and statistics
     */
    public function import_deck($file_path, $user_id, $quiz_title = '', $deck_configs = array())
    {
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

        // Group notes by deck_id for creating separate quizzes
        $notes_by_deck = array();
        foreach ($deck_data['notes'] as $note) {
            $deck_id = $note['deck_id'];
            $deck_name = $note['deck_name'];
            
            if (!isset($notes_by_deck[$deck_id])) {
                $notes_by_deck[$deck_id] = array(
                    'deck_name' => $deck_name,
                    'deck_id' => $deck_id,
                    'notes' => array()
                );
            }
            
            $notes_by_deck[$deck_id]['notes'][] = $note;
        }

        // Create or update quizzes for each deck
        $results = array();
        $total_created = 0;
        $total_skipped = 0;

        foreach ($notes_by_deck as $deck_id => $deck_info) {
            $deck_title = !empty($quiz_title) ? $quiz_title : $deck_info['deck_name'];
            
            $import_result = $this->create_or_update_learndash_quiz(
                $deck_info['notes'],
                $user_id,
                $deck_title,
                $deck_id,
                $wp_media_dir,
                $deck_configs
            );
            
            if ($import_result['success']) {
                $results[] = $import_result;
                $total_created += $import_result['created_questions'];
                $total_skipped += $import_result['skipped_questions'];
            }
        }

        // Return summary of all imports
        return array(
            'success' => true,
            'quizzes' => $results,
            'total_quizzes' => count($results),
            'total_created_questions' => $total_created,
            'total_skipped_questions' => $total_skipped,
            'media_url' => $wp_media_dir ? $wp_media_dir['url'] : null
        );
    }

    /**
     * Extract zip file
     */
    private function extract_zip($zip_path, $extract_to)
    {
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
    private function read_anki_database($db_path)
    {
        try {
            $db = new PDO("sqlite:$db_path");
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Get all notes
            $notes_query = $db->query("SELECT n.id, n.mid, n.flds, n.sfld, n.tags, d.name AS deck_name, c.did FROM notes AS n
            INNER JOIN cards AS c ON n.id = c.nid
            INNER JOIN decks AS d ON c.did = d.id
            ORDER BY n.id");
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
                    'mid' => $note['mid'],
                    'deck_name' => $note['deck_name'],
                    'deck_id' => $note['did'],
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
     * Get deck structure from Anki database
     * Groups notes by model ID (mid) and includes deck names
     */
    private function get_deck_structure($db_path, $user_id)
    {
        try {
            $db = new PDO("sqlite:$db_path");
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Get collection metadata (contains models and decks info)
            $notetypes = $db->query("SELECT id,name FROM notetypes");
            $notetypes_data = $notetypes->fetchAll(PDO::FETCH_ASSOC);
            $col_query = $db->query("SELECT models, decks FROM col LIMIT 1");
            $col_data = $col_query->fetch(PDO::FETCH_ASSOC);

            if ($col_data && !empty($col_data['models'])) {
                $models_json = json_decode($col_data['models'], true);
                if ($models_json) {
                    foreach ($models_json as $model_id => $model_data) {
                        $models[$model_id] = isset($model_data['name']) ? $model_data['name'] : 'Unknown Model';
                    }
                }
            }

            if ($col_data && !empty($col_data['decks'])) {
                $decks_json = json_decode($col_data['decks'], true);
                if ($decks_json) {
                    foreach ($decks_json as $deck_id => $deck_data) {
                        $decks[$deck_id] = isset($deck_data['name']) ? $deck_data['name'] : 'Unknown Deck';
                    }
                }
            }

            // Get all notes grouped by mid
            $notes_query = $db->query("SELECT id, mid, flds, sfld, tags FROM notes ORDER BY mid, id");
            $notes = $notes_query->fetchAll(PDO::FETCH_ASSOC);

            if (empty($notes)) {
                return array(
                    'success' => false,
                    'message' => 'No notes found in Anki database'
                );
            }

            // Group notes by model ID
            $grouped_notes = array();
            foreach ($notes as $note) {
                $mid = $note['mid'];
                $notetypes_filtered = array_filter($notetypes_data, fn($deck) => $deck['id'] == $mid);
                $found_notetype = reset($notetypes_filtered);

                $model_name = is_array($found_notetype)
                    ? ($found_notetype['name'] ?? 'Unknown Model')
                    : 'Unknown Model';
                $fields = explode("\x1f", $note['flds']);

                if (!isset($grouped_notes[$mid])) {
                    $grouped_notes[$mid] = array(
                        'mid' => $mid,
                        'model_name' => $model_name,
                        'notes' => array(),
                        'total_fields' => count($fields)
                    );
                }

                // Parse fields

                $grouped_notes[$mid]['notes'][] = array(
                    'id' => $note['id'],
                    'fields' => $fields,
                    'question' => $note['sfld'],
                );
            }

            // Convert to indexed array
            $deck_list = array_values($grouped_notes);

            return array(
                'success' => true,
                'decks' => $deck_list,
                'total_decks' => count($deck_list),
                'total_notes' => count($notes),
                'user_id' => $user_id
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            );
        }
    }

    /**
     * Create a preview string for a note
     */
    private function create_note_preview($fields)
    {
        $question = isset($fields[0]) ? strip_tags($fields[0]) : '';
        $answer = isset($fields[1]) ? strip_tags($fields[1]) : '';

        // Truncate if too long
        if (strlen($question) > 100) {
            $question = substr($question, 0, 97) . '...';
        }
        if (strlen($answer) > 100) {
            $answer = substr($answer, 0, 97) . '...';
        }

        return array(
            'question' => $question,
            'answer' => $answer
        );
    }

    /**
     * Copy media files to WordPress uploads directory
     */
    private function copy_media_files($source_media_dir, $user_id)
    {
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
     * Create or update LearnDash quiz and questions from Anki notes
     * If a quiz with the same deck_name exists, it will be reused
     */
    private function create_or_update_learndash_quiz($notes, $user_id, $quiz_title, $deck_id, $media_info, $deck_configs = array())
    {
        global $wpdb;

        if (empty($quiz_title)) {
            $quiz_title = 'Imported Anki Deck - ' . date('Y-m-d H:i:s');
        }

        // Check if quiz with this title already exists
        $existing_quiz = $wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_title = %s 
            AND post_type = 'sfwd-quiz' 
            AND post_status = 'publish'
            LIMIT 1",
            $quiz_title
        ));

        if ($existing_quiz) {
            // Use existing quiz
            $quiz_id = $existing_quiz->ID;
            $quiz_pro_id = get_post_meta($quiz_id, 'quiz_pro_id', true);
            
            if (!$quiz_pro_id) {
                // If quiz exists but has no pro_id, create one
                $quiz_pro_id = $this->create_quiz_pro($quiz_title);
                if (!$quiz_pro_id) {
                    return $this->error_response('Failed to create quiz pro entry for existing quiz');
                }
                update_post_meta($quiz_id, 'quiz_pro_id', $quiz_pro_id);
                update_post_meta($quiz_id, 'quiz_pro_id_' . $quiz_pro_id, $quiz_pro_id);
            }
            
            error_log("Anki Import: Reusing existing quiz '{$quiz_title}' (ID: {$quiz_id}, Pro ID: {$quiz_pro_id})");
        } else {
            // Create new LearnDash Quiz post
            $quiz_id = wp_insert_post(array(
                'post_title' => $quiz_title,
                'post_content' => 'Imported from Anki deck (Deck ID: ' . $deck_id . ')',
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

            // Add required LearnDash quiz settings
            $quiz_settings = array(
                'sfwd-quiz_quiz_pro' => $quiz_pro_id,
                'sfwd-quiz_lesson' => 0,
                'sfwd-quiz_certificate' => 0,
                'sfwd-quiz_threshold' => 80,
                'sfwd-quiz_passingpercentage' => 80,
                'sfwd-quiz_quiz_pro_id' => $quiz_pro_id,
                'sfwd-quiz_quiz_pro_id_' . $quiz_pro_id => $quiz_pro_id,
            );
            update_post_meta($quiz_id, '_sfwd-quiz', $quiz_settings);
            
            error_log("Anki Import: Created new quiz '{$quiz_title}' (ID: {$quiz_id}, Pro ID: {$quiz_pro_id})");
        }

        // Store deck_id reference for future imports
        update_post_meta($quiz_id, 'anki_deck_id', $deck_id);

        // Create questions
        $created_questions = 0;
        $skipped_questions = 0;

        foreach ($notes as $index => $note) {
            // Get deck configuration for this note's model ID
            $mid = $note['mid'];
            $config = isset($deck_configs[$mid]) ? $deck_configs[$mid] : null;

            // Build question and answer text based on field mappings
            if ($config) {
                $question_text = $this->build_text_from_mapping($note['raw_fields'], $config['questionMapping'], $media_info);
                $answer_text = $this->build_text_from_mapping($note['raw_fields'], $config['answerMapping'], $media_info);
                $question_type = $config['questionType'];
            } else {
                // Fallback to default behavior
                $question_text = $this->process_media_references($note['question'], $media_info);
                $answer_text = $this->process_media_references($note['answer'], $media_info);
                $question_type = 'cloze_answer';
            }

            // Skip if question is empty
            if (empty(trim(strip_tags($question_text)))) {
                $skipped_questions++;
                continue;
            }

            // Create LearnDash question
            $question_result = $this->create_learndash_question(
                $quiz_id,
                $quiz_pro_id,
                $question_text,
                $answer_text,
                $index,
                $note['tags'],
                $question_type
            );

            if ($question_result) {
                $created_questions++;
            } else {
                $skipped_questions++;
            }
        }

        // Clear any caches
        wp_cache_delete($quiz_id, 'post_meta');
        clean_post_cache($quiz_id);

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
    private function create_quiz_pro($title)
    {
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
    private function create_learndash_question($quiz_id, $quiz_pro_id, $question_text, $answer_text, $sort_order, $tags, $question_type)
    {
        global $wpdb;

        $title = wp_strip_all_tags(substr($question_text, 0, length: 100));
        $content = $question_text;
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
                'answer_type' => isset($question_type) ? $question_type : 'cloze_answer',
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
    private function process_media_references($text, $media_info)
    {
        if (!$media_info || empty($text)) {
            return $text;
        }

        $media_url = $media_info['url'];

        // Convert [sound:filename.mp3] to audio shortcode
        $text = preg_replace_callback(
            '/\[sound:([^\]]+)\]/',
            function ($matches) use ($media_url) {
                $filename = $matches[1];
                $file_url = $media_url . '/' . $filename;
                return '[audio src="' . esc_url($file_url) . '"]';
            },
            $text
        );

        // Update relative image paths to full URLs
        $text = preg_replace_callback(
            '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i',
            function ($matches) use ($media_url) {
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
     * Build text from field mapping string
     * Mapping format: "0|1|2" joins fields 0, 1, and 2 together
     * 
     * @param array $fields Array of field values
     * @param string $mapping Field indices separated by pipes (e.g., "0|1")
     * @param array $media_info Media information for processing references
     * @return string Combined and processed text
     */
    private function build_text_from_mapping($fields, $mapping, $media_info)
    {
        if (empty($mapping)) {
            return '';
        }

        // Parse field indices from mapping string
        $field_indices = array_map('trim', explode('|', $mapping));

        // Collect field values
        $parts = array();
        foreach ($field_indices as $index) {
            if (is_numeric($index) && isset($fields[$index])) {
                $parts[] = $fields[$index];
            }
        }

        // Join parts with line breaks
        $text = implode("\n\n", array_filter($parts));

        // Process media references
        return $this->process_media_references($text, $media_info);
    }

    /**
     * Error response helper
     */
    private function error_response($message)
    {
        return array(
            'success' => false,
            'error' => $message
        );
    }

    /**
     * Clean up temporary directory
     */
    private function cleanup_directory($dir)
    {
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
