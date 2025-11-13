import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import fs from "fs";
import path from "path";
// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    {
      name: "move-manifest",
      writeBundle() {
        const buildDir = path.resolve(__dirname, "../build");
        const viteDir = path.join(buildDir, ".vite");
        const src = path.join(viteDir, "manifest.json");
        const dest = path.join(buildDir, "manifest.json");

        try {
          // ✅ Di chuyển manifest.json ra ngoài
          if (fs.existsSync(src)) {
            fs.renameSync(src, dest);
            console.log("✅ manifest.json moved to build/manifest.json");
          }

          // ✅ Xoá thư mục .vite sau khi move
          if (fs.existsSync(viteDir)) {
            fs.rmSync(viteDir, { recursive: true, force: true });
            console.log("🧹 removed .vite folder");
          }
        } catch (err) {
          console.error("❌ Error moving manifest:", err);
        }
      },
    },
  ],
  build: {
    manifest: true, // 👈 bắt buộc để tạo manifest.json
    outDir: "../build", // 👈 thư mục output (nếu bạn muốn build ra plugin/build)
    emptyOutDir: true, // Xóa thư mục cũ trước khi build
    rollupOptions: {
      input: {
        sranki: path.resolve(__dirname, "src/sranki.tsx"),
        importAnki: path.resolve(__dirname, "src/importAnki.tsx"),
      }
    }
  },
});
