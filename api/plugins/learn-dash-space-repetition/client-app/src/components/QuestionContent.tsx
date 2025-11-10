import { useEffect, useState } from "react";

export default function QuestionContent({ html }: { html: string }) {
  const [tracks, setTracks] = useState<{ src: string; title?: string }[]>([]);

  useEffect(() => {
    if (!html) return;

    // Tạo DOM ảo để parse chuỗi HTML
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, "text/html");

    // Lấy phần JSON trong <script class="wp-playlist-script">
    const script = doc.querySelector(".wp-playlist-script");
    if (script?.textContent) {
      try {
        const data = JSON.parse(script.textContent);
        if (data?.tracks?.length) {
          setTracks(data.tracks);
        }
      } catch (err) {
        console.error("Failed to parse wp-playlist JSON:", err);
      }
    }
  }, [html]);

  return (
    <div className="question">
      {/* Hiển thị nội dung text (câu hỏi) */}
      <div
        className="question-text"
        dangerouslySetInnerHTML={{
          __html: html.split("<!--[if")[0], // cắt bỏ phần playlist HTML
        }}
      />

      {/* Hiển thị audio player */}
      {tracks.length > 0 && (
        <div className="audio-player">
          {tracks.map((track, i) => (
            <div key={i} className="track-item">
              <p>{track.title || `Track ${i + 1}`}</p>
              <audio controls src={track.src} preload="none" />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
