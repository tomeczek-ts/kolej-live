import {
  Activity,
  AlertTriangle,
  ArrowDownToLine,
  ArrowUpFromLine,
  CalendarDays,
  ChevronRight,
  CircleOff,
  Clock3,
  ExternalLink,
  Languages,
  LocateFixed,
  Loader2,
  MapPin,
  Radio,
  RefreshCw,
  Route,
  Search,
  TrainFront,
  Wifi,
  X,
} from "lucide-react";
import type { FormEvent, MouseEvent, ReactNode } from "react";
import { useEffect, useMemo, useState } from "react";
import { api, AppApiError } from "./api";
import {
  dateTimeLocales,
  defaultLocale,
  isLocale,
  localeNames,
  localeStorageKey,
  locales,
  texts,
  translate,
} from "./i18n";
import type { Locale, TextsByLocale, TranslateFn } from "./i18n";
import { popularStationSuggestions } from "./popularStations";
import {
  deslug,
  isPublicStationName,
  routeFromLocation,
  searchHref,
  stationHref,
  stationSeoLink,
  syncCanonicalLink,
  trainHref,
  trainListHref,
  trainSeoLink,
  trainNumber,
} from "./seoLinks";
import type { SeoLink, SeoLinksResponse, TrainListKind, UrlRoute } from "./seoLinks";
import type {
  AppView,
  BoardItem,
  BoardKind,
  Disruption,
  DisruptionsResponse,
  SearchMode,
  SearchResponse,
  SearchSuggestion,
  StationResponse,
  StationSuggestion,
  StatsResponse,
  StatusInfo,
  TrainListResponse,
  TrainResponse,
  TrainSummary,
} from "./types";

type PendingDetail = {
  title: string;
  overlineKey: string;
  loadingKey: string;
};

const today = new Date().toISOString().slice(0, 10);
const defaultQuery = "";
const pastBoardLimit = 5;
const boardDayMs = 24 * 60 * 60 * 1000;
const initialRoute = routeFromLocation(window.location);
const initialDate = initialRoute?.date ?? today;

function getInitialLocale(): Locale {
  const urlLocale = new URLSearchParams(window.location.search).get("lang");
  if (isLocale(urlLocale)) return urlLocale;

  const stored = window.localStorage.getItem(localeStorageKey);
  if (isLocale(stored)) return stored;

  const browserLocale = window.navigator.languages.map((value) => value.slice(0, 2)).find(isLocale);
  return browserLocale ?? defaultLocale;
}

