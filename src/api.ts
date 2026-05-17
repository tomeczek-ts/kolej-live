import { demoSearch, demoStation, demoStats, demoTrain } from "./demoData";
import { texts as fallbackTexts } from "./i18n";
import type { Locale } from "./i18n";
import { stationSeoLink, trainSeoLink } from "./seoLinks";
import type { SeoLink, SeoLinksResponse, TrainListKind } from "./seoLinks";
import type {
  DisruptionsResponse,
  SearchMode,
  SearchResponse,
  StationResponse,
  StatsResponse,
  SuggestResponse,
  TrainResponse,
  TrainListResponse,
  TranslationsResponse,
} from "./types";

const API_URL = import.meta.env.VITE_API_URL ?? "/api/index.php";

class AppApiError extends Error {
  code: string;
  details: unknown;

  constructor(message: string, code = "api_error", details: unknown = null) {
    super(message);
    this.name = "AppApiError";
    this.code = code;
    this.details = details;
  }
}

const isDev = import.meta.env.DEV;
const demoSeoLinks: SeoLinksResponse = {
  links: [
    stationSeoLink(demoStation.station, "recent"),
    trainSeoLink(demoTrain.train, "recent"),
    stationSeoLink({ id: 5100071, name: "Warszawa Zachodnia" }, "random"),
    stationSeoLink({ id: 5100067, name: "Warszawa Wschodnia" }, "random"),
    trainSeoLink({ label: "EIC 3502 Tatry", name: "Tatry", number: "3502", category: "EIC" }, "random"),
    trainSeoLink({ label: "R 19316", name: null, number: "19316", category: "R" }, "random"),
  ],
  recent: [stationSeoLink(demoStation.station, "recent"), trainSeoLink(demoTrain.train, "recent")],
  random: [
    stationSeoLink({ id: 5100071, name: "Warszawa Zachodnia" }, "random"),
    stationSeoLink({ id: 5100067, name: "Warszawa Wschodnia" }, "random"),
    trainSeoLink({ label: "EIC 3502 Tatry", name: "Tatry", number: "3502", category: "EIC" }, "random"),
    trainSeoLink({ label: "R 19316", name: null, number: "19316", category: "R" }, "random"),
  ],
  demo: true,
};

async function request<T>(params: Record<string, string | number | undefined>, demoValue: T): Promise<T> {
  const url = new URL(API_URL, window.location.origin);
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== "") {
      url.searchParams.set(key, String(value));
    }
  });

  try {
    const response = await fetch(url, { headers: { Accept: "application/json" } });
    const text = await response.text();
    const json = text ? JSON.parse(text) : {};

    if (!response.ok) {
      throw new AppApiError(json?.error?.message ?? "", json?.error?.code ?? "api_error", json?.error?.details);
    }

    return json as T;
  } catch (error) {
    if (isDev) {
      return demoValue;
    }

    if (error instanceof AppApiError) {
      throw error;
    }

    throw new AppApiError(error instanceof Error ? error.message : "", "network_error");
  }
}

export const api = {
  search(q: string, mode: SearchMode, date: string) {
    return request<SearchResponse>({ action: "search", q, mode, date }, { ...demoSearch, query: q, date });
  },
  suggest(q: string, date: string) {
    return request<SuggestResponse>(
      { action: "suggest", q, date },
      {
        query: q,
        date,
        suggestions: [
          { type: "station", label: "Warszawa Centralna", subtitle: "Stacja", value: "Warszawa Centralna", stationId: 5100069 },
          {
            type: "train",
            label: demoTrain.train.label,
            subtitle: `${demoTrain.train.origin ?? "-"} -> ${demoTrain.train.destination ?? "-"}`,
            value: demoTrain.train.label,
            scheduleId: demoTrain.train.scheduleId,
            orderId: demoTrain.train.orderId,
            operationOrderId: demoTrain.operationOrderId,
            operatingDate: demoTrain.date,
          },
        ],
        demo: true,
      },
    );
  },
  station(id: number, date: string) {
    return request<StationResponse>({ action: "station", id, date }, { ...demoStation, date });
  },
  train(scheduleId: number, orderId: number, operationOrderId: number, operatingDate: string) {
    return request<TrainResponse>(
      { action: "train", scheduleId, orderId, operationOrderId, operatingDate },
      { ...demoTrain, date: operatingDate },
    );
  },
  trainList(kind: TrainListKind, date: string) {
    return request<TrainListResponse>(
      { action: "trains", kind, date },
      { date, kind, trains: demoSearch.trains, demo: true },
    );
  },
  stats(date: string) {
    return request<StatsResponse>({ action: "stats", date }, { ...demoStats, date });
  },
  translations(locale: Locale) {
    return request<TranslationsResponse>(
      { action: "translations", lang: locale },
      { locale, texts: fallbackTexts[locale] ?? fallbackTexts.pl },
    );
  },
  seoLinks() {
    return request<SeoLinksResponse>({ action: "seo_links" }, demoSeoLinks);
  },
  trackLink(link: SeoLink) {
    return request<{ ok: boolean }>(
      {
        action: "track_link",
        type: link.type,
        label: link.label,
        slug: link.slug,
        href: link.href,
        subtitle: link.subtitle,
      },
      { ok: true },
    );
  },
  disruptions(date: string, stationId?: number) {
    const demoDisruptions = stationId ? demoStation.disruptions : demoStation.disruptions.slice(0, 2);
    return request<DisruptionsResponse>(
      { action: "disruptions", date, stationId },
      { date, stationId: stationId ?? null, disruptions: demoDisruptions, demo: true },
    );
  },
};

export { AppApiError };
