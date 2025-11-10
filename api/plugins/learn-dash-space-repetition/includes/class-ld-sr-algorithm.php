<?php
/**
 * Anki SM-2 Spaced Repetition Algorithm
 * Handles interval calculation and card state transitions
 */

if (!defined('ABSPATH')) exit;

class LD_SR_Algorithm {
    
    /**
     * Calculate next review based on Anki SM-2 algorithm
     * 
     * @param string $rating Rating: again, hard, good, easy
     * @param int $current_interval Current interval in days
     * @param float $current_ease Current ease factor
     * @param string $current_state Current card state
     * @return array Array with: interval, ease_factor, card_state, is_correct, next_review_date
     */
    public static function calculate_next_review($rating, $current_interval = 0, $current_ease = 2.5, $current_state = 'new') {
        $interval = $current_interval;
        $ease_factor = $current_ease;
        $card_state = $current_state;
        $is_correct = 1;
        
        switch ($rating) {
            case 'again':
                // Wrong answer - review again in 10 minutes
                $is_correct = 0;
                $interval = 0;
                $card_state = ($current_state === 'new') ? 'learning' : 'relearning';
                $ease_factor = max(1.3, $ease_factor - 0.2);
                break;
                
            case 'hard':
                $is_correct = 1;
                if ($current_state === 'new' || $current_state === 'learning') {
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
                if ($current_state === 'new' || $current_state === 'learning') {
                    $interval = 1;
                    $card_state = 'review';
                } else {
                    $interval = floor($interval * $ease_factor);
                }
                break;
                
            case 'easy':
                $is_correct = 1;
                if ($current_state === 'new') {
                    $interval = 4;
                } elseif ($current_state === 'learning') {
                    $interval = 4;
                } else {
                    $interval = floor($interval * $ease_factor * 1.3);
                }
                $card_state = 'review';
                $ease_factor = min(2.5, $ease_factor + 0.15);
                break;
        }
        
        // Calculate next review date
        if ($rating === 'again') {
            $next_review = date('Y-m-d H:i:s', strtotime(current_time('mysql') . " +10 minutes"));
        } else {
            $next_review = date('Y-m-d H:i:s', strtotime(current_time('mysql') . " +{$interval} days"));
        }
        
        return array(
            'interval' => $interval,
            'ease_factor' => $ease_factor,
            'card_state' => $card_state,
            'is_correct' => $is_correct,
            'next_review_date' => $next_review
        );
    }
    
    /**
     * Auto-rate answer based on correctness
     * Wrong = again, Correct = hard (conservative)
     * 
     * @param bool $is_correct Whether the answer is correct
     * @return string Rating: again or hard
     */
    public static function auto_rate($is_correct) {
        return $is_correct ? 'hard' : 'again';
    }
    
    /**
     * Parse serialized answer data to get answer options
     * 
     * @param string $answer_data_serialized Serialized answer data
     * @param bool $include_correct Whether to include correct flag (false for security)
     * @return array Array of answer options
     */
    public static function parse_answer_data($answer_data_serialized, $include_correct = true) {
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
                // Access protected/private properties
                $props = (array)$answer;
                
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
                
                $answer_item = array(
                    'id' => $index,
                    'answer' => $answer_text,
                    'html' => $html,
                    'points' => $points,
                    'graded' => $graded,
                );
                
                if ($include_correct) {
                    $answer_item['correct'] = $correct;
                }
                
                $answers[] = $answer_item;
                $index++;
            }
        }
        
        return $answers;
    }
    
    /**
     * Validate user answer and check if correct
     * 
     * @param string $answer_type Question type: single, multiple, cloze_answer, sort_answer
     * @param mixed $user_answer User's answer
     * @param array $answers All answer options with correct flags
     * @return array Array with: is_correct, correct_answers
     */
    public static function validate_answer($answer_type, $user_answer, $answers) {
        $is_correct = false;
        $correct_answers = array();
        
        switch ($answer_type) {
            case 'single':
                $user_answer_id = intval($user_answer);
                foreach ($answers as $ans) {
                    if ($ans['correct']) {
                        $correct_answers[] = $ans;
                    }
                    if ($ans['id'] === $user_answer_id && $ans['correct']) {
                        $is_correct = true;
                    }
                }
                break;
                
            case 'multiple':
                $user_answer_ids = is_array($user_answer) ? array_map('intval', $user_answer) : array(intval($user_answer));
                $correct_ids = array();
                
                foreach ($answers as $ans) {
                    if ($ans['correct']) {
                        $correct_ids[] = $ans['id'];
                        $correct_answers[] = $ans;
                    }
                }
                
                sort($user_answer_ids);
                sort($correct_ids);
                $is_correct = ($user_answer_ids === $correct_ids);
                break;
                
            case 'cloze_answer':
                $user_answers = is_array($user_answer) ? $user_answer : array($user_answer);
                
                if (!empty($answers)) {
                    $answer_text = $answers[0]['answer'];
                    preg_match_all('/\{(.*?)\}/', $answer_text, $matches);
                    $correct_values = $matches[1];
                    
                    foreach ($correct_values as $idx => $val) {
                        $correct_answers[] = array(
                            'id' => $idx,
                            'answer' => $val
                        );
                    }
                    
                    if (count($user_answers) === count($correct_values)) {
                        $is_correct = true;
                        foreach ($user_answers as $idx => $user_val) {
                            if (strtolower(trim($user_val)) !== strtolower(trim($correct_values[$idx]))) {
                                $is_correct = false;
                                break;
                            }
                        }
                    }
                }
                break;
                
            case 'sort_answer':
                $user_answer_ids = is_array($user_answer) ? array_map('intval', $user_answer) : array(intval($user_answer));
                $correct_order = array();
                
                foreach ($answers as $ans) {
                    $correct_order[] = $ans['id'];
                    $correct_answers[] = $ans;
                }
                
                $is_correct = ($user_answer_ids === $correct_order);
                break;
        }
        
        return array(
            'is_correct' => $is_correct,
            'correct_answers' => $correct_answers
        );
    }
}
