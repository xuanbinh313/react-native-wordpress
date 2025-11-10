import { useEffect, useState } from "react";
import QuestionContent from "./components/QuestionContent";

interface Quiz {
  quiz_id: number;
  quiz_title: string;
  quiz_pro_id: number;
  question_count: number;
  questions: QuestionItem[];
}

interface QuestionItem {
  question_id: number;
  question_title: string;
  points: number;
}

interface Answer {
  id: number;
  answer: string;
  html: boolean;
  points: number;
  correct?: boolean; // Optional now - only present after submission
  graded: boolean;
}

interface SubmitResult {
  is_correct: boolean;
  rating: string;
  correct_answers: Answer[];
  next_review: string;
  interval: number;
  ease_factor: number;
  card_state: string;
}

interface QuestionData {
  complete: boolean;
  question_id?: number;
  title?: string;
  question?: string;
  answer_type?: "single" | "multiple" | "cloze_answer" | "sort_answer";
  points?: number;
  answers?: Answer[];
  remaining?: number;
  total?: number;
  card_state?: string;
  stats?: {
    total_reviews: number;
    wrong_count: number;
    new_cards: number;
    due_cards: number;
    today_cards: number;
    next_review_date?: string;
    current_time?: string;
  };
}

// Declare global ldSR object from WordPress
declare global {
  interface Window {
    ldSR: {
      ajax_url: string;
      rest_url: string;
      nonce: string;
      rest_nonce: string;
    };
    wp: any;
  }
}