export default function App() {
  const [locale, setLocale] = useState<Locale>(getInitialLocale);
  const [textMaps, setTextMaps] = useState<TextsByLocale>(texts);
  const [view, setView] = useState<AppView>("status");
  const [query, setQuery] = useState(initialRoute ? queryFromRoute(initialRoute) : defaultQuery);
  const [mode, setMode] = useState<SearchMode>("auto");
  const [date, setDate] = useState(initialDate);
  const [search, setSearch] = useState<SearchResponse | null>(null);
  const [station, setStation] = useState<StationResponse | null>(null);
  const [train, setTrain] = useState<TrainResponse | null>(null);
  const [trainList, setTrainList] = useState<TrainListResponse | null>(null);
  const [stats, setStats] = useState<StatsResponse | null>(null);
  const [disruptions, setDisruptions] = useState<DisruptionsResponse | null>(null);
  const [seoLinks, setSeoLinks] = useState<SeoLinksResponse | null>(null);
  const [suggestions, setSuggestions] = useState<SearchSuggestion[]>([]);
  const [suggestionsOpen, setSuggestionsOpen] = useState(false);
  const [suggestionLoading, setSuggestionLoading] = useState(false);
  const [locationLoading, setLocationLoading] = useState(false);
  const [boardKind, setBoardKind] = useState<BoardKind>("departure");
  const [pendingDetail, setPendingDetail] = useState<PendingDetail | null>(null);
  const [loading, setLoading] = useState<"search" | "station" | "train" | "trainList" | "stats" | "disruptions" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const t = useMemo<TranslateFn>(() => (key, values) => translate(locale, key, values, textMaps), [locale, textMaps]);
  const dateTimeLocale = dateTimeLocales[locale];

  useEffect(() => {
    document.documentElement.lang = locale;
    document.title = pageTitle(station, train, t);
    window.localStorage.setItem(localeStorageKey, locale);
  }, [locale, station, t, train]);

  useEffect(() => {
    if (train) {
      syncCanonicalLink(trainHref(train.train));
      return;
    }

    if (station) {
      syncCanonicalLink(stationHref(station.station));
      return;
    }

    if (trainList) {
      syncCanonicalLink(trainListHref(trainList.kind));
      return;
    }

    syncCanonicalLink(search ? searchHref(search.query || query, mode) : "/");
  }, [mode, query, search, station, train, trainList]);

  useEffect(() => {
    let active = true;

    void api.translations(locale).then((result) => {
      if (!active || !isLocale(result.locale)) return;
      const responseLocale: Locale = result.locale;
      setTextMaps((current) => ({
        ...current,
        [responseLocale]: {
          ...current[responseLocale],
          ...result.texts,
        },
      }));
    }).catch(() => {
      // Keep the build-time PHP fallback if the live PHP endpoint is not reachable.
    });

    return () => {
      active = false;
    };
  }, [locale]);

  useEffect(() => {
    void refreshStats(initialDate);
    void refreshSeoLinks();

    if (initialRoute) {
      void applyUrlRoute(initialRoute, "replace");
      return;
    }
  }, []);

  useEffect(() => {
    const onPopState = () => {
      const route = routeFromLocation(window.location);
      if (route) {
        void applyUrlRoute(route, "replace");
      } else {
        setTrain(null);
        setStation(null);
        setTrainList(null);
        setSearch(null);
        setView("status");
        setMode("auto");
        setQuery(defaultQuery);
      }
    };

    window.addEventListener("popstate", onPopState);
    return () => window.removeEventListener("popstate", onPopState);
  }, []);

  useEffect(() => {
    const value = query.trim();
    if (value.length === 0) {
      setSuggestions(popularStationSuggestions);
      setSuggestionLoading(false);
      return;
    }

    if (value.length < 2) {
      setSuggestions([]);
      setSuggestionLoading(false);
      return;
    }

    let active = true;
    setSuggestionLoading(true);

    const timer = window.setTimeout(async () => {
      try {
        const result = await api.suggest(value, date);
        if (active) {
          const nextSuggestions = mode === "train"
            ? result.suggestions.filter((item) => item.type !== "train")
            : result.suggestions;
          setSuggestions(nextSuggestions);
        }
      } catch {
        if (active) {
          setSuggestions([]);
        }
      } finally {
        if (active) {
          setSuggestionLoading(false);
        }
      }
    }, 250);

    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [date, mode, query]);

  useEffect(() => {
    if (view === "disruptions" && !station) {
      void refreshDisruptions();
    }
  }, [date, station, view]);

  async function refreshStats(targetDate: string) {
    try {
      setLoading((current) => current ?? "stats");
      setStats(await api.stats(targetDate));
    } catch {
      setStats(null);
    } finally {
      setLoading((current) => (current === "stats" ? null : current));
    }
  }

  function selectView(nextView: AppView) {
    setView(nextView);

    if (nextView === "status") {
      setBoardKind("departure");
    } else if (nextView === "arrivals") {
      setBoardKind("arrival");
    } else if (nextView === "departures") {
      setBoardKind("departure");
    } else if (nextView === "route") {
      setMode("train");
    } else if (nextView === "disruptions") {
      void refreshDisruptions(station?.station.id);
    } else if (nextView === "trains") {
      setMode("train");
    }
  }

  function selectBoardKind(kind: BoardKind) {
    setBoardKind(kind);
    setView(kind === "arrival" ? "arrivals" : "departures");
  }

  function clearSearchForm() {
    setQuery(defaultQuery);
    setMode("auto");
    setSearch(null);
    setStation(null);
    setTrain(null);
    setTrainList(null);
    setError(null);
    setSuggestions(popularStationSuggestions);
    setSuggestionsOpen(false);
    setBoardKind("departure");
    setPendingDetail(null);
    setView("status");
    writeBrowserUrl("/", "push");
  }

  async function refreshSeoLinks() {
    try {
      setSeoLinks(await api.seoLinks());
    } catch {
      setSeoLinks(null);
    }
  }

  async function trackSeoLink(link: SeoLink) {
    if (link.type === "station" && !isPublicStationName(link.label)) {
      return;
    }

    try {
      await api.trackLink(link);
      await refreshSeoLinks();
    } catch {
      // Link tracking is non-critical for the search flow.
    }
  }

  async function applyUrlRoute(route: UrlRoute, historyMode: "push" | "replace" = "replace") {
    if (route.date && route.date !== date) {
      setDate(route.date);
      void refreshStats(route.date);
    }

    if (route.type === "station") {
      const stationName = deslug(route.slug) || `Stacja ${route.id}`;
      setMode("station");
      setQuery(stationName);
      await loadStation({ id: route.id, name: stationName }, true, { historyMode });
      return;
    }

    if (route.type === "train") {
      const trainQuery = route.number || deslug(route.slug);
      if (trainQuery.length < 2) return;

      setMode("train");
      setView("route");
      setQuery(trainQuery);
      await runSearch(undefined, trainQuery, "train", { historyMode, preferTrainSlug: route.slug });
      return;
    }

    if (route.type === "trainList") {
      setMode("train");
      setQuery(trainListQuery(route.kind, t));
      await loadTrainList(route.kind, true, { historyMode, date: route.date });
      return;
    }

    setMode(route.mode);
    setQuery(route.query);
    await runSearch(undefined, route.query, route.mode, { historyMode });
  }

  function navigateToSeoLink(event: MouseEvent<HTMLAnchorElement>, link: SeoLink) {
    event.preventDefault();
    const route = routeFromHref(link.href);
    if (route) {
      void applyUrlRoute(route, "push");
    }
  }

  function navigateToTrainList(event: MouseEvent<HTMLAnchorElement>, kind: TrainListKind) {
    event.preventDefault();
    void loadTrainList(kind);
  }

  async function runSearch(
    event?: FormEvent,
    overrideQuery?: string,
    overrideMode?: SearchMode,
    options: { updateUrl?: boolean; historyMode?: "push" | "replace"; preferTrainSlug?: string } = {},
  ) {
    event?.preventDefault();
    const value = (overrideQuery ?? query).trim();
    if (value.length < 2) return;

    setQuery(value);
    setSuggestionsOpen(false);
    setError(null);
    setTrainList(null);
    const searchMode = overrideMode ?? mode;
    setPendingDetail(searchPendingDetail(value, searchMode));
    setLoading("search");

    try {
      const result = await api.search(value, searchMode, date);
      setSearch(result);

      if (options.updateUrl !== false) {
        writeBrowserUrl(searchHref(value, searchMode), options.historyMode ?? "push");
      }

      const exactStation = searchMode !== "train" ? exactStationMatch(result.stations, value) : null;

      if (exactStation) {
        await loadStation(exactStation, false, { historyMode: options.historyMode });
      } else if (result.stations.length === 1 && searchMode !== "train") {
        await loadStation(result.stations[0], false, { historyMode: options.historyMode });
      } else if (result.stations.length === 0 && result.trains[0]) {
        const trainMatch = preferredTrain(result.trains, options.preferTrainSlug);
        await loadTrain(trainMatch, false, { historyMode: options.historyMode });
      }
    } catch (cause) {
      setError(errorMessage(cause, t));
    } finally {
      setPendingDetail(null);
      setLoading(null);
    }
  }

  async function findNearbyStations() {
    if (!window.navigator.geolocation) {
      setError(t("errors.geolocation_unavailable"));
      return;
    }

    const title = t("search.location");
    setError(null);
    setSearch(null);
    setStation(null);
    setTrain(null);
    setTrainList(null);
    setMode("station");
    setView("status");
    setQuery(title);
    setSuggestionsOpen(false);
    setPendingDetail({ title, overlineKey: "detail.station", loadingKey: "loading.location" });
    setLocationLoading(true);

    try {
      const position = await readCurrentPosition();
      const result = await api.nearbyStations(position.coords.latitude, position.coords.longitude);
      setSearch({
        query: title,
        date,
        stations: result.stations,
        trains: [],
        warnings: result.warnings ?? [],
        generatedAt: result.generatedAt,
        demo: result.demo,
      });
    } catch (cause) {
      setError(geolocationErrorMessage(cause, t));
    } finally {
      setPendingDetail(null);
      setLocationLoading(false);
    }
  }

  async function loadStation(item: StationSuggestion, showLoading = true, options: { historyMode?: "push" | "replace"; updateUrl?: boolean; track?: boolean } = {}) {
    setError(null);
    setPendingDetail({ title: item.name, overlineKey: "detail.station", loadingKey: "loading.board" });
    setTrain(null);
    setTrainList(null);
    if (view === "arrivals") {
      setBoardKind("arrival");
    } else {
      setView("departures");
      setBoardKind("departure");
    }
    if (showLoading) setLoading("station");

    try {
      const details = await api.station(item.id, date);
      setStation(details);
      const link = stationSeoLink(details.station);
      if (options.updateUrl !== false) {
        writeBrowserUrl(link.href, options.historyMode ?? "push");
      }
      if (options.track !== false) {
        void trackSeoLink(link);
      }
    } catch (cause) {
      setError(errorMessage(cause, t));
    } finally {
      setPendingDetail(null);
      if (showLoading) setLoading(null);
    }
  }

  async function loadTrain(
    item: Pick<TrainSummary, "scheduleId" | "orderId" | "operationOrderId" | "operatingDate"> & Partial<Pick<TrainSummary, "label">>,
    showLoading = true,
    options: { historyMode?: "push" | "replace"; updateUrl?: boolean; track?: boolean } = {},
  ) {
    setError(null);
    setPendingDetail({ title: item.label ?? `${t("detail.route")} ${item.orderId}`, overlineKey: "detail.route", loadingKey: "loading.train" });
    setView("route");
    setTrainList(null);
    if (showLoading) setLoading("train");

    try {
      const details = await api.train(item.scheduleId, item.orderId, item.operationOrderId, item.operatingDate);
      setTrain(details);
      const link = trainSeoLink(details.train);
      if (options.updateUrl !== false) {
        writeBrowserUrl(link.href, options.historyMode ?? "push");
      }
      if (options.track !== false) {
        void trackSeoLink(link);
      }
    } catch (cause) {
      setError(errorMessage(cause, t));
    } finally {
      setPendingDetail(null);
      if (showLoading) setLoading(null);
    }
  }

  async function loadTrainList(kind: TrainListKind, showLoading = true, options: { historyMode?: "push" | "replace"; updateUrl?: boolean; date?: string } = {}) {
    setError(null);
    setPendingDetail(null);
    setTrain(null);
    setStation(null);
    setView("trains");
    setMode("train");
    if (showLoading) setLoading("trainList");

    try {
      const result = await api.trainList(kind, options.date ?? date);
      setTrainList(result);
      if (options.updateUrl !== false) {
        writeBrowserUrl(trainListHref(kind), options.historyMode ?? "push");
      }
    } catch (cause) {
      setError(errorMessage(cause, t));
    } finally {
      if (showLoading) setLoading(null);
    }
  }

  async function refreshDisruptions(stationId?: number) {
    setError(null);
    setLoading((current) => current ?? "disruptions");

    try {
      setDisruptions(await api.disruptions(date, stationId));
    } catch (cause) {
      setError(errorMessage(cause, t));
    } finally {
      setLoading((current) => (current === "disruptions" ? null : current));
    }
  }

  async function chooseSuggestion(item: SearchSuggestion) {
    setSuggestionsOpen(false);
    setQuery(item.value || item.label);

    if (item.type === "station" && item.stationId) {
      setMode("station");
      await loadStation({ id: item.stationId, name: item.label });
      return;
    }

    if (item.type === "train" && item.scheduleId && item.orderId && item.operationOrderId && item.operatingDate) {
      setMode("train");
      await loadTrain({
        scheduleId: item.scheduleId,
        orderId: item.orderId,
        operationOrderId: item.operationOrderId,
        operatingDate: item.operatingDate,
        label: item.label,
      });
      return;
    }

    setMode("auto");
    await runSearch(undefined, item.value || item.label, "auto");
  }

  const demoMode = Boolean(search?.demo || station?.demo || train?.demo || trainList?.demo || stats?.demo || disruptions?.demo);
  const navItems: Array<{ id: AppView; label: string }> = [
    { id: "status", label: t("nav.status") },
    { id: "arrivals", label: t("nav.arrivals") },
    { id: "departures", label: t("nav.departures") },
    { id: "route", label: t("nav.route") },
    { id: "disruptions", label: t("nav.disruptions") },
  ];

  return (
    <main className="app-shell">
      <header className="topbar">
        <div className="brand-block">
          <a className="brand" href="/" aria-label="kolej.live">
            <img className="brand-logo" src="/kolej-live-logo.svg" alt="kolej.live" width="196" height="52" />
          </a>
        </div>
        <nav className="main-nav" aria-label={t("nav.aria")}>
          {navItems.map((item) => (
            <button
              type="button"
              className={view === item.id ? "active" : ""}
              aria-current={view === item.id ? "page" : undefined}
              onClick={() => selectView(item.id)}
              key={item.id}
            >
              {item.label}
            </button>
          ))}
          <a className="history-link" href="https://hop.kolej.live/" target="_blank" rel="noreferrer" aria-label={t("nav.delayHistoryService")}>
            <span>
              <strong>{t("nav.delayHistory")}</strong>
              <small>hop.kolej.live</small>
            </span>
            <ExternalLink size={14} />
          </a>
        </nav>
        <div className="topbar-actions">
          <LanguageControl locale={locale} setLocale={setLocale} t={t} />
          <StatsStrip stats={stats} loading={loading === "stats"} t={t} dateTimeLocale={dateTimeLocale} onTrainList={navigateToTrainList} />
        </div>
      </header>

      <section className="site-intro" aria-labelledby="page-title">
        <h1 id="page-title">{t("hero.title")}</h1>
        <p>{t("hero.description")}</p>
      </section>

      <section className="search-toolbar">
        <span className="sr-only">{t("nav.status")}</span>
        <form className="search-panel" onSubmit={runSearch}>
          <div className="search-field">
            <Search size={20} />
            <input
              value={query}
              onChange={(event) => {
                setQuery(event.target.value);
                setSuggestionsOpen(true);
              }}
              onFocus={() => setSuggestionsOpen(true)}
              onBlur={() => window.setTimeout(() => setSuggestionsOpen(false), 160)}
              placeholder={t("search.defaultPlaceholder")}
              aria-label={t("search.placeholder")}
              autoComplete="off"
            />
            {query.length > 0 && (
              <button
                className="icon-button clear-search-button"
                type="button"
                aria-label={t("search.clear")}
                title={t("search.clear")}
                onMouseDown={(event) => event.preventDefault()}
                onClick={clearSearchForm}
              >
                <X size={17} />
              </button>
            )}
            <button
              className="icon-button location-button"
              type="button"
              aria-label={t("search.location")}
              title={t("search.location")}
              disabled={locationLoading}
              onMouseDown={(event) => event.preventDefault()}
              onClick={findNearbyStations}
            >
              {locationLoading ? <Loader2 className="spin" size={18} /> : <LocateFixed size={18} />}
            </button>
            <button className="icon-button primary" type="submit" aria-label={t("search.submit")} disabled={loading === "search"}>
              {loading === "search" ? <Loader2 className="spin" size={18} /> : <ChevronRight size={19} />}
            </button>
            {suggestionsOpen && (suggestionLoading || suggestions.length > 0) && (
              <Suggestions
                items={suggestions}
                loading={suggestionLoading}
                t={t}
                onPick={chooseSuggestion}
              />
            )}
          </div>

          <div className="controls-row">
            <div className="segmented" aria-label={t("search.mode.aria")}>
              <button type="button" className={mode === "auto" ? "active" : ""} onClick={() => setMode("auto")}>
                {t("search.mode.auto")}
              </button>
              <button type="button" className={mode === "station" ? "active" : ""} onClick={() => setMode("station")}>
                {t("search.mode.station")}
              </button>
              <button type="button" className={mode === "train" ? "active" : ""} onClick={() => setMode("train")}>
                {t("search.mode.train")}
              </button>
            </div>
            <label className="date-field">
              <CalendarDays size={17} />
              <input
                type="date"
                value={date}
                onChange={(event) => {
                  setDate(event.target.value);
                  void refreshStats(event.target.value);
                }}
              />
            </label>
            <button className="ghost-button" type="button" onClick={() => runSearch(undefined)} disabled={loading === "search"}>
              <RefreshCw size={16} />
              {t("search.refresh")}
            </button>
          </div>
        </form>
      </section>

      {demoMode && (
        <div className="demo-ribbon">
          <Radio size={16} />
          {t("demo.ribbon")}
        </div>
      )}

      {error && (
        <div className="error-box" role="alert">
          <AlertTriangle size={18} />
          <span>{error}</span>
        </div>
      )}

      <section className="workspace">
        <aside className="results-panel">
          <SearchResults
            search={search}
            loading={loading === "search" || locationLoading}
            t={t}
            onStation={loadStation}
            onTrain={loadTrain}
            activeStationId={station?.station.id}
            activeTrainId={train ? `${train.train.scheduleId}-${train.train.orderId}` : null}
            dateTimeLocale={dateTimeLocale}
          />
          <SeoLinksPanel links={seoLinks?.links ?? []} t={t} onSeoLink={navigateToSeoLink} />
        </aside>

        <section className="detail-panel">
          {pendingDetail && (loading === "search" || loading === "station" || loading === "train") ? (
            <PendingDetailPanel pending={pendingDetail} t={t} />
          ) : view === "trains" ? (
            <TrainListView
              trainList={trainList}
              loading={loading === "trainList"}
              onTrain={loadTrain}
              t={t}
              dateTimeLocale={dateTimeLocale}
            />
          ) : view === "disruptions" ? (
            <DisruptionsView
              stationName={station?.station.name ?? null}
              items={station ? station.disruptions : disruptions?.disruptions ?? []}
              loading={loading === "disruptions" && !station}
              t={t}
            />
          ) : view === "route" ? (
            train ? (
              <TrainDetail train={train} onBackToStation={() => setView(station ? "status" : "route")} onStation={loadStation} t={t} dateTimeLocale={dateTimeLocale} />
            ) : (
              <RoutePrompt search={search} loading={loading === "train"} onTrain={loadTrain} t={t} />
            )
          ) : train && !station ? (
            <TrainDetail train={train} onBackToStation={() => setTrain(null)} onStation={loadStation} t={t} dateTimeLocale={dateTimeLocale} />
          ) : station ? (
            <StationBoard
              station={station}
              boardKind={view === "arrivals" ? "arrival" : view === "departures" ? "departure" : boardKind}
              setBoardKind={selectBoardKind}
              showFilters
              loading={loading === "station"}
              onTrain={loadTrain}
              t={t}
              dateTimeLocale={dateTimeLocale}
            />
          ) : (
            <EmptyState t={t} />
          )}
        </section>
      </section>
    </main>
  );
}

function LanguageControl({ locale, setLocale, t }: { locale: Locale; setLocale: (locale: Locale) => void; t: TranslateFn }) {
  return (
    <label className="locale-control">
      <Languages size={16} />
      <span className="sr-only">{t("language.label")}</span>
      <select
        aria-label={t("language.label")}
        value={locale}
        onChange={(event) => {
          if (isLocale(event.target.value)) {
            setLocale(event.target.value);
          }
        }}
      >
        {locales.map((item) => (
          <option value={item} key={item}>
            {localeNames[item]}
          </option>
        ))}
      </select>
    </label>
  );
}

function StatsStrip({
  stats,
  loading,
  t,
  dateTimeLocale,
  onTrainList,
}: {
  stats: StatsResponse | null;
  loading: boolean;
  t: TranslateFn;
  dateTimeLocale: string;
  onTrainList: (event: MouseEvent<HTMLAnchorElement>, kind: TrainListKind) => void;
}) {
  const values = stats?.stats;

  return (
    <div className="stats-strip" aria-label={t("stats.aria")}>
      <Stat
        href={trainListHref("all")}
        icon={<Activity size={16} />}
        label={t("stats.total")}
        value={values?.totalTrains}
        loading={loading}
        dateTimeLocale={dateTimeLocale}
        onClick={(event) => onTrainList(event, "all")}
      />
      <Stat
        href={trainListHref("running")}
        icon={<Wifi size={16} />}
        label={t("stats.inProgress")}
        value={values?.inProgress}
        loading={loading}
        tone="green"
        dateTimeLocale={dateTimeLocale}
        onClick={(event) => onTrainList(event, "running")}
      />
      <Stat
        href={trainListHref("cancelled")}
        icon={<CircleOff size={16} />}
        label={t("stats.cancelled")}
        value={(values?.cancelled ?? 0) + (values?.partialCancelled ?? 0)}
        loading={loading}
        tone="red"
        dateTimeLocale={dateTimeLocale}
        onClick={(event) => onTrainList(event, "cancelled")}
      />
    </div>
  );
}

function Stat({
  href,
  icon,
  label,
  value,
  loading,
  tone,
  dateTimeLocale,
  onClick,
}: {
  href: string;
  icon: ReactNode;
  label: string;
  value?: number;
  loading: boolean;
  tone?: "green" | "red";
  dateTimeLocale: string;
  onClick: (event: MouseEvent<HTMLAnchorElement>) => void;
}) {
  return (
    <a className={`stat ${tone ?? ""}`} href={href} onClick={onClick}>
      {icon}
      <span>{label}</span>
      <strong>{loading ? "..." : value?.toLocaleString(dateTimeLocale) ?? "-"}</strong>
    </a>
  );
}

function SearchResults({
  search,
  loading,
  t,
  onStation,
  onTrain,
  activeStationId,
  activeTrainId,
  dateTimeLocale,
}: {
  search: SearchResponse | null;
  loading: boolean;
  t: TranslateFn;
  onStation: (station: StationSuggestion) => void;
  onTrain: (train: TrainSummary) => void;
  activeStationId?: number;
  activeTrainId: string | null;
  dateTimeLocale: string;
}) {
  if (loading && !search) {
    return <PanelLoader label={t("loading.results")} />;
  }

  return (
    <>
      <div className="panel-title">
        <div>
          <h2>{t("results.title")}</h2>
          <span>{search ? t("results.count", { count: search.stations.length + search.trains.length }) : t("results.ready")}</span>
        </div>
      </div>

      {search?.warnings?.map((warning) => (
        <div className="warning-line" key={warning}>
          <AlertTriangle size={15} />
          {warning}
        </div>
      ))}

      <div className="result-group">
        <h3>{t("results.stations")}</h3>
        {search?.stations.length ? (
          search.stations.map((station) => {
            const link = stationSeoLink(station);

            return (
              <a
                className={`result-row ${activeStationId === station.id ? "selected" : ""}`}
                key={station.id}
                href={link.href}
                onClick={(event) => {
                  event.preventDefault();
                  onStation(station);
                }}
              >
                <MapPin size={18} />
                <span>
                  <strong>{station.name}</strong>
                  <small>
                    {station.distanceKm !== undefined
                      ? `${t("results.distanceKm", { distance: formatDistanceKm(station.distanceKm, dateTimeLocale) })} · ${link.slug}`
                      : link.slug}
                  </small>
                </span>
                <ChevronRight size={17} />
              </a>
            );
          })
        ) : (
          <div className="muted-row">{t("results.noStations")}</div>
        )}
      </div>

      <div className="result-group">
        <h3>{t("results.trains")}</h3>
        {search?.trains.length ? (
          search.trains.map((train) => {
            const link = trainSeoLink(train);

            return (
              <a
                className={`result-row train ${activeTrainId === `${train.scheduleId}-${train.orderId}` ? "selected" : ""}`}
                key={`${train.scheduleId}-${train.orderId}-${train.operationOrderId}`}
                href={link.href}
                onClick={(event) => {
                  event.preventDefault();
                  onTrain(train);
                }}
              >
                <TrainFront size={18} />
                <span>
                  <strong>{train.label}</strong>
                  <small className="train-result-route">
                    <span>
                      <b>{t("results.departureShort")}</b>
                      <span>{train.origin ?? "-"}</span>
                      <time>{formatClock(train.firstDeparture, dateTimeLocale)}</time>
                    </span>
                    <span>
                      <b>{t("results.arrivalShort")}</b>
                      <span>{train.destination ?? "-"}</span>
                      <time>{formatClock(train.lastArrival, dateTimeLocale)}</time>
                    </span>
                  </small>
                </span>
                <ChevronRight size={17} />
              </a>
            );
          })
        ) : (
          <div className="muted-row">{t("results.noTrains")}</div>
        )}
      </div>
    </>
  );
}

function SeoLinksPanel({ links, t, onSeoLink }: { links: SeoLink[]; t: TranslateFn; onSeoLink: (event: MouseEvent<HTMLAnchorElement>, link: SeoLink) => void }) {
  return (
    <section className="seo-links">
      <div className="section-title compact">
        <Search size={16} />
        <h3>{t("seoLinks.title")}</h3>
      </div>
      {links.length ? (
        <div className="seo-link-list">
          {links.slice(0, 10).map((link) => (
            <a className="seo-link-row" href={link.href} onClick={(event) => onSeoLink(event, link)} key={`${link.source}-${link.type}-${link.href}`}>
              {link.type === "train" ? <TrainFront size={16} /> : <MapPin size={16} />}
              <span>
                <strong>{link.label}</strong>
                <small>{link.subtitle ?? link.slug}</small>
              </span>
            </a>
          ))}
        </div>
      ) : (
        <div className="muted-row">{t("seoLinks.empty")}</div>
      )}
    </section>
  );
}

function Suggestions({
  items,
  loading,
  t,
  onPick,
}: {
  items: SearchSuggestion[];
  loading: boolean;
  t: TranslateFn;
  onPick: (item: SearchSuggestion) => void;
}) {
  return (
    <div className="suggestions-popover" role="listbox" aria-label={t("suggestions.aria")}>
      {loading && items.length === 0 ? (
        <div className="suggestion-empty">
          <Loader2 className="spin" size={16} />
          {t("suggestions.loading")}
        </div>
      ) : (
        items.map((item, index) => (
          <button
            type="button"
            className="suggestion-row"
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => onPick(item)}
            key={`${item.type}-${item.label}-${index}`}
          >
            {item.type === "train" ? <TrainFront size={17} /> : <MapPin size={17} />}
            <span>
              <strong>{item.label}</strong>
              <small>{suggestionSubtitle(item, t)}</small>
            </span>
          </button>
        ))
      )}
    </div>
  );
}

