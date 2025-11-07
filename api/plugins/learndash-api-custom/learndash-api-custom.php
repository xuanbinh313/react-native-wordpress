<?php
/*
Plugin Name: LearnDash Question API
Description: Custom REST API endpoints to create LearnDash quiz questions from array or CSV.
Version: 2.0
Author: ChatGPT
*/

add_action('rest_api_init', function () {
    // Endpoint for creating multiple questions from array
    register_rest_route('ld/v1', '/questions/bulk', [
        'methods' => 'POST',
        'callback' => 'ld_create_questions_bulk',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
    ]);

    // Endpoint for creating questions from CSV file
    register_rest_route('ld/v1', '/questions/csv', [
        'methods' => 'POST',
        'callback' => 'ld_create_questions_csv',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
    ]);
});

// Add import button to question list page
add_action('admin_footer-edit.php', 'ld_add_import_button_to_questions_list');

/**
 * Add Import Button to LearnDash Questions List Page
 */
function ld_add_import_button_to_questions_list() {
    global $typenow;
    
    // Only show on sfwd-question post type list
    if ($typenow !== 'sfwd-question') {
        return;
    }
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Add import button next to "Add New" button
            $('.wrap .page-title-action').after(
                '<a href="#" class="page-title-action ld-import-questions-btn" style="margin-left: 5px;">Import Questions</a>'
            );
            
            // Handle button click (no logic yet, just for testing)
            $('.ld-import-questions-btn').on('click', function(e) {
                e.preventDefault();
                alert('Import Questions button clicked! Logic to be implemented.');
            });
        });
    </script>
    <?php
}

/**
 * Create multiple questions from array
 * Expected JSON format:
 * {
 *   "quiz_id": 123,
 *   "questions": [
 *     {
 *       "title": "Question 1",
 *       "content": "What is...",
 *       "answers": [{"answer": "A", "correct": true, "points": 1}, ...],
 *       "points": 1,
 *       "type": "single"
 *     },
 *     ...
 *   ]
 * }
 */
function ld_create_questions_bulk($request) {
    global $wpdb;
    $params = $request->get_json_params();
    
    $quiz_id = intval($params['quiz_id']);
    $questions = $params['questions'] ?? [];
    
    if (empty($questions) || !is_array($questions)) {
        return new WP_Error('invalid_data', 'Questions array is required', ['status' => 400]);
    }
    
    // Validate quiz exists
    $quiz_pro_id = get_post_meta($quiz_id, 'quiz_pro_id', true);
    if (empty($quiz_pro_id)) {
        return new WP_Error('invalid_quiz', 'Quiz does not have a valid quiz_pro_id', ['status' => 400]);
    }
    
    $results = [];
    $errors = [];
    
    foreach ($questions as $index => $question_data) {
        $result = ld_create_single_question($quiz_id, $quiz_pro_id, $question_data);
        
        if (is_wp_error($result)) {
            $errors[] = [
                'index' => $index,
                'error' => $result->get_error_message(),
            ];
        } else {
            $results[] = $result;
        }
    }
    
    return [
        'success' => true,
        'quiz_id' => $quiz_id,
        'created' => count($results),
        'failed' => count($errors),
        'results' => $results,
        'errors' => $errors,
    ];
}

/**
 * Create questions from CSV file
 * Expected CSV format:
 * title,content,type,points,answer1,correct1,points1,answer2,correct2,points2,...
 * 
 * File should be sent as multipart/form-data with:
 * - quiz_id: integer
 * - file: CSV file
 */
