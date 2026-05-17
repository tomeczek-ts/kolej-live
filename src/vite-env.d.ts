/// <reference types="vite/client" />

declare module "virtual:i18n-texts" {
  export const texts: Record<"pl" | "en", Record<string, string>>;
}