function StationBoard({
  station,
  boardKind,
  setBoardKind,
  showFilters = true,
  loading,
  onTrain,
  t,
  dateTimeLocale,
}: {
  station: StationResponse;
  boardKind: BoardKind;
  setBoardKind: (kind: BoardKind) => void;
  showFilters?: boolean;
  loading: boolean;
  onTrain: (train: Pick<TrainSummary, "scheduleId" | "orderId" | "operationOrderId" | "operatingDate">) => void;
  t: TranslateFn;
  dateTimeLocale: string;
}) {
  const [currentTime, setCurrentTime] = useState(() => Date.now());

  useEffect(() => {
    setCurrentTime(Date.now());
    const interval = window.setInterval(() => setCurrentTime(Date.now()), 60 * 1000);

    return () => window.clearInterval(interval);
  }, [station.date, station.station.id]);

  const visibleBoard = useMemo(() => {
    const dayStart = timestampFromDateOnly(station.date);
    const dayEnd = dayStart + boardDayMs;
    const splitTime = Math.min(Math.max(currentTime, dayStart), dayEnd);
    const timedItems = station.board
      .filter((item) => item.kind === boardKind)
      .map((item) => ({ item, boardTimestamp: boardItemTimestamp(item) }))
      .filter((entry): entry is { item: BoardItem; boardTimestamp: number } => {
        return entry.boardTimestamp !== null && entry.boardTimestamp >= dayStart && entry.boardTimestamp < dayEnd;
      });

    const past = timedItems
      .filter((entry) => entry.boardTimestamp < splitTime)
      .sort((a, b) => b.boardTimestamp - a.boardTimestamp)
      .slice(0, pastBoardLimit)
      .sort((a, b) => a.boardTimestamp - b.boardTimestamp);

    const future = timedItems
      .filter((entry) => entry.boardTimestamp >= splitTime)
      .sort((a, b) => a.boardTimestamp - b.boardTimestamp);

    return [...past, ...future].map((entry) => entry.item);
  }, [boardKind, currentTime, station.board, station.date]);

  return (
    <>
      <div className="detail-header">
        <div>
          <span className="overline">{t("detail.station")}</span>
          <h2>{station.station.name}</h2>
        </div>
        {showFilters && (
          <div className="tabbar" aria-label={t("board.aria")}>
            <button className={boardKind === "departure" ? "active" : ""} onClick={() => setBoardKind("departure")} type="button">
              {t("board.departures")}
            </button>
            <button className={boardKind === "arrival" ? "active" : ""} onClick={() => setBoardKind("arrival")} type="button">
              {t("board.arrivals")}
            </button>
          </div>
        )}
      </div>

      {loading ? (
        <PanelLoader label={t("loading.board")} />
      ) : (
        <div className="board-table">
          <div className="board-head">
            <span>{t("board.time")}</span>
            <span>{t("board.train")}</span>
            <span>{t("board.direction")}</span>
            <span>{t("board.platform")}</span>
            <span>{t("board.status")}</span>
          </div>
          {visibleBoard.length ? (
            visibleBoard.map((item) => <BoardRow item={item} key={item.id} onTrain={onTrain} t={t} dateTimeLocale={dateTimeLocale} />)
          ) : (
            <div className="empty-line">{t("board.empty")}</div>
          )}
        </div>
      )}

      <Disruptions items={station.disruptions} t={t} />
    </>
  );
}

