<?php
/**
 * REST API endpoints for LearnDash Spaced Repetition
 * Handles all API routes and request processing
 */

if (!defined('ABSPATH')) exit;

class LD_SR_REST_API {
    
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Get all quizzes with questions
        register_rest_route('ld-sr/v1', '/quizzes', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_quizzes'),
            'permission_callback' => array($this, 'permission_check'),
        ));
        
        // Get next question
        register_rest_route('ld-sr/v1', '/quiz/(?P<quiz_id>\d+)/next-question', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_next_question'),
            'permission_callback' => array($this, 'permission_check'),
            'args' => array(
                'quiz_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
        
        // Submit answer
        register_rest_route('ld-sr/v1', '/quiz/(?P<quiz_id>\d+)/question/(?P<question_id>\d+)/submit', array(
            'methods' => 'POST',
            'callback' => array($this, 'submit_answer'),
            'permission_callback' => array($this, 'permission_check'),
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
                'user_answer' => array(
                    'required' => true,
                ),
                'answer_time' => array(
                    'required' => false,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
        
        // Update rating
        register_rest_route('ld-sr/v1', '/quiz/(?P<quiz_id>\d+)/question/(?P<question_id>\d+)/rating', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_rating'),
            'permission_callback' => array($this, 'permission_check'),
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
            ),
        ));
    }
    
    /**
     * Permission check - user must be logged in
     */
    public function permission_check() {
        return is_user_logged_in();
    }
    
    /**
     * Get all quizzes with their questions
     */
    public function get_quizzes($request) {
        $quizzes = $this->db->get_all_quizzes_with_questions();
        
        return rest_ensure_response(array(
            'success' => true,
            'quizzes' => $quizzes
        ));
    }
    
    /**
     * Get next question for review
     */
    public function get_next_question($request) {
        $quiz_id = intval($request['quiz_id']);
        $user_id = get_current_user_id();
        
        $result = $this->get_next_question_logic($quiz_id, $user_id);
        
        if (is_wp_error($result)) {
            return new WP_Error('error', $result->get_error_message(), array('status' => 400));
        }
        
        return rest_ensure_response($result);
    }
    
    /**
     * Submit answer and validate
     */
    public function submit_answer($request) {
        $quiz_id = intval($request['quiz_id']);
        $question_id = intval($request['question_id']);
        $user_answer = $request['user_answer'];
        $answer_time = isset($request['answer_time']) ? intval($request['answer_time']) : null;
        $user_id = get_current_user_id();
        
        $result = $this->submit_answer_logic($quiz_id, $question_id, $user_answer, $answer_time, $user_id);
        
        if (is_wp_error($result)) {
            return new WP_Error('error', $result->get_error_message(), array('status' => 400));
        }
        
        return rest_ensure_response($result);
    }
    
    /**
     * Update rating after submission
     */
    public function update_rating($request) {
        $quiz_id = intval($request['quiz_id']);
        $question_id = intval($request['question_id']);
        $rating = sanitize_text_field($request['rating']);
        $user_id = get_current_user_id();
        
        $result = $this->update_rating_logic($quiz_id, $question_id, $rating, $user_id);
        
        if (is_wp_error($result)) {
            return new WP_Error('error', $result->get_error_message(), array('status' => 400));
        }
        
        return rest_ensure_response($result);
    }
    
    /**
     * Core logic: Get next question
     */
    private function get_next_question_logic($quiz_id, $user_id) {
        $questions = $this->db->get_quiz_questions($quiz_id);
        
        if (empty($questions)) {
            return new WP_Error('no_questions', 'No questions found in this quiz');
        }
        
        $current_time = current_time('mysql');
        $question_ids = array_column($questions, 'question_pro_id');
        
        // Get latest states
        $latest_states = $this->db->get_latest_card_states($user_id, $quiz_id, $question_ids);
        
        // Find new and due cards
        $answered_question_ids = array_keys($latest_states);
        $new_question_ids = array_diff($question_ids, $answered_question_ids);
        
        $due_cards = array();
        $today_cards = array();
        
        $today_start = strtotime(current_time('Y-m-d 00:00:00'));
        $today_end = strtotime(current_time('Y-m-d 23:59:59'));
        $current_timestamp = strtotime($current_time);
        
        foreach ($latest_states as $card) {
            $next_review_timestamp = strtotime($card->next_review_date);
            $is_today = ($next_review_timestamp >= $today_start) && ($next_review_timestamp <= $today_end);
            $is_due_now = ($next_review_timestamp <= $current_timestamp);
            
            if ($is_due_now && $is_today) {
                $due_cards[] = $card;
            } elseif ($is_today && !$is_due_now) {
                $today_cards[] = $card;
            } elseif ($is_due_now && !$is_today) {
                $due_cards[] = $card;
            }
        }
        
        // Sort due cards
        if (!empty($due_cards)) {
            usort($due_cards, function($a, $b) {
                $time_diff = strtotime($a->next_review_date) - strtotime($b->next_review_date);
                if ($time_diff !== 0) return $time_diff;
                
                $wrong_a = $this->db->get_question_stats($a->user_id, $a->quiz_id, $a->question_id)['wrong_count'];
                $wrong_b = $this->db->get_question_stats($b->user_id, $b->quiz_id, $b->question_id)['wrong_count'];
                return $wrong_b - $wrong_a;
            });
        }
        
        // Select next question
        $next_question_id = null;
        $card_state = 'new';
        
        if (!empty($due_cards)) {
            $next_question_id = $due_cards[0]->question_id;
            $card_state = $due_cards[0]->card_state;
        } elseif (!empty($new_question_ids)) {
            $new_question_ids = array_values($new_question_ids);
            $next_question_id = $new_question_ids[0];
            $card_state = 'new';
        } elseif (!empty($today_cards)) {
            $next_question_id = $today_cards[0]->question_id;
            $card_state = $today_cards[0]->card_state;
        } else {
            return array('complete' => true);
        }
        
        // Get question details
        $question_data = $this->db->get_question($next_question_id);
        
        if (!$question_data) {
            return new WP_Error('question_not_found', 'Question not found');
        }
        
        // Parse answers without correct flags
        $answers = LD_SR_Algorithm::parse_answer_data($question_data['answer_data'], false);
        
        // Get stats
        $stats = $this->db->get_question_stats($user_id, $quiz_id, $next_question_id);
        $next_review_info = isset($latest_states[$next_question_id]) ? $latest_states[$next_question_id] : null;
        
        $total_due = count($due_cards) + count($new_question_ids);
        if (empty($new_question_ids) && !empty($today_cards)) {
            $total_due += count($today_cards);
        }
        
        return array(
            'complete' => false,
            'question_id' => $next_question_id,
            'title' => $question_data['title'],
            'question' => do_shortcode($question_data['question']),
            'answer_type' => $question_data['answer_type'],
            'points' => $question_data['points'],
            'answers' => $answers,
            'remaining' => $total_due,
            'total' => count($questions),
            'card_state' => $card_state,
            'stats' => array(
                'total_reviews' => $stats['total_reviews'],
                'wrong_count' => $stats['wrong_count'],
                'new_cards' => count($new_question_ids),
                'due_cards' => count($due_cards),
                'today_cards' => count($today_cards),
                'next_review_date' => $next_review_info ? $next_review_info->next_review_date : null,
                'current_time' => $current_time
            )
        );
    }
    
    /**
     * Core logic: Submit answer
     */
    private function submit_answer_logic($quiz_id, $question_id, $user_answer, $answer_time_ms, $user_id) {
        $question_data = $this->db->get_question($question_id);
        
        if (!$question_data) {
            return new WP_Error('question_not_found', 'Question not found');
        }
        
        // Parse answers with correct flags
        $answers = LD_SR_Algorithm::parse_answer_data($question_data['answer_data'], true);
        
        // Validate answer
        $validation = LD_SR_Algorithm::validate_answer($question_data['answer_type'], $user_answer, $answers);
        $is_correct = $validation['is_correct'];
        $correct_answers = $validation['correct_answers'];
        
        // Auto-rate
        $rating = LD_SR_Algorithm::auto_rate($is_correct);
        
        // Save response
        $save_result = $this->save_response_logic($quiz_id, $question_id, $rating, $answer_time_ms, $user_id);
        
        if (is_wp_error($save_result)) {
            return $save_result;
        }
        
        return array(
            'is_correct' => $is_correct,
            'rating' => $rating,
            'correct_answers' => $correct_answers,
            'next_review' => $save_result['next_review'],
            'interval' => $save_result['interval'],
            'ease_factor' => $save_result['ease_factor'],
            'card_state' => $save_result['card_state']
        );
    }
    
    /**
     * Core logic: Update rating
     */
    private function update_rating_logic($quiz_id, $question_id, $new_rating, $user_id) {
        $latest = $this->db->get_latest_card_state($user_id, $quiz_id, $question_id);
        
        if (!$latest) {
            return new WP_Error('no_answer', 'No answer found to update');
        }
        
        $previous = $this->db->get_previous_card_state($user_id, $quiz_id, $question_id, $latest->id);
        
        $interval = $previous ? $previous->interval_days : 0;
        $ease_factor = $previous ? $previous->ease_factor : 2.5;
        $card_state = $previous ? $previous->card_state : 'new';
        
        // Recalculate with new rating
        $result = LD_SR_Algorithm::calculate_next_review($new_rating, $interval, $ease_factor, $card_state);
        
        // Update record
        $updated = $this->db->update_answer($latest->id, array(
            'is_correct' => $result['is_correct'],
            'rating' => $new_rating,
            'interval_days' => $result['interval'],
            'ease_factor' => $result['ease_factor'],
            'next_review_date' => $result['next_review_date'],
            'card_state' => $result['card_state'],
        ));
        
        if ($updated === false) {
            return new WP_Error('update_failed', 'Failed to update rating');
        }
        
        return array(
            'rating' => $new_rating,
            'is_correct' => $result['is_correct'],
            'next_review' => $result['next_review_date'],
            'interval' => $result['interval'],
            'ease_factor' => $result['ease_factor'],
            'card_state' => $result['card_state']
        );
    }
    
    /**
     * Core logic: Save response
     */
    private function save_response_logic($quiz_id, $question_id, $rating, $answer_time_ms, $user_id) {
        $latest = $this->db->get_latest_card_state($user_id, $quiz_id, $question_id);
        
        $interval = 0;
        $ease_factor = 2.5;
        $card_state = 'new';
        
        if ($latest) {
            $interval = $latest->interval_days;
            $ease_factor = $latest->ease_factor;
            $card_state = $latest->card_state;
        }
        
        // Calculate next review
        $result = LD_SR_Algorithm::calculate_next_review($rating, $interval, $ease_factor, $card_state);
        
        // Insert record
        $inserted = $this->db->insert_answer(array(
            'user_id' => $user_id,
            'quiz_id' => $quiz_id,
            'question_id' => $question_id,
            'is_correct' => $result['is_correct'],
            'rating' => $rating,
            'interval_days' => $result['interval'],
            'ease_factor' => $result['ease_factor'],
            'next_review_date' => $result['next_review_date'],
            'card_state' => $result['card_state'],
            'answer_time_ms' => $answer_time_ms,
            'answered_at' => current_time('mysql'),
        ));
        
        if (!$inserted) {
            return new WP_Error('save_failed', 'Failed to save response');
        }
        
        return array(
            'next_review' => $result['next_review_date'],
            'interval' => $result['interval'],
            'ease_factor' => $result['ease_factor'],
            'card_state' => $result['card_state'],
            'is_correct' => $result['is_correct']
        );
    }
}
