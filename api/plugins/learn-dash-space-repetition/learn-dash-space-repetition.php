<?php
/**
 * Plugin Name: LearnDash Spaced Repetition
 * Description: Adds Anki-style spaced repetition to LearnDash quizzes with database tracking
 * Version: 2.0
 * Author: Your Name
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

class LearnDash_Spaced_Repetition {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ld_spaced_repetition';
        
        register_activation_hook(__FILE__, array($this, 'create_table'));
        add_shortcode('ld_spaced_repetition', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Legacy AJAX endpoints (kept for backward compatibility)
        add_action('wp_ajax_ld_sr_save_response', array($this, 'save_response'));
        add_action('wp_ajax_ld_sr_get_next_question', array($this, 'get_next_question'));
        
        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Get next question endpoint
        register_rest_route('ld-sr/v1', '/quiz/(?P<quiz_id>\d+)/next-question', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_next_question'),
            'permission_callback' => array($this, 'rest_permission_check'),
            'args' => array(
                'quiz_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
        
        // Save rating endpoint
        register_rest_route('ld-sr/v1', '/quiz/(?P<quiz_id>\d+)/question/(?P<question_id>\d+)/rating', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_save_rating'),
            'permission_callback' => array($this, 'rest_permission_check'),
            'args' => array(
                'quiz_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
                'question_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
                'rating' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return in_array($param, array('again', 'hard', 'good', 'easy'));
                    }
                ),
                'answer_time' => array(
                    'required' => false,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
    }
    
    /**
     * Permission check for REST API endpoints
     */
    public function rest_permission_check() {
        return is_user_logged_in();
    }
    
    /**
     * REST API: Get next question
     */
    public function rest_get_next_question($request) {
        $quiz_id = intval($request['quiz_id']);
        $user_id = get_current_user_id();
        
        return $this->get_next_question_logic($quiz_id, $user_id);
    }
    
    /**
     * REST API: Save rating
     */
    public function rest_save_rating($request) {
        $quiz_id = intval($request['quiz_id']);
        $question_id = intval($request['question_id']);
        $rating = sanitize_text_field($request['rating']);
        $answer_time = isset($request['answer_time']) ? intval($request['answer_time']) : null;
        $user_id = get_current_user_id();
        
        return $this->save_response_logic($quiz_id, $question_id, $rating, $answer_time, $user_id);
    }
    
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            quiz_id bigint(20) NOT NULL,
            question_id bigint(20) NOT NULL,
            is_correct tinyint(1) NOT NULL COMMENT '1=correct, 0=wrong',
            rating varchar(10) DEFAULT NULL COMMENT 'again, hard, good, easy',
            interval_days int(11) DEFAULT 0 COMMENT 'Days until next review',
            ease_factor decimal(3,2) DEFAULT 2.50 COMMENT 'Anki ease factor',
            next_review_date datetime NOT NULL COMMENT 'Next scheduled review',
            card_state varchar(20) DEFAULT 'new' COMMENT 'new, learning, review, relearning',
            answer_time_ms int(11) DEFAULT NULL COMMENT 'Time taken to answer in milliseconds',
            answered_at datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'When this answer was recorded',
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY quiz_id (quiz_id),
            KEY question_id (question_id),
            KEY user_quiz_question (user_id, quiz_id, question_id),
            KEY next_review_date (next_review_date),
            KEY card_state (card_state)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
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
    
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'quiz_id' => 0
        ), $atts);
        
        if (!$atts['quiz_id']) {
            return '<p>Please specify a quiz_id parameter.</p>';
        }
        
        // Simply return the container div - React will handle the content
        return '<div id="ld-sr-container" data-quiz-id="' . esc_attr($atts['quiz_id']) . '"></div>';
    }
    
    /**
     * Get next question for the user to review
     * 
     * Flow:
     * 1. Get ALL published questions from quiz (regardless of table)
     * 2. Get latest state from table for answered questions
     * 3. Identify NEW cards (questions NOT in table = never answered)
     * 4. Identify DUE cards (next_review_date <= now)
     * 5. Priority: DUE cards first, then NEW cards
     * 6. Return question data with stats
     * 
     * When table is empty: ALL questions are NEW cards and will be learned
     */
    public function get_next_question() {
        check_ajax_referer('ld_sr_nonce', 'nonce');
        
        $quiz_id = intval($_POST['quiz_id']);
        $user_id = get_current_user_id();
        
        $result = $this->get_next_question_logic($quiz_id, $user_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }
    
    /**
     * Core logic for getting next question (used by both AJAX and REST API)
     */
    private function get_next_question_logic($quiz_id, $user_id) {
        global $wpdb;
        
        // Step 1: Get ALL published questions from LearnDash quiz
        // Query directly from database for reliability
        
        // First, get quiz_pro_id from quiz post meta
        $quiz_pro_id = get_post_meta($quiz_id, 'quiz_pro_id', true);
        
        if (empty($quiz_pro_id)) {
            return new WP_Error('invalid_quiz', 'Invalid quiz: quiz_pro_id not found');
        }
        
        // Get all questions for this quiz from wp_learndash_pro_quiz_question table
        // Join with wp_posts to ensure we only get PUBLISHED questions
        $questions = $wpdb->get_results($wpdb->prepare(
            "SELECT q.id as question_pro_id, q.title, q.question, q.answer_type, q.points, q.sort
            FROM {$wpdb->prefix}learndash_pro_quiz_question q
            INNER JOIN {$wpdb->prefix}postmeta pm ON q.id = pm.meta_value AND pm.meta_key = 'question_pro_id'
            INNER JOIN {$wpdb->prefix}posts p ON pm.post_id = p.ID
            WHERE q.quiz_id = %d 
                AND q.online = 1 
                AND p.post_type = 'sfwd-question'
                AND p.post_status = 'publish'
            ORDER BY q.sort ASC",
            $quiz_pro_id
        ), ARRAY_A);
        
        if (empty($questions)) {
            return new WP_Error('no_questions', 'No questions found in this quiz');
        }
        
        // Get current time in MySQL format for comparison with next_review_date
        $current_time = current_time('mysql');
        $question_ids = array_column($questions, 'question_pro_id');
        
        // Step 2: Get latest answer state for each question that HAS been answered
        // We need to get the MOST RECENT record (highest id) for each question
        // Then filter by next_review_date to find which are due
        $latest_states = array();
        
        if (!empty($question_ids)) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT t1.* FROM {$this->table_name} t1
                INNER JOIN (
                    SELECT question_id, MAX(id) as max_id
                    FROM {$this->table_name}
                    WHERE user_id = %d AND quiz_id = %d AND question_id IN (" . implode(',', array_map('intval', $question_ids)) . ")
                    GROUP BY question_id
                ) t2 ON t1.question_id = t2.question_id AND t1.id = t2.max_id
                ORDER BY t1.next_review_date ASC, t1.question_id ASC",
                $user_id, $quiz_id
            ));
            
            // Index by question_id for easy lookup
            foreach ($results as $row) {
                $latest_states[$row->question_id] = $row;
            }
        }
        
        // Debug: Log current time and latest states
        error_log('Current Time (WordPress): ' . $current_time);
        error_log('Current Time (UTC): ' . gmdate('Y-m-d H:i:s'));
        error_log('Timezone: ' . wp_timezone_string());
        error_log('Latest States: ' . json_encode($latest_states));
        
        // Step 3: Identify NEW cards (questions that have never been answered)
        // These are ALL questions from the quiz that don't exist in our table yet
        $answered_question_ids = array_keys($latest_states);
        $new_question_ids = array_diff($question_ids, $answered_question_ids);
        
        // Step 4: Find DUE cards and TODAY cards
        // DUE cards: Cards that need review now (next_review_date <= current_time)
        // TODAY cards: Cards scheduled for today but not yet due (used as fallback when no new cards)
        $due_cards = array();
        $today_cards = array(); // Cards scheduled for today but time hasn't arrived yet
        
        // Get start and end of today for comparison using WordPress timezone
        $today_start = strtotime(current_time('Y-m-d 00:00:00'));
        $today_end = strtotime(current_time('Y-m-d 23:59:59'));
        $current_timestamp = strtotime($current_time);
        
        foreach ($latest_states as $card) {
            // Convert to timestamps for accurate comparison
            $next_review_timestamp = strtotime($card->next_review_date);
            
            // Calculate time remaining until due (in minutes)
            $time_remaining_seconds = $next_review_timestamp - $current_timestamp;
            $time_remaining_minutes = round($time_remaining_seconds / 60);
            
            // Check if card is scheduled for today (not future dates)
            $is_today = ($next_review_timestamp >= $today_start) && ($next_review_timestamp <= $today_end);
            
            // Check if card is actually due now (time has passed)
            $is_due_now = ($next_review_timestamp <= $current_timestamp);
            
            // Debug log for each card
            error_log(sprintf(
                'Question %d: next_review=%s (%d), current=%s (%d), today_start=%s (%d), today_end=%s (%d), is_today=%s, is_due_now=%s, time_until_due=%d minutes',
                $card->question_id,
                $card->next_review_date,
                $next_review_timestamp,
                $current_time,
                $current_timestamp,
                current_time('Y-m-d 00:00:00'),
                $today_start,
                current_time('Y-m-d 23:59:59'),
                $today_end,
                $is_today ? 'YES' : 'NO',
                $is_due_now ? 'YES' : 'NO',
                $time_remaining_minutes
            ));
            
            // Categorize cards
            if ($is_due_now && $is_today) {
                // Card is due now and scheduled for today
                $due_cards[] = $card;
            } elseif ($is_today && !$is_due_now) {
                // Card is scheduled for today but time hasn't arrived yet
                $today_cards[] = $card;
            } elseif ($is_due_now && !$is_today) {
                // Card is overdue from previous days
                $due_cards[] = $card;
            }
        }
        
        error_log('Total due cards found: ' . count($due_cards));
        error_log('Total today cards (not yet due): ' . count($today_cards));
        
        // Step 5: Sort due cards by next_review_date ASC, then by wrong count DESC
        if (!empty($due_cards)) {
            usort($due_cards, function($a, $b) use ($wpdb) {
                $time_diff = strtotime($a->next_review_date) - strtotime($b->next_review_date);
                if ($time_diff !== 0) return $time_diff;
                
                // Count wrong answers for each question
                $wrong_a = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} 
                    WHERE user_id = %d AND question_id = %d AND is_correct = 0",
                    $a->user_id, $a->question_id
                ));
                $wrong_b = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} 
                    WHERE user_id = %d AND question_id = %d AND is_correct = 0",
                    $b->user_id, $b->question_id
                ));
                return $wrong_b - $wrong_a; // More wrongs first
            });
        }
        
        // Step 6: Select next question based on priority
        // Priority 1: Due cards (already time to review)
        // Priority 2: New cards (never answered before)
        // Priority 3: Today cards (scheduled for today but time hasn't arrived yet - only if no new cards)
        // Priority 4: Nothing available - show complete
        $next_question_id = null;
        $card_state = 'new';
        
        if (!empty($due_cards)) {
            // Pick first due card (already sorted by date and wrong count)
            $next_question_id = $due_cards[0]->question_id;
            $card_state = $due_cards[0]->card_state;
            error_log('Selected from DUE cards: question_id=' . $next_question_id);
        } elseif (!empty($new_question_ids)) {
            // Pick first new card (question that has never been answered)
            // Reset array keys to avoid "Undefined array key 0" error
            $new_question_ids = array_values($new_question_ids);
            $next_question_id = $new_question_ids[0];
            $card_state = 'new';
            error_log('Selected from NEW cards: question_id=' . $next_question_id);
        } elseif (!empty($today_cards)) {
            // No new cards available, but there are cards scheduled for today
            // Let user study them early (better than showing "complete")
            $next_question_id = $today_cards[0]->question_id;
            $card_state = $today_cards[0]->card_state;
            error_log('Selected from TODAY cards (early review): question_id=' . $next_question_id . ', scheduled_for=' . $today_cards[0]->next_review_date);
        } else {
            // All questions have been reviewed and none are scheduled for today
            // This happens when all cards have been answered and none are due for review yet
            error_log('No questions available: due_cards=' . count($due_cards) . ', new_cards=' . count($new_question_ids) . ', today_cards=' . count($today_cards));
            return array('complete' => true);
        }
        
        // Get question details from database
        $question_data = $wpdb->get_row($wpdb->prepare(
            "SELECT id, title, question, answer_data, answer_type, points
            FROM {$wpdb->prefix}learndash_pro_quiz_question
            WHERE id = %d",
            $next_question_id
        ), ARRAY_A);
        
        if (!$question_data) {
            return new WP_Error('question_not_found', 'Question not found');
        }
        
        // Parse answer data to get ALL answers with correct flag
        $answers = $this->parse_answer_data($question_data['answer_data']);
        
        // Step 7: Calculate statistics for display
        // Include today_cards in the count only if there are no new cards (otherwise they're bonus)
        $total_due = count($due_cards) + count($new_question_ids);
        if (empty($new_question_ids) && !empty($today_cards)) {
            $total_due += count($today_cards);
        }
        
        $total_reviews = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE user_id = %d AND quiz_id = %d AND question_id = %d",
            $user_id, $quiz_id, $next_question_id
        ));
        $wrong_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE user_id = %d AND quiz_id = %d AND question_id = %d AND is_correct = 0",
            $user_id, $quiz_id, $next_question_id
        ));
        
        // Get next review date for this question (if exists)
        $next_review_info = isset($latest_states[$next_question_id]) ? $latest_states[$next_question_id] : null;
        
        // Return question data
        return array(
            'complete' => false,
            'question_id' => $next_question_id,
            'title' => $question_data['title'],
            'question' => $question_data['question'],
            'answer_type' => $question_data['answer_type'],
            'points' => $question_data['points'],
            'answers' => $answers, // All answer options with correct flags
            'remaining' => $total_due,
            'total' => count($questions),
            'card_state' => $card_state,
            'stats' => array(
                'total_reviews' => $total_reviews,
                'wrong_count' => $wrong_count,
                'new_cards' => count($new_question_ids),
                'due_cards' => count($due_cards),
                'today_cards' => count($today_cards), // Cards scheduled for today but not yet due
                'next_review_date' => $next_review_info ? $next_review_info->next_review_date : null,
                'current_time' => $current_time
            )
        );
    }
    
    /**
     * Parse serialized answer data to get ALL answers
     * Returns array of answers with their properties
     */
    private function parse_answer_data($answer_data_serialized) {
        if (empty($answer_data_serialized)) {
            return array();
        }
        
        $answer_data = @unserialize($answer_data_serialized);
        
        if (!is_array($answer_data)) {
            return array();
        }
        
        $answers = array();
        $index = 0;
        
        foreach ($answer_data as $answer) {
            if (is_object($answer)) {
                // Access protected/private properties using get_object_vars or via array cast
                $props = (array)$answer;
                
                // Protected properties have \0*\0 prefix in their keys
                $answer_text = isset($props["\0*\0_answer"]) ? $props["\0*\0_answer"] : 
                              (isset($props['_answer']) ? $props['_answer'] : '');
                              
                $html = isset($props["\0*\0_html"]) ? $props["\0*\0_html"] : 
                       (isset($props['_html']) ? $props['_html'] : false);
                       
                $points = isset($props["\0*\0_points"]) ? floatval($props["\0*\0_points"]) : 
                         (isset($props['_points']) ? floatval($props['_points']) : 0);
                         
                $correct = isset($props["\0*\0_correct"]) ? (bool)$props["\0*\0_correct"] : 
                          (isset($props['_correct']) ? (bool)$props['_correct'] : false);
                          
                $graded = isset($props["\0*\0_graded"]) ? $props["\0*\0_graded"] : 
                         (isset($props['_graded']) ? $props['_graded'] : false);
                
                $answers[] = array(
                    'id' => $index,
                    'answer' => $answer_text,
                    'html' => $html,
                    'points' => $points,
                    'correct' => $correct,
                    'graded' => $graded,
                );
                $index++;
            }
        }
        
        return $answers;
    }
    
    public function save_response() {
        check_ajax_referer('ld_sr_nonce', 'nonce');
        
        $quiz_id = intval($_POST['quiz_id']);
        $question_id = intval($_POST['question_id']);
        $rating = sanitize_text_field($_POST['rating']);
        $answer_time = isset($_POST['answer_time']) ? intval($_POST['answer_time']) : null;
        $user_id = get_current_user_id();
        
        $result = $this->save_response_logic($quiz_id, $question_id, $rating, $answer_time, $user_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }
    
    /**
     * Core logic for saving response (used by both AJAX and REST API)
     */
    private function save_response_logic($quiz_id, $question_id, $rating, $answer_time_ms, $user_id) {
        global $wpdb;
        
        // Get latest state for this question
        $latest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
            WHERE user_id = %d AND quiz_id = %d AND question_id = %d
            ORDER BY id DESC LIMIT 1",
            $user_id, $quiz_id, $question_id
        ));
        
        // Initialize values for new cards
        $interval = 0;
        $ease_factor = 2.5;
        $card_state = 'new';
        $is_correct = 1; // Assume correct unless rating is 'again'
        
        if ($latest) {
            $interval = $latest->interval_days;
            $ease_factor = $latest->ease_factor;
            $card_state = $latest->card_state;
        }
        
        // Anki SM-2 algorithm
        switch ($rating) {
            case 'again':
                // Wrong answer - review again in 10 minutes
                $is_correct = 0;
                $interval = 0; // 0 days, but we'll add 10 minutes below
                $card_state = ($card_state === 'new') ? 'learning' : 'relearning';
                $ease_factor = max(1.3, $ease_factor - 0.2);
                break;
                
            case 'hard':
                $is_correct = 1;
                if ($card_state === 'new' || $card_state === 'learning') {
                    $interval = 1;
                    $card_state = 'learning';
                } else {
                    $interval = max(1, floor($interval * 1.2));
                    $card_state = 'review';
                }
                $ease_factor = max(1.3, $ease_factor - 0.15);
                break;
                
            case 'good':
                $is_correct = 1;
                if ($card_state === 'new' || $card_state === 'learning') {
                    $interval = 1;
                    $card_state = 'review';
                } else {
                    $interval = floor($interval * $ease_factor);
                }
                break;
                
            case 'easy':
                $is_correct = 1;
                if ($card_state === 'new') {
                    $interval = 4;
                } elseif ($card_state === 'learning') {
                    $interval = 4;
                } else {
                    $interval = floor($interval * $ease_factor * 1.3);
                }
                $card_state = 'review';
                $ease_factor = min(2.5, $ease_factor + 0.15);
                break;
        }
        
        // Calculate next review date using WordPress timezone for consistency
        // For "again" rating, review in 10 minutes instead of immediately
        if ($rating === 'again') {
            $next_review = date('Y-m-d H:i:s', strtotime(current_time('mysql') . " +10 minutes"));
        } else {
            $next_review = date('Y-m-d H:i:s', strtotime(current_time('mysql') . " +{$interval} days"));
        }
        
        // Debug log the save operation
        error_log(sprintf(
            'Saving response: question_id=%d, rating=%s, current_time=%s, next_review=%s, interval=%d days',
            $question_id,
            $rating,
            current_time('mysql'),
            $next_review,
            $interval
        ));
        
        // Insert new history record
        $inserted = $wpdb->insert(
            $this->table_name,
            array(
                'user_id' => $user_id,
                'quiz_id' => $quiz_id,
                'question_id' => $question_id,
                'is_correct' => $is_correct,
                'rating' => $rating,
                'interval_days' => $interval,
                'ease_factor' => $ease_factor,
                'next_review_date' => $next_review,
                'card_state' => $card_state,
                'answer_time_ms' => $answer_time_ms,
                'answered_at' => current_time('mysql'), // Use WordPress timezone
            ),
            array('%d', '%d', '%d', '%d', '%s', '%d', '%f', '%s', '%s', '%d', '%s')
        );
        
        if (!$inserted) {
            return new WP_Error('save_failed', 'Failed to save response');
        }
        
        return array(
            'next_review' => $next_review,
            'interval' => $interval,
            'ease_factor' => $ease_factor,
            'card_state' => $card_state,
            'is_correct' => $is_correct
        );
    }
}

new LearnDash_Spaced_Repetition();
?>