function BoardRow({
  item,
  onTrain,
  t,
  dateTimeLocale,
}: {
  item: BoardItem;
  onTrain: (train: Pick<TrainSummary, "scheduleId" | "orderId" | "operationOrderId" | "operatingDate">) => void;
  t: TranslateFn;
  dateTimeLocale: string;
}) {
  const DirectionIcon = item.kind === "departure" ? ArrowUpFromLine : ArrowDownToLine;
  const href = trainHref(item);

  return (
    <a
      className="board-row"
      href={href}
      onClick={(event) => {
        event.preventDefault();
        onTrain(item);
      }}
    >
      <span className="time-cell">
        <strong>{formatClock(item.plannedTime, dateTimeLocale)}</strong>
        <DelayBadge delay={item.delayMinutes} t={t} />
      </span>
      <span className="train-cell">
        <DirectionIcon size={16} />
        <span>
          <strong>{item.label}</strong>
          <small>{item.carrierCode ?? "PLK"}</small>
        </span>
      </span>
      <span className="direction-cell board-route-summary">
        <span>
          <b>{t("board.from")}</b>
          <span>{item.origin ?? "-"}</span>
          <time>{formatClock(item.firstDeparture, dateTimeLocale)}</time>
        </span>
        <span>
          <b>{t("board.to")}</b>
          <span>{item.destination ?? "-"}</span>
          <time>{formatClock(item.lastArrival, dateTimeLocale)}</time>
        </span>
      </span>
      <span className="platform-cell">
        <strong>{item.platform ?? "-"}</strong>
        <small>
          {t("rail.trackShort")} {item.track ?? "-"}
        </small>
      </span>
      <span className="status-cell">
        <StatusBadge status={item.isCancelled ? { code: "X", label: "", tone: "cancelled" } : item.status} t={t} />
      </span>
    </a>
  );
}

