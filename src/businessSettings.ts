import settings from "../business-settings.json";
import type { SearchMode, SearchSuggestion } from "./types";

type BusinessSettings = typeof settings;

export const businessSettings = settings as BusinessSettings;

export const defaultSearchMode = (
  businessSettings.search.defaultMode === "train" ? "train" : "station"
) satisfies SearchMode;

export const defaultStationSuggestions = businessSettings.search.defaultStations as SearchSuggestion[];

export const stationBoardSettings = {
  pastItemsInitial: businessSettings.stationBoard.pastItemsInitial,
  pastItemsStep: businessSettings.stationBoard.pastItemsStep,
};

export const delayThresholds = businessSettings.delayThresholds;

export const googleTagId = businessSettings.googleTagId;
