import React, { useState } from 'react';

interface Deck {
    mid: string;
    model_name: string;
    notes: Note[];
    total_fields: number;
}

interface DeckConfig {
    questionMapping: string;  // e.g., "0|1"
    answerMapping: string;    // e.g., "2|3"
    questionType: string;     // e.g., "cloze_answer", "multiple", "single"
}

interface Note {
    id: string;
    question: string;
    answer: string;
    extra: string;
    tags: string;
    fields: string[];
    preview: {
        question: string;
        answer: string;
    };
}

interface PreviewData {
    success: boolean;
    decks: Deck[];
    total_decks: number;
    total_notes: number;
    user_id: number;
}

interface ImportResponse {
    quizzes: Array<{
        quiz_id: number;
        quiz_title: string;
        created_questions: number;
        skipped_questions: number;
    }>;
    total_quizzes: number;
    total_created_questions: number;
    total_skipped_questions: number;
}

declare const ldSR: {
    ajax_url: string;
    anki_nonce: string;
};

const AnkiImport: React.FC = () => {
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [previewData, setPreviewData] = useState<PreviewData | null>(null);
    const [quizTitle, setQuizTitle] = useState('');
    const [deckConfigs, setDeckConfigs] = useState<Map<string, DeckConfig>>(new Map());
    const [status, setStatus] = useState<{
        type: 'idle' | 'processing' | 'success' | 'error';
        message: string;
    }>({ type: 'idle', message: '' });
    const [expandedDecks, setExpandedDecks] = useState<Set<number>>(new Set());
    const [isImporting, setIsImporting] = useState(false);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setSelectedFile(file);
        }
    };

    const handlePreview = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!selectedFile) {
            alert('Please select a file');
            return;
        }

        setStatus({ type: 'processing', message: 'Loading preview... Please wait.' });

        const formData = new FormData();
        formData.append('action', 'ld_anki_preview');
        formData.append('anki_file', selectedFile);
        formData.append('nonce', ldSR.anki_nonce);

        try {
            const response = await fetch(ldSR.ajax_url, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setPreviewData(result.data);
                setStatus({ type: 'idle', message: '' });
                
                // Initialize default configs for each deck
                const defaultConfigs = new Map<string, DeckConfig>();
                result.data.decks.forEach((deck: Deck) => {
                    defaultConfigs.set(deck.mid, {
                        questionMapping: '0',
                        answerMapping: '1',
                        questionType: 'cloze_answer'
                    });
                });
                setDeckConfigs(defaultConfigs);
            } else {
                setStatus({
                    type: 'error',
                    message: `Error: ${result.data}`,
                });
            }
        } catch (error) {
            setStatus({
                type: 'error',
                message: `Preview Error: ${error instanceof Error ? error.message : 'Unknown error'}`,
            });
        }
    };

    const handleImport = async () => {
        if (!selectedFile) {
            alert('No file selected');
            return;
        }

        setStatus({ type: 'processing', message: 'Importing questions... Please wait.' });
        setIsImporting(true);

        const formData = new FormData();
        formData.append('action', 'ld_anki_upload');
        formData.append('anki_file', selectedFile);
        formData.append('quiz_title', quizTitle);
        formData.append('nonce', ldSR.anki_nonce);
        
        // Add deck configurations
        formData.append('deck_configs', JSON.stringify(Array.from(deckConfigs.entries())));

        try {
            const response = await fetch(ldSR.ajax_url, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                const data: ImportResponse = result.data;
                
                // Build message for multiple quizzes
                const quizzesHtml = data.quizzes.map(quiz => `
                    <div style="margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <p><strong>Quiz Title:</strong> ${quiz.quiz_title}</p>
                        <p><strong>Quiz ID:</strong> ${quiz.quiz_id}</p>
                        <p><strong>Questions Created:</strong> ${quiz.created_questions}</p>
                        <p><strong>Questions Skipped:</strong> ${quiz.skipped_questions}</p>
                        <p><a href="/wp-admin/post.php?post=${quiz.quiz_id}&action=edit" class="button">Edit Quiz</a></p>
                    </div>
                `).join('');
                
                setStatus({
                    type: 'success',
                    message: `
            <h3>✓ Import Successful!</h3>
            <p><strong>Total Quizzes:</strong> ${data.total_quizzes}</p>
            <p><strong>Total Questions Created:</strong> ${data.total_created_questions}</p>
            <p><strong>Total Questions Skipped:</strong> ${data.total_skipped_questions}</p>
            <hr style="margin: 15px 0;" />
            ${quizzesHtml}
          `,
                });

                // Reset form
                setPreviewData(null);
                setSelectedFile(null);
                setQuizTitle('');
            } else {
                setStatus({
                    type: 'error',
                    message: `Error: ${result.data}`,
                });
            }
        } catch (error) {
            setStatus({
                type: 'error',
                message: `Import Error: ${error instanceof Error ? error.message : 'Unknown error'}`,
            });
        } finally {
            setIsImporting(false);
        }
    };

    const handleCancel = () => {
        setPreviewData(null);
        setSelectedFile(null);
        setQuizTitle('');
        setStatus({ type: 'idle', message: '' });
        setExpandedDecks(new Set());
        setDeckConfigs(new Map());
    };

    const toggleDeck = (index: number) => {
        const newExpanded = new Set(expandedDecks);
        if (newExpanded.has(index)) {
            newExpanded.delete(index);
        } else {
            newExpanded.add(index);
        }
        setExpandedDecks(newExpanded);
    };
    
    const updateDeckConfig = (mid: string, field: keyof DeckConfig, value: string) => {
        const newConfigs = new Map(deckConfigs);
        const config = newConfigs.get(mid) || {
            questionMapping: '0',
            answerMapping: '1',
            questionType: 'cloze_answer'
        };
        config[field] = value;
        newConfigs.set(mid, config);
        setDeckConfigs(newConfigs);
    };

    return (
        <div className="ld-anki-import-container">
            <h2>Import Anki Deck</h2>
            <p>Upload your Anki deck file (.apkg or .zip) to preview and import questions into LearnDash.</p>

            {/* Step 1: Upload and Preview */}
            {!previewData && (
                <div id="upload-section">
                    <form onSubmit={handlePreview}>
                        <div className="form-group">
                            <label htmlFor="anki-file">Select Anki Deck File:</label>
                            <input
                                type="file"
                                id="anki-file"
                                name="anki_file"
                                accept=".apkg,.zip"
                                onChange={handleFileChange}
                                required
                            />
                        </div>

                        <button type="submit" className="button button-primary">
                            Preview Deck
                        </button>
                    </form>
                </div>
            )}

            {/* Step 2: Deck Preview */}
            {previewData && (
                <div id="preview-section">
                    <h3>Deck Preview</h3>
                    <div id="deck-list">
                        <p>
                            <strong>Total Decks:</strong> {previewData.total_decks} |{' '}
                            <strong>Total Notes:</strong> {previewData.total_notes}
                        </p>

                        {previewData.decks.map((deck, index) => (
                            <div key={deck.mid} className="deck-card">
                                <h4>📚 {deck.model_name}</h4>
                                <div className="deck-info">
                                    Model ID: {deck.mid} | Total Fields: {deck.total_fields} | Notes: {deck.notes.length}
                                </div>
                                
                                <div className="deck-config" style={{ marginTop: '15px', marginBottom: '15px' }}>
                                    <div className="form-group">
                                        <label>
                                            <strong>Field Mapping:</strong>
                                            <small style={{ display: 'block', color: '#666', marginTop: '5px' }}>
                                                Example: "0|1" joins fields 0 and 1 for question, "2|3" for answer
                                            </small>
                                        </label>
                                        <textarea
                                            placeholder="0|1&#10;2|3"
                                            rows={2}
                                            value={deckConfigs.get(deck.mid)?.questionMapping + '\n' + deckConfigs.get(deck.mid)?.answerMapping}
                                            onChange={(e) => {
                                                const lines = e.target.value.split('\n');
                                                updateDeckConfig(deck.mid, 'questionMapping', lines[0] || '0');
                                                updateDeckConfig(deck.mid, 'answerMapping', lines[1] || '1');
                                            }}
                                            style={{
                                                width: '100%',
                                                padding: '8px',
                                                border: '1px solid #ddd',
                                                borderRadius: '4px',
                                                fontFamily: 'monospace'
                                            }}
                                        />
                                    </div>
                                    
                                    <div className="form-group">
                                        <label><strong>Question Type:</strong></label>
                                        <select
                                            value={deckConfigs.get(deck.mid)?.questionType || 'cloze_answer'}
                                            onChange={(e) => updateDeckConfig(deck.mid, 'questionType', e.target.value)}
                                            style={{
                                                width: '100%',
                                                padding: '8px',
                                                border: '1px solid #ddd',
                                                borderRadius: '4px'
                                            }}
                                        >
                                            <option value="cloze_answer">Fill in the Blank</option>
                                            <option value="multiple">Multiple Choice</option>
                                            <option value="single">Single Choice</option>
                                            <option value="essay">Essay</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div
                                    className="toggle-notes"
                                    onClick={() => toggleDeck(index)}
                                    style={{ cursor: 'pointer' }}
                                >
                                    {expandedDecks.has(index) ? '▼ Hide Notes' : '▶ Show Notes'}
                                </div>

                                {expandedDecks.has(index) && (
                                    <div className="notes-list">
                                        {deck.notes.slice(0, 5).map((note) => (
                                            <div key={note.id} className="note-preview">
                                                {
                                                    note.fields.map((field, i) => (
                                                        <>
                                                            <div key={i}>
                                                                <strong>Field {i + 1}:</strong> {field}
                                                            </div>
                                                            <br />
                                                        </>

                                                    ))
                                                }
                                            </div>
                                        ))}

                                        {deck.notes.length > 5 && (
                                            <p style={{ color: '#666', fontSize: '12px', marginTop: '10px' }}>
                                                ... and {deck.notes.length - 5} more notes
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>

                    <div className="form-group" style={{ marginTop: '20px' }}>
                        <label htmlFor="quiz-title">Quiz Title (optional):</label>
                        <input
                            type="text"
                            id="quiz-title"
                            value={quizTitle}
                            onChange={(e) => setQuizTitle(e.target.value)}
                            placeholder="Leave blank for auto-generated title"
                        />
                    </div>

                    <button
                        className="button button-primary"
                        onClick={handleImport}
                        disabled={isImporting}
                    >
                        Import All Decks
                    </button>
                    <button className="button" onClick={handleCancel} style={{ marginLeft: '10px' }}>
                        Cancel
                    </button>
                </div>
            )}

            {/* Status Messages */}
            {status.message && (
                <div
                    id="ld-anki-import-status"
                    className={status.type}
                    style={{ marginTop: '20px' }}
                    dangerouslySetInnerHTML={{ __html: status.message }}
                />
            )}
        </div>
    );
};

export default AnkiImport;