function RoutePrompt({
  search,
  loading,
  onTrain,
  t,
}: {
  search: SearchResponse | null;
  loading: boolean;
  onTrain: (train: TrainSummary) => void;
  t: TranslateFn;
}) {
  const trains = search?.trains ?? [];

  return (
    <div className="route-prompt">
      <div className="detail-header">
        <div>
          <span className="overline">{t("detail.route")}</span>
          <h2>{t("routePrompt.title")}</h2>
        </div>
      </div>
      {loading ? (
        <PanelLoader label={t("loading.train")} />
      ) : trains.length ? (
        <div className="route-list">
          {trains.map((train) => (
            <a
              className="route-row"
              href={trainHref(train)}
              onClick={(event) => {
                event.preventDefault();
                onTrain(train);
              }}
              key={`${train.scheduleId}-${train.orderId}-${train.operationOrderId}`}
            >
              <TrainFront size={18} />
              <span>
                <strong>{train.label}</strong>
                <small>
                  {train.origin ?? "-"} {"->"} {train.destination ?? "-"}
                </small>
              </span>
              <ChevronRight size={17} />
            </a>
          ))}
        </div>
      ) : (
        <div className="empty-state compact">
          <Route size={32} />
          <h2>{t("routePrompt.emptyTitle")}</h2>
          <p>{t("routePrompt.emptyBody")}</p>
        </div>
      )}
    </div>
  );
}

