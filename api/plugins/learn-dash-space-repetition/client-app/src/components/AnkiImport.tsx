import React, { useState } from 'react';

interface Deck {
  mid: string;
  model_name: string;
  notes: Note[];
}

interface Note {
  id: string;
  question: string;
  answer: string;
  extra: string;
  tags: string;
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
  quiz_id: number;
  quiz_title: string;
  created_questions: number;
  skipped_questions: number;
}

declare const ldSR: {
  ajax_url: string;
  anki_nonce: string;
};

const AnkiImport: React.FC = () => {
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [previewData, setPreviewData] = useState<PreviewData | null>(null);
  const [quizTitle, setQuizTitle] = useState('');
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

    try {
      const response = await fetch(ldSR.ajax_url, {
        method: 'POST',
        body: formData,
      });

      const result = await response.json();

      if (result.success) {
        const data: ImportResponse = result.data;
        setStatus({
          type: 'success',
          message: `
            <h3>✓ Import Successful!</h3>
            <p><strong>Quiz Title:</strong> ${data.quiz_title}</p>
            <p><strong>Quiz ID:</strong> ${data.quiz_id}</p>
            <p><strong>Questions Created:</strong> ${data.created_questions}</p>
            <p><strong>Questions Skipped:</strong> ${data.skipped_questions}</p>
            <p><a href="/wp-admin/post.php?post=${data.quiz_id}&action=edit" class="button">Edit Quiz</a></p>
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
                  Model ID: {deck.mid} | Notes: {deck.notes.length}
                </div>

                <span
                  className="toggle-notes"
                  onClick={() => toggleDeck(index)}
                  style={{ cursor: 'pointer' }}
                >
                  {expandedDecks.has(index) ? '▼ Hide Notes' : '▶ Show Notes'}
                </span>

                {expandedDecks.has(index) && (
                  <div className="notes-list">
                    {deck.notes.slice(0, 5).map((note) => (
                      <div key={note.id} className="note-preview">
                        <strong>Q:</strong> {note.preview.question}
                        <br />
                        <strong>A:</strong> {note.preview.answer}
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
