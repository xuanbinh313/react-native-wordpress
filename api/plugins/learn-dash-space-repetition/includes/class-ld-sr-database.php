<?php
/**
 * Database operations for LearnDash Spaced Repetition
 * Handles all database queries and table management
 */

if (!defined('ABSPATH')) exit;

class LD_SR_Database {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ld_spaced_repetition';
    }
    
    /**
     * Get table name
     */
    public function get_table_name() {
        return $this->table_name;
    }
    
    /**
     * Create database table
     */
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
    
    /**
     * Get all quizzes with their questions
     */
    public function get_all_quizzes_with_questions() {
        global $wpdb;
        
        // Get all LearnDash quizzes
        $quizzes = $wpdb->get_results(
            "SELECT p.ID as quiz_id, p.post_title as quiz_title, pm.meta_value as quiz_pro_id
            FROM {$wpdb->prefix}posts p
            INNER JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id AND pm.meta_key = 'quiz_pro_id'
            WHERE p.post_type = 'sfwd-quiz' 
                AND p.post_status = 'publish'
            ORDER BY p.post_title ASC",
            ARRAY_A
        );
        
        if (empty($quizzes)) {
            return array();
        }
        
        $result = array();
        
        foreach ($quizzes as $quiz) {
            // Get questions for this quiz
            $questions = $wpdb->get_results($wpdb->prepare(
                "SELECT q.id as question_id, q.title as question_title, q.points
                FROM {$wpdb->prefix}learndash_pro_quiz_question q
                INNER JOIN {$wpdb->prefix}postmeta pm ON q.id = pm.meta_value AND pm.meta_key = 'question_pro_id'
                INNER JOIN {$wpdb->prefix}posts p ON pm.post_id = p.ID
                WHERE q.quiz_id = %d 
                    AND q.online = 1 
                    AND p.post_type = 'sfwd-question'
                    AND p.post_status = 'publish'
                ORDER BY q.sort ASC",
                $quiz['quiz_pro_id']
            ), ARRAY_A);
            
            $result[] = array(
                'quiz_id' => intval($quiz['quiz_id']),
                'quiz_title' => $quiz['quiz_title'],
                'quiz_pro_id' => intval($quiz['quiz_pro_id']),
                'question_count' => count($questions),
                'questions' => $questions
            );
        }
        
        return $result;
    }
    
    /**
     * Get all questions for a quiz
     */
    public function get_quiz_questions($quiz_id) {
        global $wpdb;
        
        $quiz_pro_id = get_post_meta($quiz_id, 'quiz_pro_id', true);
        
        if (empty($quiz_pro_id)) {
            return array();
        }
        
        return $wpdb->get_results($wpdb->prepare(
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
    }
    
    /**
     * Get question details
     */
    public function get_question($question_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, title, question, answer_data, answer_type, points
            FROM {$wpdb->prefix}learndash_pro_quiz_question
            WHERE id = %d",
            $question_id
        ), ARRAY_A);
    }
    
    /**
     * Get latest card states for user's questions
     */
    public function get_latest_card_states($user_id, $quiz_id, $question_ids) {
        global $wpdb;
        
        if (empty($question_ids)) {
            return array();
        }
        
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
        
        // Index by question_id
        $states = array();
        foreach ($results as $row) {
            $states[$row->question_id] = $row;
        }
        
        return $states;
    }
    
    /**
     * Get latest card state for a single question
     */
    public function get_latest_card_state($user_id, $quiz_id, $question_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
            WHERE user_id = %d AND quiz_id = %d AND question_id = %d
            ORDER BY id DESC LIMIT 1",
            $user_id, $quiz_id, $question_id
        ));
    }
    
    /**
     * Get previous card state (one before the latest)
     */
    public function get_previous_card_state($user_id, $quiz_id, $question_id, $current_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
            WHERE user_id = %d AND quiz_id = %d AND question_id = %d AND id < %d
            ORDER BY id DESC LIMIT 1",
            $user_id, $quiz_id, $question_id, $current_id
        ));
    }
    
    /**
     * Get statistics for a question
     */
    public function get_question_stats($user_id, $quiz_id, $question_id) {
        global $wpdb;
        
        $total_reviews = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
            WHERE user_id = %d AND quiz_id = %d AND question_id = %d",
            $user_id, $quiz_id, $question_id
        ));
        
        $wrong_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
            WHERE user_id = %d AND quiz_id = %d AND question_id = %d AND is_correct = 0",
            $user_id, $quiz_id, $question_id
        ));
        
        return array(
            'total_reviews' => intval($total_reviews),
            'wrong_count' => intval($wrong_count)
        );
    }
    
    /**
     * Insert new answer record
     */
    public function insert_answer($data) {
        global $wpdb;
        
        return $wpdb->insert(
            $this->table_name,
            $data,
            array('%d', '%d', '%d', '%d', '%s', '%d', '%f', '%s', '%s', '%d', '%s')
        );
    }
    
    /**
     * Update answer record
     */
    public function update_answer($id, $data) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            $data,
            array('id' => $id),
            array('%d', '%s', '%d', '%f', '%s', '%s'),
            array('%d')
        );
    }
}