function TrainListView({
  trainList,
  loading,
  onTrain,
  t,
  dateTimeLocale,
}: {
  trainList: TrainListResponse | null;
  loading: boolean;
  onTrain: (train: TrainSummary) => void;
  t: TranslateFn;
  dateTimeLocale: string;
}) {
  const trains = trainList?.trains ?? [];
  const kind = trainList?.kind ?? "all";

  return (
    <>
      <div className="detail-header">
        <div>
          <span className="overline">{t("detail.trainList")}</span>
          <h2>{t(`trainList.${kind}`)}</h2>
        </div>
        <span className="list-count">{t("trainList.count", { count: trains.length })}</span>
      </div>
      {loading ? (
        <PanelLoader label={t("loading.trainList")} />
      ) : trains.length ? (
        <div className="train-list">
          {trains.map((train) => (
            <a
              className="train-list-row"
              href={trainHref(train)}
              onClick={(event) => {
                event.preventDefault();
                onTrain(train);
              }}
              key={`${train.scheduleId}-${train.orderId}-${train.operationOrderId}`}
            >
              <TrainFront size={18} />
              <span>
                <strong>{train.label}</strong>
                <small>
                  {train.origin ?? "-"} {"->"} {train.destination ?? "-"}
                </small>
              </span>
              <span className="train-list-time">
                {formatClock(train.firstDeparture, dateTimeLocale)}
              </span>
              <ChevronRight size={17} />
            </a>
          ))}
        </div>
      ) : (
        <div className="empty-line">{t("trainList.empty")}</div>
      )}
    </>
  );
}