function App() {
  const [quizzes, setQuizzes] = useState<Quiz[]>([]);
  const [quizId, setQuizId] = useState<number>(0);
  const [questionData, setQuestionData] = useState<QuestionData | null>(null);
  const [showAnswer, setShowAnswer] = useState(false);
  const [loading, setLoading] = useState(false);
  const [isComplete, setIsComplete] = useState(false);
  const [clozeAnswers, setClozeAnswers] = useState<{ [key: number]: string }>({});
  const [submitResult, setSubmitResult] = useState<SubmitResult | null>(null);
  const [showQuizList, setShowQuizList] = useState(true);

  useEffect(() => {
    // Get quiz ID from container data attribute
    const container = document.getElementById("ld-sr-container");
    const quizIdAttr = container?.getAttribute("data-quiz-id");
    if (quizIdAttr) {
      const id = parseInt(quizIdAttr);
      setQuizId(id);
      setShowQuizList(false); // Skip quiz list if quiz_id is provided
    } else {
      // Load quiz list if no quiz_id provided
      loadQuizzes();
    }
  }, []);

  useEffect(() => {
    if (quizId > 0 && !showQuizList) {
      loadNextQuestion();
    }
  }, [quizId, showQuizList]);

  const loadQuizzes = async () => {
    setLoading(true);
    try {
      const response = await fetch(
        `${window.ldSR.rest_url}/quizzes`,
        {
          method: "GET",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": window.ldSR.rest_nonce,
          },
        }
      );

      const result = await response.json();

      if (response.ok && result.success) {
        setQuizzes(result.quizzes);
      } else {
        console.error("Error loading quizzes:", result);
        alert("Error loading quizzes. Please try again.");
      }
    } catch (error) {
      console.error("Error loading quizzes:", error);
      alert("Error loading quizzes. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  const handleStartQuiz = (quiz: Quiz) => {
    setQuizId(quiz.quiz_id);
    setShowQuizList(false);
  };

  const handleBackToQuizList = () => {
    setShowQuizList(true);
    setQuestionData(null);
    setIsComplete(false);
    setShowAnswer(false);
    setSubmitResult(null);
  };

  const loadNextQuestion = async () => {
    setLoading(true);
    setShowAnswer(false);
    setClozeAnswers({});
    setSubmitResult(null);

    try {
      // Use REST API endpoint
      const response = await fetch(
        `${window.ldSR.rest_url}/quiz/${quizId}/next-question`,
        {
          method: "GET",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": window.ldSR.rest_nonce,
          },
        }
      );

      const result = await response.json();

      if (response.ok) {
        if (result.complete) {
          setIsComplete(true);
          setQuestionData(null);
        } else {
          setQuestionData(result);
          setIsComplete(false);
        }
      } else {
        console.error("Error loading question:", result);
        alert(result.message || "Error loading question. Please try again.");
      }
    } catch (error) {
      console.error("Error loading question:", error);
      alert("Error loading question. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  const handleShowAnswer = async () => {
    if (!questionData?.question_id) return;

    // Collect user's answer based on question type
    let userAnswer: any;

    switch (questionData.answer_type) {
      case "single":
        const radioInput = document.querySelector<HTMLInputElement>(
          'input[name="user_answer"]:checked'
        );
        if (!radioInput) {
          alert("Please select an answer");
          return;
        }
        userAnswer = parseInt(radioInput.value);
        break;

      case "multiple":
        const checkboxes = document.querySelectorAll<HTMLInputElement>(
          'input[name="user_answer"]:checked'
        );
        if (checkboxes.length === 0) {
          alert("Please select at least one answer");
          return;
        }
        userAnswer = Array.from(checkboxes).map((cb) => parseInt(cb.value));
        break;

      case "cloze_answer":
        const clozeValues = Object.values(clozeAnswers);
        if (clozeValues.length === 0 || clozeValues.some((v) => !v.trim())) {
          alert("Please fill in all blanks");
          return;
        }
        userAnswer = clozeValues;
        break;

      case "sort_answer":
        // TODO: Implement sort answer logic
        alert("Sort answer not yet implemented");
        return;

      default:
        alert("Unsupported question type");
        return;
    }

    setLoading(true);

    try {
      // Submit answer to server for validation
      const response = await fetch(
        `${window.ldSR.rest_url}/quiz/${quizId}/question/${questionData.question_id}/submit`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": window.ldSR.rest_nonce,
          },
          body: JSON.stringify({
            user_answer: userAnswer,
          }),
        }
      );

      const result = await response.json();

      if (response.ok) {
        setSubmitResult(result);
        setShowAnswer(true);
      } else {
        console.error("Error submitting answer:", result);
        alert(result.message || "Error submitting answer. Please try again.");
      }
    } catch (error) {
      console.error("Error submitting answer:", error);
      alert("Error submitting answer. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  const handleRating = async (rating: string) => {
    if (!questionData?.question_id) return;

    setLoading(true);

    try {
      // Update rating on server
      const response = await fetch(
        `${window.ldSR.rest_url}/quiz/${quizId}/question/${questionData.question_id}/rating`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": window.ldSR.rest_nonce,
          },
          body: JSON.stringify({
            rating: rating,
          }),
        }
      );

      const result = await response.json();

      if (response.ok) {
        // Update submit result with new rating
        if (submitResult) {
          setSubmitResult({ ...submitResult, rating: result.rating });
        }
        // Load next question after a short delay to show the updated rating
        setTimeout(() => {
          loadNextQuestion();
        }, 500);
      } else {
        console.error("Error updating rating:", result);
        alert(result.message || "Error updating rating. Please try again.");
        setLoading(false);
      }
    } catch (error) {
      console.error("Error updating rating:", error);
      alert("Error updating rating. Please try again.");
      setLoading(false);
    }
  };

  const renderQuestion = () => {
    if (!questionData) return null;
    console.log('Rendering questionData:', window?.wp);
    return (
      <>
        {questionData.title && <h3>{questionData.title}</h3>}
        {questionData.question && <QuestionContent html={questionData.question} />}

        {questionData.answers &&
          questionData.answers.length > 0 && (
            <div className="answer-options">
              {questionData.answers.map((ans) => {
                switch (questionData.answer_type) {
                  case "single":
                    return (
                      <label key={ans.id} className="answer-option">
                        <input 
                          type={"radio"} 
                          name="user_answer" 
                          value={ans.id}
                          disabled={showAnswer}
                          onCopy={(e) => e.preventDefault()}
                          onCut={(e) => e.preventDefault()}
                          onPaste={(e) => e.preventDefault()}
                        />
                        <span dangerouslySetInnerHTML={{ __html: ans.answer }} />
                      </label>
                    );
                  case "multiple":
                    return (
                      <label key={ans.id} className="answer-option">
                        <input 
                          type={"checkbox"} 
                          name="user_answer" 
                          value={ans.id}
                          disabled={showAnswer}
                          onCopy={(e) => e.preventDefault()}
                          onCut={(e) => e.preventDefault()}
                          onPaste={(e) => e.preventDefault()}
                        />
                        <span dangerouslySetInnerHTML={{ __html: ans.answer }} />
                      </label>
                    );
                  case "cloze_answer":
                    // Parse the answer text to extract blanks and text parts
                    const parts: Array<{ type: 'text' | 'blank', content: string, index?: number }> = [];
                    const text = ans.answer || '';
                    let lastIndex = 0;
                    let blankIndex = 0;
                    
                    // Match all {text} patterns
                    const regex = /\{(.*?)\}/g;
                    let match;
                    
                    while ((match = regex.exec(text)) !== null) {
                      // Add text before the blank
                      if (match.index > lastIndex) {
                        parts.push({ type: 'text', content: text.slice(lastIndex, match.index) });
                      }
                      // Add the blank
                      parts.push({ type: 'blank', content: match[1], index: blankIndex });
                      blankIndex++;
                      lastIndex = regex.lastIndex;
                    }
                    
                    // Add remaining text
                    if (lastIndex < text.length) {
                      parts.push({ type: 'text', content: text.slice(lastIndex) });
                    }
                    
                    return (
                      <div key={ans.id} className="cloze-answer">
                        {parts.map((part, idx) => {
                          if (part.type === 'text') {
                            return <span key={idx} dangerouslySetInnerHTML={{ __html: part.content }} />;
                          } else {
                            const inputIndex = part.index!;
                            return (
                              <input
                                key={idx}
                                type="text"
                                name="cloze_answer"
                                value={clozeAnswers[inputIndex] || ''}
                                onChange={(e) => {
                                  setClozeAnswers(prev => ({
                                    ...prev,
                                    [inputIndex]: e.target.value
                                  }));
                                }}
                                className="cloze-input"
                                placeholder={showAnswer ? part.content : ''}
                                disabled={showAnswer}
                                onCopy={(e) => e.preventDefault()}
                                onCut={(e) => e.preventDefault()}
                                onPaste={(e) => e.preventDefault()}
                              />
                            );
                          }
                        })}
                      </div>
                    );
                  case "sort_answer":
                    break;
                }

              })}
            </div>
          )}
      </>
    );
  };

  const renderCorrectAnswers = () => {
    if (!submitResult) return null;

    return (
      <div className={`answer-result ${submitResult.is_correct ? 'correct' : 'incorrect'}`}>
        <h4>
          {submitResult.is_correct ? '✅ Correct!' : '❌ Incorrect'}
        </h4>
        
        {!submitResult.is_correct && submitResult.correct_answers.length > 0 && (
          <div>
            <strong>Correct Answer(s):</strong>
            <ul>
              {submitResult.correct_answers.map((ans, idx) => (
                <li key={idx}>
                  <span dangerouslySetInnerHTML={{ __html: ans.answer }} />
                  {ans.points > 0 && <span> ({ans.points} points)</span>}
                </li>
              ))}
            </ul>
          </div>
        )}
        
        <div className="auto-rating">
          <small>
            Auto-rated as: <strong>{submitResult.rating}</strong>
            {submitResult.is_correct 
              ? ' (You can adjust below if you want)' 
              : ' (You can adjust below if needed)'}
          </small>
        </div>
      </div>
    );
  };

  // Show quiz selection screen
  if (showQuizList) {
    return (
      <div id="ld-sr-quiz-list">
        <h2>📚 Select a Quiz to Start Learning</h2>
        
        {loading && (
          <div style={{ textAlign: "center", padding: "20px" }}>Loading quizzes...</div>
        )}
        
        {!loading && quizzes.length === 0 && (
          <div style={{ textAlign: "center", padding: "20px" }}>
            <p>No quizzes available.</p>
          </div>
        )}
        
        {!loading && quizzes.length > 0 && (
          <div className="quiz-list">
            {quizzes.map((quiz) => (
              <div key={quiz.quiz_id} className="quiz-card">
                <h3>{quiz.quiz_title}</h3>
                <div className="quiz-info">
                  <span>📝 {quiz.question_count} questions</span>
                </div>
                
                {quiz.questions.length > 0 && (
                  <details className="question-details">
                    <summary>View Questions</summary>
                    <ul className="question-list">
                      {quiz.questions.map((q) => (
                        <li key={q.question_id}>
                          {q.question_title} ({q.points} points)
                        </li>
                      ))}
                    </ul>
                  </details>
                )}
                
                <button
                  className="ld-sr-btn ld-sr-start-btn"
                  onClick={() => handleStartQuiz(quiz)}
                >
                  🚀 Start Learning
                </button>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }

  if (isComplete) {
    return (
      <div id="ld-sr-complete">
        <h3>🎉 Quiz Complete!</h3>
        <p>You've reviewed all questions for today.</p>
        {!quizId && (
          <button
            className="ld-sr-btn"
            onClick={handleBackToQuizList}
          >
            ← Back to Quiz List
          </button>
        )}
      </div>
    );
  }

  return (
    <div id="ld-sr-card">
      {questionData && (
        <>
          <div id="ld-sr-progress">
            Remaining: {questionData.remaining} / {questionData.total}
          </div>

          {questionData.stats && (
            <div id="ld-sr-stats">
              <div className="stats-bar">
                <span className="stat-item">
                  📊 Reviews: {questionData.stats.total_reviews}
                </span>
                <span className="stat-item">
                  ❌ Wrong: {questionData.stats.wrong_count}
                </span>
                <span className="stat-item">
                  🆕 New: {questionData.stats.new_cards}
                </span>
                <span className="stat-item">
                  📅 Due: {questionData.stats.due_cards}
                </span>
                {questionData.card_state && (
                  <span className="stat-item">
                    📌 State: {questionData.card_state}
                  </span>
                )}
                {questionData.stats.next_review_date && (
                  <span className="stat-item debug-info">
                    ⏰ Next: {questionData.stats.next_review_date}
                  </span>
                )}
                {questionData.stats.current_time && (
                  <span className="stat-item debug-info">
                    🕒 Now: {questionData.stats.current_time}
                  </span>
                )}
              </div>
            </div>
          )}

          <div id="ld-sr-question">{renderQuestion()}</div>

          {showAnswer && <div id="ld-sr-answer">{renderCorrectAnswers()}</div>}

          <div id="ld-sr-buttons">
            {!showAnswer ? (
              <button
                id="ld-sr-show-answer"
                className="ld-sr-btn"
                onClick={handleShowAnswer}
                disabled={loading}
              >
                Submit Answer
              </button>
            ) : (
              <div id="ld-sr-rating-buttons">
                <button
                  className="ld-sr-btn ld-sr-again"
                  onClick={() => handleRating("again")}
                  disabled={loading}
                >
                  ❌ Again
                  <br />
                  <small>&lt;10m</small>
                </button>
                <button
                  className="ld-sr-btn ld-sr-hard"
                  onClick={() => handleRating("hard")}
                  disabled={loading}
                >
                  😓 Hard
                  <br />
                  <small>1 day</small>
                </button>
                <button
                  className="ld-sr-btn ld-sr-good"
                  onClick={() => handleRating("good")}
                  disabled={loading}
                >
                  ✅ Good
                  <br />
                  <small>Normal</small>
                </button>
                <button
                  className="ld-sr-btn ld-sr-easy"
                  onClick={() => handleRating("easy")}
                  disabled={loading}
                >
                  😊 Easy
                  <br />
                  <small>4 days</small>
                </button>
              </div>
            )}
          </div>
        </>
      )}

      {loading && !questionData && (
        <div style={{ textAlign: "center", padding: "20px" }}>Loading...</div>
      )}
    </div>
  );
}

export default App;