function ld_create_questions_csv($request) {
    global $wpdb;
    $files = $request->get_file_params();
    $params = $request->get_params();
    
    $quiz_id = intval($params['quiz_id']);
    
    if (empty($files['file'])) {
        return new WP_Error('no_file', 'CSV file is required', ['status' => 400]);
    }
    
    $file = $files['file'];
    $file_path = $file['tmp_name'];
    
    if (!file_exists($file_path)) {
        return new WP_Error('file_error', 'Cannot read uploaded file', ['status' => 400]);
    }
    
    // Validate quiz exists
    $quiz_pro_id = get_post_meta($quiz_id, 'quiz_pro_id', true);
    if (empty($quiz_pro_id)) {
        return new WP_Error('invalid_quiz', 'Quiz does not have a valid quiz_pro_id', ['status' => 400]);
    }
    
    // Parse CSV file
    $questions = [];
    $handle = fopen($file_path, 'r');
    
    if ($handle === false) {
        return new WP_Error('file_error', 'Cannot open CSV file', ['status' => 400]);
    }
    
    // Read header row
    $headers = fgetcsv($handle);
    
    if (!$headers || !in_array('title', $headers)) {
        fclose($handle);
        return new WP_Error('invalid_csv', 'CSV must contain "title" column', ['status' => 400]);
    }
    
    // Read data rows
    while (($row = fgetcsv($handle)) !== false) {
        if (empty($row[0])) continue; // Skip empty rows
        
        $question_data = [];
        foreach ($headers as $index => $header) {
            $question_data[$header] = $row[$index] ?? '';
        }
        
        // Parse question data
        $parsed_question = ld_parse_csv_question($question_data);
        if ($parsed_question) {
            $questions[] = $parsed_question;
        }
    }
    
    fclose($handle);
    
    if (empty($questions)) {
        return new WP_Error('no_questions', 'No valid questions found in CSV', ['status' => 400]);
    }
    
    // Create questions
    $results = [];
    $errors = [];
    
    foreach ($questions as $index => $question_data) {
        $result = ld_create_single_question($quiz_id, $quiz_pro_id, $question_data);
        
        if (is_wp_error($result)) {
            $errors[] = [
                'index' => $index,
                'title' => $question_data['title'] ?? 'Unknown',
                'error' => $result->get_error_message(),
            ];
        } else {
            $results[] = $result;
        }
    }
    
    return [
        'success' => true,
        'quiz_id' => $quiz_id,
        'created' => count($results),
        'failed' => count($errors),
        'results' => $results,
        'errors' => $errors,
    ];
}

/**
 * Parse CSV row into question data structure
 */
function ld_parse_csv_question($row) {
    if (empty($row['title'])) {
        return null;
    }
    
    $question = [
        'title' => $row['title'],
        'content' => $row['content'] ?? $row['question'] ?? '',
        'type' => $row['type'] ?? 'single',
        'points' => intval($row['points'] ?? 1),
        'answers' => [],
    ];
    
    // Parse answers (supports answer1, correct1, points1, answer2, correct2, points2, etc.)
    $answer_index = 1;
    while (isset($row['answer' . $answer_index])) {
        $answer_text = $row['answer' . $answer_index];
        if (!empty($answer_text)) {
            $question['answers'][] = [
                'answer' => $answer_text,
                'correct' => !empty($row['correct' . $answer_index]) && 
                           ($row['correct' . $answer_index] === '1' || 
                            $row['correct' . $answer_index] === 'true' || 
                            $row['correct' . $answer_index] === 'yes'),
                'points' => floatval($row['points' . $answer_index] ?? 0),
            ];
        }
        $answer_index++;
    }
    
    return $question;
}

/**
 * Create a single question (shared logic)
 */