function TrainDetail({
  train,
  onBackToStation,
  onStation,
  t,
  dateTimeLocale,
}: {
  train: TrainResponse;
  onBackToStation: () => void;
  onStation: (station: StationSuggestion) => void;
  t: TranslateFn;
  dateTimeLocale: string;
}) {
  const maxDelay = train.timeline.reduce((value, stop) => Math.max(value, stop.arrivalDelayMinutes ?? 0, stop.departureDelayMinutes ?? 0), 0);

  return (
    <>
      <div className="detail-header">
        <div>
          <span className="overline">{t("detail.route")}</span>
          <h2>{train.train.label}</h2>
        </div>
        <div className="train-actions">
          <StatusBadge status={train.status} t={t} />
          <button className="ghost-button compact" type="button" onClick={onBackToStation}>
            <MapPin size={16} />
            {t("detail.station")}
          </button>
        </div>
      </div>

      <div className="train-summary">
        <SummaryMetric label={t("train.relation")} value={`${train.train.origin ?? "-"} -> ${train.train.destination ?? "-"}`} />
        <SummaryMetric label={t("train.delay")} value={formatDelay(maxDelay, t)} tone={maxDelay > 0 ? "warn" : "ok"} />
        <SummaryMetric label={t("train.points")} value={`${train.timeline.length}`} />
      </div>

      <div className="timeline">
        {train.timeline.map((stop) => {
          const delay = stop.departureDelayMinutes ?? stop.arrivalDelayMinutes;
          const planned = stop.plannedDeparture ?? stop.plannedArrival;
          const actual = stop.actualDeparture ?? stop.actualArrival;
          const stationLink = stationSeoLink({ id: stop.stationId, name: stop.stationName });

          return (
            <div className={`timeline-row ${stop.isCancelled ? "cancelled" : ""}`} key={`${stop.stationId}-${stop.orderNumber}`}>
              <div className="timeline-rail">
                <span />
              </div>
              <div className="timeline-main">
                <div>
                  <a
                    className="timeline-station-link"
                    href={stationLink.href}
                    onClick={(event) => {
                      event.preventDefault();
                      onStation({ id: stop.stationId, name: stop.stationName });
                    }}
                  >
                    {stop.stationName}
                  </a>
                  <small>{stop.stopTypeName ?? t("train.stopFallback")}</small>
                </div>
                <div className="timeline-meta">
                  <span>
                    <Clock3 size={15} />
                    {formatClock(planned, dateTimeLocale)}
                  </span>
                  <DelayBadge delay={delay} t={t} shortUnknown />
                  <span>
                    {t("rail.platformShort")} {stop.platform ?? "-"}
                  </span>
                  <span>
                    {t("rail.trackShort")} {stop.track ?? "-"}
                  </span>
                  {actual && (
                    <span>
                      {t("train.actualShort")} {formatClock(actual, dateTimeLocale)}
                    </span>
                  )}
                </div>
              </div>
            </div>
          );
        })}
      </div>
    </>
  );
}

function DisruptionsView({
  stationName,
  items,
  loading,
  t,
}: {
  stationName: string | null;
  items: Disruption[];
  loading: boolean;
  t: TranslateFn;
}) {
  if (loading) {
    return <PanelLoader label={t("loading.disruptions")} />;
  }

  return (
    <>
      <div className="detail-header">
        <div>
          <span className="overline">{stationName ?? t("disruptions.global")}</span>
          <h2>{t("disruptions.title")}</h2>
        </div>
      </div>
      <Disruptions items={items} t={t} limit={20} />
    </>
  );
}

function Disruptions({ items, t, limit = 5 }: { items: Disruption[]; t: TranslateFn; limit?: number }) {
  const visibleItems = items.filter(isVisibleDisruption);

  return (
    <section className="disruptions">
      <div className="section-title">
        <AlertTriangle size={17} />
        <h3>{t("disruptions.title")}</h3>
      </div>
      {visibleItems.length ? (
        visibleItems.slice(0, limit).map((item) => {
          const type = visibleDisruptionType(item.type);

          return (
            <article className="disruption-row" key={item.id}>
              {type && <span className="disruption-type">{type}</span>}
              <p>{item.message}</p>
              {(item.startStation || item.endStation) && (
                <small>
                  {t("disruptions.sectionLabel")} {[item.startStation, item.endStation].filter(Boolean).join(" -> ")}
                </small>
              )}
            </article>
          );
        })
      ) : (
        <div className="empty-line">{t("disruptions.empty")}</div>
      )}
    </section>
  );
}

function isVisibleDisruption(item: Disruption): boolean {
  const message = item.message.trim();

  return message.length > 10 && !isTechnicalDisruptionText(message) && !hasTemplatePlaceholder(message);
}

function visibleDisruptionType(type: string | null | undefined): string | null {
  if (!type || type === "Utrudnienie" || isTechnicalDisruptionText(type) || hasTemplatePlaceholder(type)) {
    return null;
  }

  return type;
}

function isTechnicalDisruptionText(value: string): boolean {
  return /^utr[_ -]?\d+$/i.test(value.trim());
}

function hasTemplatePlaceholder(value: string): boolean {
  return /\{[^{}]+\}/.test(value);
}

function suggestionSubtitle(item: SearchSuggestion, t: TranslateFn) {
  if (item.type === "station") return t("suggestions.station");
  if (item.type === "train") return item.subtitle || t("suggestions.train");
  if (item.type === "carrier") return item.subtitle || t("suggestions.carrier");
  if (item.type === "category") return item.subtitle || t("suggestions.category");
  if (item.type === "city") return item.subtitle || t("suggestions.city");

  return item.subtitle;
}

function routeFromHref(href: string) {
  return routeFromLocation(new URL(href, window.location.origin));
}

function writeBrowserUrl(href: string, mode: "push" | "replace") {
  const url = new URL(href, window.location.origin);
  const nextPath = `${url.pathname}${url.search}`;

  if (nextPath === `${window.location.pathname}${window.location.search}`) {
    return;
  }

  if (mode === "replace") {
    window.history.replaceState({}, "", nextPath);
  } else {
    window.history.pushState({}, "", nextPath);
  }
}

