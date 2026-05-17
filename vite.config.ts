import { defineConfig, normalizePath } from "vite";
import react from "@vitejs/plugin-react";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const translationModuleId = "virtual:i18n-texts";
const resolvedTranslationModuleId = "\0" + translationModuleId;
const translationDir = normalizePath(fileURLToPath(new URL("./server/api/lang/", import.meta.url)));

function phpString(value: string) {
  return value.replace(/\\\\/g, "\0").replace(/\\'/g, "'").replace(/\0/g, "\\");
}

function readPhpTranslations(locale: "pl" | "en") {
  const source = readFileSync(new URL(`./server/api/lang/${locale}.php`, import.meta.url), "utf8");
  const pairs: Record<string, string> = {};
  const pairPattern = /'((?:\\\\|\\'|[^'])*)'\s*=>\s*'((?:\\\\|\\'|[^'])*)'/g;
  let match: RegExpExecArray | null;

  while ((match = pairPattern.exec(source)) !== null) {
    pairs[phpString(match[1])] = phpString(match[2]);
  }

  return pairs;
}

function phpTranslationsPlugin() {
  return {
    name: "kolej-live-php-translations",
    resolveId(id: string) {
      return id === translationModuleId ? resolvedTranslationModuleId : null;
    },
    load(id: string) {
      if (id !== resolvedTranslationModuleId) return null;

      const texts = {
        pl: readPhpTranslations("pl"),
        en: readPhpTranslations("en"),
      };

      return `export const texts = ${JSON.stringify(texts)};`;
    },
    configureServer(server) {
      server.watcher.add(translationDir);
    },
    handleHotUpdate({ file, server }: { file: string; server: any }) {
      if (!normalizePath(file).startsWith(translationDir)) return;

      const module = server.moduleGraph.getModuleById(resolvedTranslationModuleId);
      if (module) {
        server.moduleGraph.invalidateModule(module);
      }
    },
  };
}

export default defineConfig({
  plugins: [phpTranslationsPlugin(), react()],
  build: {
    outDir: "dist",
    emptyOutDir: true,
  },
});
