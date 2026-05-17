import { texts as phpTexts } from "virtual:i18n-texts";

export const locales = ["pl", "en"] as const;

export type Locale = (typeof locales)[number];
export type TextDictionary = Record<string, string>;
export type TextsByLocale = Record<Locale, TextDictionary>;

export const defaultLocale: Locale = "pl";
export const localeStorageKey = "kolej.live.locale";

export const localeNames: Record<Locale, string> = {
  pl: "PL",
  en: "EN",
};

export const dateTimeLocales: Record<Locale, string> = {
  pl: "pl-PL",
  en: "en-US",
};

export const texts = phpTexts as TextsByLocale;

export type TextKey = string;
export type TranslateFn = (key: TextKey, values?: Record<string, string | number>) => string;

export function isLocale(value: string | null | undefined): value is Locale {
  return locales.includes(value as Locale);
}

export function translate(
  locale: Locale,
  key: TextKey,
  values: Record<string, string | number> = {},
  dictionaries: TextsByLocale = texts,
): string {
  const template = dictionaries[locale]?.[key] ?? dictionaries[defaultLocale]?.[key] ?? key;

  return template.replace(/\{(\w+)\}/g, (_, name: string) => String(values[name] ?? ""));
}