function ld_create_single_question($quiz_id, $quiz_pro_id, $question_data) {
    global $wpdb;
    
    $title     = sanitize_text_field($question_data['title']);
    $content   = sanitize_textarea_field($question_data['content'] ?? '');
    $answers   = $question_data['answers'] ?? [];
    $points    = intval($question_data['points'] ?? 1);
    $type      = sanitize_text_field($question_data['type'] ?? 'single');

    // Get current user ID
    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        $current_user_id = 1;
    }

    // Get next sort order
    $max_sort = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(sort) FROM {$wpdb->prefix}learndash_pro_quiz_question WHERE quiz_id = %d",
        $quiz_pro_id
    ));
    $sort_order = intval($max_sort) + 1;

    // Serialize answers
    $serialized_answers = ld_serialize_answers($answers);

    // INSERT into wp_learndash_pro_quiz_question
    $wpdb->insert(
        $wpdb->prefix . 'learndash_pro_quiz_question',
        [
            'quiz_id'                              => $quiz_pro_id,
            'online'                               => 1,
            'sort'                                 => $sort_order,
            'title'                                => $title,
            'points'                               => $points,
            'question'                             => $content,
            'correct_msg'                          => '',
            'incorrect_msg'                        => '',
            'correct_same_text'                    => 0,
            'tip_enabled'                          => 0,
            'tip_msg'                              => '',
            'answer_type'                          => $type,
            'show_points_in_box'                   => 0,
            'answer_points_activated'              => 0,
            'answer_data'                          => $serialized_answers,
            'category_id'                          => 0,
            'answer_points_diff_modus_activated'   => 0,
            'disable_correct'                      => 0,
            'matrix_sort_answer_criteria_width'    => 20,
        ],
        ['%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%d']
    );

    $pro_id = $wpdb->insert_id;

    if (!$pro_id) {
        return new WP_Error('db_insert_failed', 'Failed to insert question', ['status' => 500]);
    }

    // Create WordPress post
    $question_id = wp_insert_post([
        'post_title'   => $title,
        'post_content' => $content,
        'post_type'    => 'sfwd-question',
        'post_status'  => 'publish',
        'post_author'  => $current_user_id,
    ]);

    if (is_wp_error($question_id)) {
        $wpdb->delete($wpdb->prefix . 'learndash_pro_quiz_question', ['id' => $pro_id], ['%d']);
        return new WP_Error('create_failed', 'Cannot create question post', ['status' => 500]);
    }

    // Update post meta
    update_post_meta($question_id, '_edit_last', $current_user_id);
    update_post_meta($question_id, 'quiz_id', $quiz_id);
    update_post_meta($question_id, '_sfwd-question', [
        'sfwd-question_quiz' => strval($quiz_id),
    ]);
    update_post_meta($question_id, 'question_pro_id', $pro_id);
    update_post_meta($question_id, 'question_points', $points);
    update_post_meta($question_id, 'question_type', $type);
    update_post_meta($question_id, 'question_pro_category', '0');
    update_post_meta($question_id, '_edit_lock', time() . ':' . $current_user_id);

    // Update quiz metadata
    $quiz_questions = get_post_meta($quiz_id, 'ld_quiz_questions', true);
    if (!is_array($quiz_questions)) {
        $quiz_questions = [];
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
        $wpdb->delete($wpdb->prefix . 'postmeta', ['meta_id' => $meta_id], ['%d']);
    }

    // Clear caches
    wp_cache_delete($quiz_id, 'post_meta');
    wp_cache_delete($question_id, 'post_meta');
    clean_post_cache($quiz_id);
    clean_post_cache($question_id);

    return [
        'question_id' => $question_id,
        'pro_id' => $pro_id,
        'title' => $title,
        'sort_order' => $sort_order,
    ];
}

/**
 * Serialize answers for LearnDash format
 */
function ld_serialize_answers($answers) {
    $data = [];
    
    foreach ($answers as $i => $a) {
        $data[$i] = (object)[
            '_mapper' => null,
            '_answer' => isset($a['answer']) ? $a['answer'] : '',
            '_html' => false,
            '_points' => isset($a['points']) ? floatval($a['points']) : 0.0,
            '_correct' => !empty($a['correct']),
            '_sortString' => '',
            '_sortStringHtml' => false,
            '_graded' => false,
            '_gradingProgression' => 'not-graded-none',
            '_gradedType' => null,
            '__PHP_Incomplete_Class_Name' => 'WpProQuiz_Model_AnswerTypes',
        ];
    }
    
    return serialize($data);
}
