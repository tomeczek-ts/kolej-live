# Struktura projektu kolej.live / hop.kolej.live

## Konfiguracja

- `business-settings.json` - parametry biznesowe bez sekretow: limity tablicy stacji, progi opoznien, Google Analytics, domyslne stacje w wyszukiwarce, TTL cache API.
- `server/api/config.local.php` - wspolny lokalny plik sekretow dla `kolej.live` i `hop.kolej.live`. Ten plik nie powinien trafic do publicznego repozytorium.
- `server/api/config.example.php` - przyklad struktury `config.local.php` bez prawdziwych hasel.
- `server/api/AppConfig.php` - wspolny loader konfiguracji. Dla kompatybilnosci czyta tez stare `server/api/hop/Config.local.php`, ale preferowany jest jeden wspolny plik `config.local.php`.

## Teksty widoczne dla uzytkownika

- `server/api/lang/pl.php`
- `server/api/lang/en.php`
- `public/hop/api/lang/pl.php`

Frontend laduje teksty przez `src/i18n.ts` i endpoint `action=translations`.

## Warstwa API PDP PLK

- `server/api/index.php` - routing endpointow JSON i skladanie odpowiedzi dla frontendu.
- `server/api/pdp/stations.php` - stacje i wspolrzedne.
- `server/api/pdp/schedules.php` - rozklady, tablice stacji, trasy.
- `server/api/pdp/operations.php` - statusy realtime, obserwacje i statystyki.
- `server/api/pdp/disruptions.php` - utrudnienia.
- `server/api/PdpClient.php` - klient HTTP do PDP API.

## HOP

- `public/hop/index.php` - niezalezna podstrona `hop.kolej.live`.
- `server/api/hop/*` - baza danych, kolektor dzienny i logika HOP.

## Cache i optymalizacja

Cache realtime PDP zostaje w plikach w `server/api/data` i `server/api/cache`, bo:

- dane maja krotki TTL i sa czesto nadpisywane,
- zapis plikow uzywa `LOCK_EX`,
- nie dokladamy zaleznosci od MySQL do podstawowego statusu pociagu,
- HOP i tak korzysta z MySQL dla danych historycznych, gdzie relacje i zapytania analityczne maja sens.

Do MySQL warto przeniesc ostatnie wyszukiwania i katalog stacji dopiero przy wiekszym ruchu, wielu serwerach albo problemach z blokadami plikow.
