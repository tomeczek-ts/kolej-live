import { copyFile, cp, mkdir, rm } from "node:fs/promises";
import { resolve } from "node:path";

const root = resolve(import.meta.dirname, "..");
const source = resolve(root, "server", "api");
const hopLangSource = resolve(root, "public", "hop", "api", "lang");
const targets = [
  resolve(root, "dist", "api"),
  resolve(root, "dist", "hop", "api"),
];

for (const target of targets) {
  await mkdir(target, { recursive: true });
  await cp(source, target, { recursive: true, force: true });
}

const hopApiTarget = resolve(root, "dist", "hop", "api");
const hopLangTarget = resolve(hopApiTarget, "lang");
await rm(hopLangTarget, { recursive: true, force: true });
await mkdir(hopLangTarget, { recursive: true });
await cp(hopLangSource, hopLangTarget, { recursive: true, force: true });

for (const target of targets) {
  await copyFile(resolve(root, "business-settings.json"), resolve(target, "business-settings.json"));
}