function queryFromRoute(route: UrlRoute) {
  if (route.type === "station") return deslug(route.slug) || `Stacja ${route.id}`;
  if (route.type === "train") return route.number || deslug(route.slug);
  if (route.type === "trainList") return route.kind;

  return route.query;
}

function trainListQuery(kind: TrainListKind, t: TranslateFn) {
  return t(`trainList.${kind}`);
}

function preferredTrain(trains: TrainSummary[], preferredSlug?: string) {
  if (!preferredSlug) return trains[0];
  const readable = deslug(preferredSlug).toLowerCase();

  return trains.find((train) => train.label.toLowerCase().includes(readable) || trainNumber(train) === readable) ?? trains[0];
}

function searchPendingDetail(title: string, mode: SearchMode): PendingDetail {
  if (mode === "station") {
    return { title, overlineKey: "detail.station", loadingKey: "loading.results" };
  }

  if (mode === "train") {
    return { title, overlineKey: "detail.route", loadingKey: "loading.results" };
  }

  return { title, overlineKey: "detail.search", loadingKey: "loading.results" };
}

function exactStationMatch(stations: StationSuggestion[], query: string) {
  const normalizedQuery = normalizeStationName(query);

  return stations.find((station) => normalizeStationName(station.name) === normalizedQuery) ?? null;
}

function readCurrentPosition(): Promise<GeolocationPosition> {
  return new Promise((resolve, reject) => {
    window.navigator.geolocation.getCurrentPosition(resolve, reject, {
      enableHighAccuracy: false,
      timeout: 10000,
      maximumAge: 5 * 60 * 1000,
    });
  });
}

function geolocationErrorMessage(cause: unknown, t: TranslateFn) {
  const code = typeof cause === "object" && cause !== null && "code" in cause ? Number(cause.code) : 0;

  if (code === 1) return t("errors.geolocation_denied");
  if (code === 2) return t("errors.geolocation_unavailable");
  if (code === 3) return t("errors.geolocation_timeout");
  if (cause instanceof AppApiError) return errorMessage(cause, t);

  return t("errors.geolocation_failed");
}

function normalizeStationName(value: string) {
  return value
    .replace(/[łŁ]/g, (match) => (match === "Ł" ? "L" : "l"))
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/\s+/g, " ")
    .trim()
    .toLowerCase();
}

function pageTitle(station: StationResponse | null, train: TrainResponse | null, t: TranslateFn) {
  if (train) return `${train.train.label} - kolej.live`;
  if (station) return `${station.station.name} - kolej.live`;

  return t("meta.title");
}

function SummaryMetric({ label, value, tone }: { label: string; value: string; tone?: "ok" | "warn" }) {
  return (
    <div className={`summary-metric ${tone ?? ""}`}>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function StatusBadge({ status, t }: { status: StatusInfo; t: TranslateFn }) {
  return <span className={`status-badge ${status.tone}`}>{statusText(status, t)}</span>;
}

function DelayBadge({ delay, t, shortUnknown = false }: { delay: number | null; t: TranslateFn; shortUnknown?: boolean }) {
  if (delay === null) return null;

  const tone = delay === null ? "unknown" : delay > 0 ? "late" : "ok";
  const label = delay === null && shortUnknown ? t("delay.noneShort") : formatDelay(delay, t);

  return <span className={`delay-badge ${tone}`}>{label}</span>;
}

function PanelLoader({ label }: { label: string }) {
  return (
    <div className="panel-loader">
      <Loader2 className="spin" size={22} />
      <span>{label}</span>
    </div>
  );
}

function EmptyState({ t }: { t: TranslateFn }) {
  return (
    <div className="empty-state">
      <Route size={36} />
      <h2>{t("empty.title")}</h2>
      <p>{t("empty.body")}</p>
    </div>
  );
}

function PendingDetailPanel({ pending, t }: { pending: PendingDetail; t: TranslateFn }) {
  return (
    <>
      <div className="detail-header">
        <div>
          <span className="overline">{t(pending.overlineKey)}</span>
          <h2>{pending.title}</h2>
        </div>
      </div>
      <PanelLoader label={t(pending.loadingKey)} />
    </>
  );
}

function formatClock(value: string | null, dateTimeLocale: string) {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "-";

  return new Intl.DateTimeFormat(dateTimeLocale, { hour: "2-digit", minute: "2-digit" }).format(date);
}

function timestampFromIso(value: string | null) {
  if (!value) return null;
  const timestamp = new Date(value).getTime();

  return Number.isNaN(timestamp) ? null : timestamp;
}

function boardItemTimestamp(item: BoardItem) {
  return timestampFromIso(item.actualTime) ?? timestampFromIso(item.plannedTime);
}

function timestampFromDateOnly(value: string) {
  const timestamp = new Date(`${value}T00:00:00`).getTime();

  return Number.isNaN(timestamp) ? 0 : timestamp;
}

function formatDelay(delay: number | null, t: TranslateFn) {
  if (delay === null) return "";
  if (delay <= 0) return t("delay.onTime");

  return t("delay.minutes", { minutes: delay });
}

function formatDistanceKm(distance: number, locale: string) {
  return new Intl.NumberFormat(locale, {
    maximumFractionDigits: distance < 10 ? 1 : 0,
    minimumFractionDigits: 0,
  }).format(distance);
}

function statusText(status: StatusInfo, t: TranslateFn) {
  switch (status.code) {
    case "S":
      return t("status.S");
    case "P":
      return t("status.P");
    case "C":
      return t("status.C");
    case "X":
      return t("status.X");
    case "Q":
      return t("status.Q");
    default:
      return t("status.unknown");
  }
}

function errorMessage(cause: unknown, t: TranslateFn) {
  if (cause instanceof AppApiError) {
    switch (cause.code) {
      case "api_error":
        return t("errors.api_error");
      case "network_error":
        return t("errors.network_error");
      case "method_not_allowed":
        return t("errors.method_not_allowed");
      case "missing_api_key":
        return t("errors.missing_api_key");
      case "unknown_action":
        return t("errors.unknown_action");
      case "station_required":
        return t("errors.station_required");
      case "train_required":
        return t("errors.train_required");
      case "pdp_api_error":
        return t("errors.pdp_api_error");
      case "server_error":
        return t("errors.server_error");
      default:
        return cause.message || t("errors.generic");
    }
  }

  if (cause instanceof Error) {
    return cause.message || t("errors.generic");
  }

  return t("errors.generic");
}
