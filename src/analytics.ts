export function installGoogleAnalytics(tagId: string) {
  if (!tagId || document.querySelector(`script[data-google-tag="${tagId}"]`)) {
    return;
  }

  window[`ga-disable-${tagId}`] = false;

  const script = document.createElement("script");
  script.async = true;
  script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(tagId)}`;
  script.dataset.googleTag = tagId;
  document.head.append(script);

  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function gtag(...args: unknown[]) {
    window.dataLayer.push(args);
  };

  window.gtag("js", new Date());
  window.gtag("config", tagId);
}

export function disableGoogleAnalytics(tagId: string) {
  if (!tagId) {
    return;
  }

  window[`ga-disable-${tagId}`] = true;
}

declare global {
  interface Window {
    dataLayer: unknown[][];
    gtag: (...args: unknown[]) => void;
    [key: `ga-disable-${string}`]: boolean | undefined;
  }
}
