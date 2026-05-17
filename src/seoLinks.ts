import type { SearchMode, StationSuggestion, TrainSummary } from "./types";

export type SeoLinkType = "station" | "train";
export type TrainListKind = "all" | "running" | "cancelled";

export interface SeoLink {
  type: SeoLinkType;
  label: string;
  slug: string;
  href: string;
  subtitle?: string;
  source?: "recent" | "random";
}

export interface SeoLinksResponse {
  links: SeoLink[];
  recent: SeoLink[];
  random: SeoLink[];
  generatedAt?: string;
  demo?: boolean;
}

export type StationLinkSource = Pick<StationSuggestion, "id" | "name">;
export type TrainLinkSource = Pick<TrainSummary, "label" | "name" | "number" | "category">;

export interface UrlRouteStation {
  type: "station";
  id: number;
  slug: string;
  date?: string;
}

export interface UrlRouteTrain {
  type: "train";
  slug: string;
  number: string;
  date?: string;
}

export interface UrlRouteSearch {
  type: "search";
  query: string;
  mode: SearchMode;
  date?: string;
}

export interface UrlRouteTrainList {
  type: "trainList";
  kind: TrainListKind;
  date?: string;
}

export type UrlRoute = UrlRouteStation | UrlRouteTrain | UrlRouteSearch | UrlRouteTrainList;

const datePattern = /^\d{4}-\d{2}-\d{2}$/;

export function slugify(value: string | null | undefined) {
  const normalized = (value ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/\u0142/g, "l")
    .replace(/\u0141/g, "l")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

  return normalized || "kolej";
}

export function deslug(value: string | null | undefined) {
  return (value ?? "").replace(/-/g, " ").trim();
}

export function stationHref(station: StationLinkSource) {
  const params = new URLSearchParams({
    stacja: slugify(station.name),
    id_stacji: String(station.id),
  });

  return `/?${params.toString()}`;
}

export function trainNumber(train: TrainLinkSource) {
  const direct = train.number?.trim();
  if (direct) return direct;

  const match = train.label.match(/\b\d{2,7}\b/);
  return match?.[0] ?? "";
}

export function trainHref(train: TrainLinkSource) {
  const params = new URLSearchParams({
    pociag: slugify(train.label),
  });

  return `/?${params.toString()}`;
}

export function trainListHref(kind: TrainListKind) {
  const value: Record<TrainListKind, string> = {
    all: "pociagi",
    running: "pociagi-w-trasie",
    cancelled: "pociagi-odwolane",
  };

  return `/?lista=${value[kind]}`;
}

export function searchHref(query: string, mode: SearchMode) {
  const params = new URLSearchParams({
    szukaj: slugify(query),
  });

  if (mode !== "auto") {
    params.set("tryb", mode === "station" ? "stacja" : "pociag");
  }

  return `/?${params.toString()}`;
}

export function stationSeoLink(station: StationLinkSource, source?: SeoLink["source"]): SeoLink {
  const slug = slugify(station.name);

  return {
    type: "station",
    label: station.name,
    slug,
    href: stationHref(station),
    subtitle: slug,
    source,
  };
}

export function trainSeoLink(train: TrainLinkSource, source?: SeoLink["source"]): SeoLink {
  const slug = slugify(train.label);
  const number = trainNumber(train);

  return {
    type: "train",
    label: train.label,
    slug,
    href: trainHref(train),
    subtitle: number ? `${number} ${slug}` : slug,
    source,
  };
}

export function routeFromLocation(location: Pick<Location, "search">): UrlRoute | null {
  const params = new URLSearchParams(location.search);
  const date = cleanDate(params.get("data") ?? params.get("date"));
  const stationId = Number(params.get("id_stacji") ?? params.get("station_id") ?? "");
  const stationSlug = params.get("stacja") ?? params.get("station") ?? "";

  if (Number.isInteger(stationId) && stationId > 0) {
    return {
      type: "station",
      id: stationId,
      slug: stationSlug || `stacja-${stationId}`,
      date,
    };
  }

  const trainSlug = params.get("pociag") ?? params.get("train") ?? "";
  const number = params.get("nr") ?? params.get("number") ?? "";
  if (trainSlug || number) {
    return {
      type: "train",
      slug: trainSlug,
      number,
      date,
    };
  }

  const list = params.get("lista") ?? params.get("list") ?? "";
  const trainListKind = trainListKindFromParam(list);
  if (trainListKind) {
    return {
      type: "trainList",
      kind: trainListKind,
      date,
    };
  }

  const search = params.get("szukaj") ?? params.get("q") ?? "";
  if (search) {
    return {
      type: "search",
      query: deslug(search),
      mode: modeFromParam(params.get("tryb") ?? params.get("mode")),
      date,
    };
  }

  return null;
}

export function canonicalUrl(href: string) {
  return new URL(href, window.location.origin).href;
}

export function syncCanonicalLink(href: string) {
  const absolute = canonicalUrl(href);
  let link = document.querySelector<HTMLLinkElement>('link[rel="canonical"]');
  if (!link) {
    link = document.createElement("link");
    link.rel = "canonical";
    document.head.append(link);
  }

  link.href = absolute;
}

function cleanDate(value: string | null) {
  return value && datePattern.test(value) ? value : undefined;
}

function modeFromParam(value: string | null): SearchMode {
  if (value === "station" || value === "stacja") return "station";
  if (value === "train" || value === "pociag") return "train";

  return "auto";
}

function trainListKindFromParam(value: string | null): TrainListKind | null {
  if (value === "pociagi" || value === "all") return "all";
  if (value === "pociagi-w-trasie" || value === "running") return "running";
  if (value === "pociagi-odwolane" || value === "cancelled") return "cancelled";

  return null;
}
