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
    $audio_folder = $params['audio_folder'] ?? '';
    $audio_files_map = []; // Empty for bulk API since files come via JSON
    
    foreach ($questions as $index => $question_data) {
        $result = ld_create_single_question($quiz_id, $quiz_pro_id, $question_data, $audio_folder, $audio_files_map);
        
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
 * - audio_files[]: Array of audio files (optional)
 */
function ld_create_questions_csv($request) {
    global $wpdb;
    $files = $request->get_file_params();
    $params = $request->get_params();
    
    error_log("LD CSV Import: Starting CSV import");
    error_log("LD CSV Import: Request params: " . print_r($params, true));
    error_log("LD CSV Import: File params: " . print_r(array_keys($files), true));
    
    $quiz_id = intval($params['quiz_id']);
    error_log("LD CSV Import: Quiz ID: {$quiz_id}");
    
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
    
    // Process uploaded audio files into an array
    $audio_files_map = [];
    if (isset($files['audio_files'])) {
        error_log("LD CSV Import: Processing uploaded audio files");
        
        // Handle multiple file uploads
        if (is_array($files['audio_files']['name'])) {
            foreach ($files['audio_files']['name'] as $index => $filename) {
                if (!empty($filename) && !empty($files['audio_files']['tmp_name'][$index])) {
                    $audio_files_map[$filename] = [
                        'tmp_name' => $files['audio_files']['tmp_name'][$index],
                        'name' => $filename,
                        'type' => $files['audio_files']['type'][$index],
                        'size' => $files['audio_files']['size'][$index]
                    ];
                    error_log("LD CSV Import: Found audio file: {$filename}");
                }
            }
        } else {
            // Single file upload
            $filename = $files['audio_files']['name'];
            if (!empty($filename)) {
                $audio_files_map[$filename] = [
                    'tmp_name' => $files['audio_files']['tmp_name'],
                    'name' => $filename,
                    'type' => $files['audio_files']['type'],
                    'size' => $files['audio_files']['size']
                ];
                error_log("LD CSV Import: Found audio file: {$filename}");
            }
        }
        
        error_log("LD CSV Import: Total audio files uploaded: " . count($audio_files_map));
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
    
    error_log("LD CSV Import: Parsed " . count($questions) . " questions from CSV");
    
    // Create questions
    $results = [];
    $errors = [];
    $audio_folder = $params['audio_folder'] ?? '';
    
    error_log("LD CSV Import: Audio folder parameter: " . ($audio_folder ? $audio_folder : '(not provided)'));
    error_log("LD CSV Import: Uploaded audio files count: " . count($audio_files_map));
    
    foreach ($questions as $index => $question_data) {
        error_log("LD CSV Import: Processing question " . ($index + 1) . ": " . $question_data['title']);
        
        // Pass audio files map instead of folder path
        $result = ld_create_single_question($quiz_id, $quiz_pro_id, $question_data, $audio_folder, $audio_files_map);
        
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
    
    error_log("LD CSV Import: Import complete - Created: " . count($results) . ", Failed: " . count($errors));
    
    return [
        'success' => true,
        'quiz_id' => $quiz_id,
        'created' => count($results),
        'failed' => count($errors),
        'results' => $results,
        'errors' => $errors,
        'debug_info' => [
            'audio_folder' => $audio_folder,
            'total_questions_parsed' => count($questions),
        ]
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
        'media' => $row['media'] ?? '',
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
function ld_create_single_question($quiz_id, $quiz_pro_id, $question_data, $audio_folder = '', $audio_files_map = []) {
    global $wpdb;
    
    $title     = sanitize_text_field($question_data['title']);
    $content   = sanitize_textarea_field($question_data['content'] ?? '');
    $answers   = $question_data['answers'] ?? [];
    $points    = intval($question_data['points'] ?? 1);
    $type      = sanitize_text_field($question_data['type'] ?? 'single');
    $media     = sanitize_text_field($question_data['media'] ?? '');

    // Process audio file - prioritize uploaded files over folder path
    if (!empty($media)) {
        // First, check if audio file was uploaded
        if (!empty($audio_files_map) && isset($audio_files_map[$media])) {
            error_log("LD Import: Found uploaded audio file: {$media}");
            $uploaded_file = $audio_files_map[$media];
            
            $media_id = ld_upload_audio_from_temp($uploaded_file);
            
            if ($media_id && !is_wp_error($media_id)) {
                error_log("LD Import: Successfully uploaded audio from temp, Media ID: {$media_id}");
                $content .= "\n\n[playlist ids=\"{$media_id}\"]";
            } else {
                $error_msg = is_wp_error($media_id) ? $media_id->get_error_message() : 'Unknown error';
                error_log("LD Import: Failed to upload audio from temp - Error: {$error_msg}");
            }
        }
        // Fallback: try filesystem path if provided
        elseif (!empty($audio_folder)) {
            // Normalize path separators for the current OS
            $audio_folder = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $audio_folder), DIRECTORY_SEPARATOR);
            $audio_file_path = $audio_folder . DIRECTORY_SEPARATOR . $media;
            
            // Log: Check audio file path
            error_log("LD Import: Checking audio file - Media: {$media}, Folder: {$audio_folder}");
            error_log("LD Import: Full audio path: {$audio_file_path}");
            error_log("LD Import: DIRECTORY_SEPARATOR: " . DIRECTORY_SEPARATOR);
            error_log("LD Import: PHP OS: " . PHP_OS);
            
            // Try to list directory contents to help debug
            $dir_exists = is_dir($audio_folder);
            error_log("LD Import: Directory exists: " . ($dir_exists ? 'YES' : 'NO'));
            
            if ($dir_exists) {
                $files = @scandir($audio_folder);
                if ($files) {
                    error_log("LD Import: Files in directory: " . implode(', ', array_slice($files, 0, 10)));
                } else {
                    error_log("LD Import: Cannot read directory contents");
                }
            }
            
            error_log("LD Import: File exists: " . (file_exists($audio_file_path) ? 'YES' : 'NO'));
            
            if (file_exists($audio_file_path)) {
                error_log("LD Import: Attempting to upload audio file: {$audio_file_path}");
                $media_id = ld_upload_audio_to_media($audio_file_path, $media);
                
                if ($media_id && !is_wp_error($media_id)) {
                    // Append playlist shortcode to content
                    error_log("LD Import: Successfully uploaded audio, Media ID: {$media_id}");
                    $content .= "\n\n[playlist ids=\"{$media_id}\"]";
                } else {
                    $error_msg = is_wp_error($media_id) ? $media_id->get_error_message() : 'Unknown error';
                    error_log("LD Import: Failed to upload audio - Error: {$error_msg}");
                }
            } else {
                error_log("LD Import: Audio file not found, skipping: {$audio_file_path}");
            }
        } else {
            if (empty($media)) {
                error_log("LD Import: No media field provided for question: {$title}");
            }
            if (empty($audio_folder)) {
                error_log("LD Import: No audio folder provided");
            }
        }
    }

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

/**
 * Upload audio file from HTTP upload (temp file)
 */
function ld_upload_audio_from_temp($uploaded_file) {
    error_log("LD Upload Temp: Starting upload from temp file");
    error_log("LD Upload Temp: File info: " . print_r($uploaded_file, true));
    
    if (empty($uploaded_file['tmp_name']) || !file_exists($uploaded_file['tmp_name'])) {
        error_log("LD Upload Temp: ERROR - Temp file does not exist");
        return false;
    }
    
    error_log("LD Upload Temp: Temp file exists, loading WordPress functions");
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    // Use WordPress function to handle the upload
    $file_array = array(
        'name'     => $uploaded_file['name'],
        'tmp_name' => $uploaded_file['tmp_name']
    );
    
    error_log("LD Upload Temp: Handling sideload for: " . $uploaded_file['name']);
    
    // Upload file to WordPress
    $attach_id = media_handle_sideload($file_array, 0);
    
    if (is_wp_error($attach_id)) {
        error_log("LD Upload Temp: ERROR - media_handle_sideload failed: " . $attach_id->get_error_message());
        @unlink($file_array['tmp_name']);
        return false;
    }
    
    error_log("LD Upload Temp: Upload complete! Media ID: {$attach_id}");
    return $attach_id;
}

/**
 * Upload audio file to WordPress media library
 */
function ld_upload_audio_to_media($file_path, $filename) {
    error_log("LD Upload: Starting upload function");
    error_log("LD Upload: File path: {$file_path}");
    error_log("LD Upload: Filename: {$filename}");
    
    if (!file_exists($file_path)) {
        error_log("LD Upload: ERROR - File does not exist at path: {$file_path}");
        return false;
    }
    
    error_log("LD Upload: File exists, checking readability");
    if (!is_readable($file_path)) {
        error_log("LD Upload: ERROR - File is not readable: {$file_path}");
        return false;
    }
    
    error_log("LD Upload: File is readable, loading WordPress functions");
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    $wp_upload_dir = wp_upload_dir();
    error_log("LD Upload: WP Upload dir path: " . $wp_upload_dir['path']);
    error_log("LD Upload: WP Upload dir url: " . $wp_upload_dir['url']);
    
    $upload_path = $wp_upload_dir['path'] . '/' . basename($filename);
    error_log("LD Upload: Target upload path: {$upload_path}");
    
    // Copy file to uploads directory
    error_log("LD Upload: Attempting to copy file...");
    if (!copy($file_path, $upload_path)) {
        error_log("LD Upload: ERROR - Failed to copy file from {$file_path} to {$upload_path}");
        $error = error_get_last();
        if ($error) {
            error_log("LD Upload: Copy error details: " . print_r($error, true));
        }
        return false;
    }
    
    error_log("LD Upload: File copied successfully");
    
    // Prepare attachment data
    $file_type = wp_check_filetype(basename($filename), null);
    error_log("LD Upload: File type detected: " . print_r($file_type, true));
    
    $attachment = array(
        'guid'           => $wp_upload_dir['url'] . '/' . basename($filename),
        'post_mime_type' => $file_type['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($filename)),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );
    
    error_log("LD Upload: Attachment data: " . print_r($attachment, true));
    
    // Insert attachment
    error_log("LD Upload: Inserting attachment to database...");
    $attach_id = wp_insert_attachment($attachment, $upload_path);
    
    if (is_wp_error($attach_id)) {
        error_log("LD Upload: ERROR - wp_insert_attachment failed: " . $attach_id->get_error_message());
        return false;
    }
    
    error_log("LD Upload: Attachment inserted with ID: {$attach_id}");
    
    // Generate metadata
    error_log("LD Upload: Generating attachment metadata...");
    $attach_data = wp_generate_attachment_metadata($attach_id, $upload_path);
    error_log("LD Upload: Metadata generated: " . print_r($attach_data, true));
    
    wp_update_attachment_metadata($attach_id, $attach_data);
    error_log("LD Upload: Metadata updated successfully");
    
    error_log("LD Upload: Upload complete! Returning media ID: {$attach_id}");
    return $attach_id;
}

/**
 * Register shortcode for import UI
 */
add_shortcode('ld_import_questions_ui', 'ld_import_questions_ui_shortcode');

function ld_import_questions_ui_shortcode() {
    ob_start();
    ?>
    <div class="ld-import-questions-wrapper" style="max-width: 800px; margin: 20px auto; padding: 20px; background: #fff; border: 1px solid #ccc; border-radius: 5px;">
        <h2>Import Questions with Audio</h2>
        
        <form id="ld-import-form" enctype="multipart/form-data">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Select Quiz:</label>
                <select name="quiz_id" id="ld-quiz-select" required style="width: 100%; padding: 8px;">
                    <option value="">-- Select Quiz --</option>
                    <?php
                    $quizzes = get_posts([
                        'post_type' => 'sfwd-quiz',
                        'posts_per_page' => -1,
                        'orderby' => 'title',
                        'order' => 'ASC'
                    ]);
                    foreach ($quizzes as $quiz) {
                        echo '<option value="' . esc_attr($quiz->ID) . '">' . esc_html($quiz->post_title) . '</option>';
                    }
                    ?>
                </select>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">CSV File:</label>
                <input type="file" name="csv_file" id="ld-csv-file" accept=".csv" required style="width: 100%;">
                <small>CSV format: title, content, type, points, media, answer1, correct1, points1, ...</small>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Audio Files:</label>
                <input type="file" name="audio_files[]" id="ld-audio-files" accept="audio/*" multiple style="width: 100%;">
                <small style="color: #0073aa; font-weight: bold;">✓ Select all audio files referenced in the CSV "media" column</small><br>
                <small>Supported formats: MP3, WAV, OGG, M4A. File names must match the CSV "media" column exactly.</small>
            </div>
            
            <div style="margin-bottom: 15px;">
                <button type="submit" id="ld-import-btn" style="padding: 10px 20px; background: #0073aa; color: #fff; border: none; border-radius: 3px; cursor: pointer; font-size: 14px;">
                    Start Import
                </button>
            </div>
            
            <div id="ld-import-progress" style="display: none; margin-top: 20px;">
                <div style="background: #f0f0f0; padding: 10px; border-radius: 3px;">
                    <div id="ld-progress-bar" style="background: #0073aa; height: 20px; width: 0%; border-radius: 3px; transition: width 0.3s;"></div>
                </div>
                <div id="ld-progress-text" style="margin-top: 10px; font-size: 14px;"></div>
            </div>
            
            <div id="ld-import-results" style="margin-top: 20px;"></div>
        </form>
    </div>
    
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Show selected files count
            $('#ld-audio-files').on('change', function() {
                var fileCount = this.files.length;
                if (fileCount > 0) {
                    var fileNames = [];
                    for (var i = 0; i < Math.min(fileCount, 5); i++) {
                        fileNames.push(this.files[i].name);
                    }
                    var message = fileCount + ' file(s) selected';
                    if (fileCount > 5) {
                        message += ' (showing first 5): ' + fileNames.join(', ') + '...';
                    } else {
                        message += ': ' + fileNames.join(', ');
                    }
                    $(this).next('small').after('<div style="margin-top:5px; color:#0073aa; font-weight:bold;">' + message + '</div>');
                }
            });
            
            // Handle form submission
            $('#ld-import-form').on('submit', function(e) {
                e.preventDefault();
                
                var quizId = $('#ld-quiz-select').val();
                var csvFile = $('#ld-csv-file')[0].files[0];
                var audioFiles = $('#ld-audio-files')[0].files;
                
                if (!quizId) {
                    alert('Please select a quiz');
                    return;
                }
                
                if (!csvFile) {
                    alert('Please select a CSV file');
                    return;
                }
                
                // Show progress
                $('#ld-import-progress').show();
                $('#ld-progress-bar').css('width', '0%');
                $('#ld-progress-text').text('Preparing import...');
                $('#ld-import-btn').prop('disabled', true);
                $('#ld-import-results').html('');
                
                // Prepare form data
                var formData = new FormData();
                formData.append('quiz_id', quizId);
                formData.append('file', csvFile);
                
                // Append all audio files
                if (audioFiles.length > 0) {
                    $('#ld-progress-text').text('Uploading ' + audioFiles.length + ' audio file(s)...');
                    for (var i = 0; i < audioFiles.length; i++) {
                        formData.append('audio_files[]', audioFiles[i]);
                    }
                }
                
                // Send AJAX request
                $.ajax({
                    url: '<?php echo rest_url('ld/v1/questions/csv'); ?>',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                        $('#ld-progress-bar').css('width', '50%');
                        $('#ld-progress-text').text('Processing questions...');
                    },
                    success: function(response) {
                        $('#ld-progress-bar').css('width', '100%');
                        $('#ld-progress-text').text('Import complete!');
                        
                        var html = '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 3px; color: #155724;">';
                        html += '<h3>Import Results</h3>';
                        html += '<p><strong>Created:</strong> ' + response.created + ' questions</p>';
                        html += '<p><strong>Failed:</strong> ' + response.failed + ' questions</p>';
                        
                        if (response.errors && response.errors.length > 0) {
                            html += '<h4>Errors:</h4><ul>';
                            response.errors.forEach(function(error) {
                                html += '<li>' + error.title + ': ' + error.error + '</li>';
                            });
                            html += '</ul>';
                        }
                        
                        html += '</div>';
                        $('#ld-import-results').html(html);
                        $('#ld-import-btn').prop('disabled', false);
                    },
                    error: function(xhr, status, error) {
                        $('#ld-progress-bar').css('width', '100%').css('background', '#dc3545');
                        $('#ld-progress-text').text('Import failed!');
                        
                        var errorMsg = 'Unknown error occurred';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        
                        var html = '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 3px; color: #721c24;">';
                        html += '<h3>Error</h3>';
                        html += '<p>' + errorMsg + '</p>';
                        html += '</div>';
                        $('#ld-import-results').html(html);
                        $('#ld-import-btn').prop('disabled', false);
                    }
                });
            });
        });
    </script>
    
    <?php
    return ob_get_clean();
}

/**
 * Add admin menu for import page
 */
add_action('admin_menu', 'ld_import_questions_admin_menu');

function ld_import_questions_admin_menu() {
    add_submenu_page(
        'edit.php?post_type=sfwd-question',
        'Import Questions',
        'Import Questions',
        'edit_posts',
        'ld-import-questions',
        'ld_import_questions_admin_page'
    );
}

function ld_import_questions_admin_page() {
    echo '<div class="wrap">';
    echo do_shortcode('[ld_import_questions_ui]');
    echo '</div>';
}
