export type SearchMode = "auto" | "station" | "train";
export type BoardKind = "all" | "departure" | "arrival";
export type AppView = "status" | "arrivals" | "departures" | "route" | "disruptions" | "trains";
export type SuggestionType = "station" | "train" | "carrier" | "category" | "city";

export interface ApiErrorPayload {
  error?: {
    code: string;
    message: string;
    details?: unknown;
  };
}

export interface StationSuggestion {
  id: number;
  name: string;
}

export interface SearchSuggestion {
  type: SuggestionType;
  label: string;
  subtitle: string;
  value: string;
  stationId?: number;
  stationIds?: number[];
  scheduleId?: number;
  orderId?: number;
  operationOrderId?: number;
  operatingDate?: string;
}

export interface TrainSummary {
  scheduleId: number;
  orderId: number;
  operationOrderId: number;
  trainOrderId: number;
  operatingDate: string;
  label: string;
  name: string | null;
  number: string | null;
  category: string | null;
  carrierCode: string | null;
  origin: string | null;
  destination: string | null;
  stationCount: number;
  firstDeparture: string | null;
  lastArrival: string | null;
}

export interface StatusInfo {
  code: string | null;
  label: string;
  tone: "idle" | "live" | "done" | "cancelled" | "warning" | "unknown";
}

export interface BoardItem {
  id: string;
  kind: "arrival" | "departure";
  stationId: number;
  scheduleId: number;
  orderId: number;
  operationOrderId: number;
  operatingDate: string;
  label: string;
  name: string | null;
  number: string | null;
  category: string | null;
  carrierCode: string | null;
  origin: string | null;
  destination: string | null;
  firstDeparture: string | null;
  lastArrival: string | null;
  plannedTime: string;
  actualTime: string | null;
  delayMinutes: number | null;
  delayLabel: string;
  platform: string | null;
  track: string | null;
  stopTypeName: string | null;
  status: StatusInfo;
  isConfirmed: boolean;
  isCancelled: boolean;
}

export interface Disruption {
  id: string | number;
  type: string;
  typeCode?: string | null;
  message: string;
  startStation: string | null;
  endStation: string | null;
  affectedRoutesCount: number;
}

export interface SuggestResponse {
  query: string;
  date: string;
  suggestions: SearchSuggestion[];
  generatedAt?: string;
  demo?: boolean;
}

export interface SearchResponse {
  query: string;
  date: string;
  stations: StationSuggestion[];
  trains: TrainSummary[];
  warnings: string[];
  generatedAt?: string;
  demo?: boolean;
}

export interface StationResponse {
  station: StationSuggestion;
  date: string;
  board: BoardItem[];
  disruptions: Disruption[];
  generatedAt?: string;
  demo?: boolean;
}

export interface DisruptionsResponse {
  date: string;
  stationId: number | null;
  disruptions: Disruption[];
  generatedAt?: string;
  demo?: boolean;
}

export interface TimelineStop {
  stationId: number;
  stationName: string;
  orderNumber: number;
  plannedArrival: string | null;
  plannedDeparture: string | null;
  actualArrival: string | null;
  actualDeparture: string | null;
  arrivalDelayMinutes: number | null;
  departureDelayMinutes: number | null;
  platform: string | null;
  track: string | null;
  isConfirmed: boolean;
  isCancelled: boolean;
  stopTypeName: string | null;
}

export interface TrainResponse {
  train: TrainSummary;
  operationOrderId: number;
  date: string;
  status: StatusInfo;
  timeline: TimelineStop[];
  generatedAt?: string;
  demo?: boolean;
}

export interface StatsResponse {
  date: string;
  stats: {
    generatedAt?: string;
    totalTrains?: number;
    notStarted?: number;
    inProgress?: number;
    completed?: number;
    cancelled?: number;
    partialCancelled?: number;
  };
  generatedAt?: string;
  demo?: boolean;
}

export interface TrainListResponse {
  date: string;
  kind: "all" | "running" | "cancelled";
  trains: TrainSummary[];
  generatedAt?: string;
  demo?: boolean;
}

export interface TranslationsResponse {
  locale: string;
  texts: Record<string, string>;
  generatedAt?: string;
}
