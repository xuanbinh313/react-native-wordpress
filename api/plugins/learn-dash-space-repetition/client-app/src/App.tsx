import { useEffect, useState } from "react";

interface Answer {
  id: number;
  answer: string;
  html: boolean;
  points: number;
  correct: boolean;
  graded: boolean;
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
  }
}

function App() {
  const [quizId, setQuizId] = useState<number>(0);
  const [questionData, setQuestionData] = useState<QuestionData | null>(null);
  const [showAnswer, setShowAnswer] = useState(false);
  const [loading, setLoading] = useState(false);
  const [isComplete, setIsComplete] = useState(false);

  useEffect(() => {
    // Get quiz ID from container data attribute
    const container = document.getElementById("ld-sr-container");
    const quizIdAttr = container?.getAttribute("data-quiz-id");
    if (quizIdAttr) {
      setQuizId(parseInt(quizIdAttr));
    }
  }, []);

  useEffect(() => {
    if (quizId > 0) {
      loadNextQuestion();
    }
  }, [quizId]);

  const loadNextQuestion = async () => {
    setLoading(true);
    setShowAnswer(false);

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

  const handleShowAnswer = () => {
    setShowAnswer(true);
  };

  const handleRating = async (rating: string) => {
    if (!questionData?.question_id) return;

    setLoading(true);

    try {
      // Use REST API endpoint
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
        loadNextQuestion();
      } else {
        console.error("Error saving response:", result);
        alert(result.message || "Error saving response. Please try again.");
        setLoading(false);
      }
    } catch (error) {
      console.error("Error saving response:", error);
      alert("Error saving response. Please try again.");
      setLoading(false);
    }
  };

  const renderQuestion = () => {
    if (!questionData) return null;

    return (
      <>
        {questionData.title && <h3>{questionData.title}</h3>}
        <div
          className="question-content"
          dangerouslySetInnerHTML={{ __html: questionData.question || "" }}
        />

        {questionData.answers &&
          questionData.answers.length > 0 &&
          (questionData.answer_type === "single" ||
            questionData.answer_type === "multiple") && (
            <div className="answer-options">
              {questionData.answers.map((ans) => {
                const inputType =
                  questionData.answer_type === "single" ? "radio" : "checkbox";
                return (
                  <label key={ans.id} className="answer-option">
                    <input type={inputType} name="user_answer" value={ans.id} />
                    <span dangerouslySetInnerHTML={{ __html: ans.answer }} />
                  </label>
                );
              })}
            </div>
          )}
      </>
    );
  };

  const renderCorrectAnswers = () => {
    if (!questionData?.answers) return null;

    const correctAnswers = questionData.answers.filter((ans) => ans.correct);

    if (correctAnswers.length === 0) return null;

    return (
      <div>
        <strong>Correct Answer(s):</strong>
        <ul>
          {correctAnswers.map((ans) => (
            <li key={ans.id}>
              <span dangerouslySetInnerHTML={{ __html: ans.answer }} />
              {ans.points > 0 && <span> ({ans.points} points)</span>}
            </li>
          ))}
        </ul>
      </div>
    );
  };

  if (isComplete) {
    return (
      <div id="ld-sr-complete">
        <h3>🎉 Quiz Complete!</h3>
        <p>You've reviewed all questions for today.</p>
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
                Show Answer
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
