# Feed Import

Wtyczka WordPressa importuje produkty Google Merchant Center z feeda XML do własnego typu wpisu `mg_feed_product`.

## Instalacja i import

1. Zainstaluj ZIP w panelu WordPressa przez **Wtyczki → Dodaj wtyczkę → Wyślij wtyczkę na serwer** i włącz wtyczkę.
2. Otwórz **Produkty z feeda → Import XML**. Formularz domyślnie wskazuje `https://magento.mgprojekt.com.pl/media/feed/gmc.xml`.
3. Kliknij **Importuj produkty**. Wynik znajdziesz w menu **Produkty z feeda**. Dane takie jak cena, dostępność i adres zdjęcia są widoczne w sekcji **Dane z feeda** po otwarciu wpisu.

Wymagania to WordPress 6.2+, PHP 8.0+ z rozszerzeniem DOM, MySQL lub MariaDB i dostęp HTTP(S) do feeda po stronie serwera WordPressa. Obsługiwany format to RSS z elementami `rss/channel/item`, polami Google Merchant Center w przestrzeni nazw `http://base.google.com/ns/1.0` i standardowymi polami RSS.

Import wymaga uprawnień administratora. Wtyczka zapisuje wpisy ze statusem **Prywatny** i przypisuje je do kategorii **Importowane produkty** w osobnej, niepublicznej taksonomii. Typ wpisu nie ma publicznych adresów ani endpointu REST. Nie wymaga WooCommerce.

Ponowny import z tego samego adresu aktualizuje wpisy według identyfikatora produktu. Zmiana adresu oznacza osobne źródło. Produkty usunięte z feeda pozostają w WordPressie. Import uruchamia się ręcznie i przyjmuje do 5000 pozycji oraz do 16 MiB XML na żądanie. Zdjęcia pozostają adresami URL zapisanymi w metadanych.
