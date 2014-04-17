PrestaShop_aktcsv
================================================

Update price and stock (or just stock) quantity in PrestaShop 1.5.6 and 1.6.6 from CSV

based on AktCSV from sokon.pl for PS 1.4 [PL]

Download working [PL] aktcsv.zip from zip subfolder
Install in PrestaShop 1.5.6
You need add to /config/settings.inc.php:
  define('_DB_SER_', 'localhost'); //Database server address
  define('_DB_PORT_', '3306');     //Database server port


  Multishop feature - untested.

  Features not yet implemented:
  - resume the import if the maximum run time is exceeded
  - products with a price of zero may automatically be deactivated
  - chceck for update
  - zero prices and quantity before update

[PL]
Moduł aktualizacyjny stany magazynowe i ceny produktów (lub tylko stany) w PrestaShop 1.5.6 i 1.6.6

Plik aktcsv_ps14.php - oryginał, dla wersji PS 1.4.

Instalacja
- Zapisz działający aktcsv.zip z podkatalogu zip na dysk
- Panel administracyjny -> Dodaj moduł ->Zainstaluj
- Edytuj plik /config/settings.inc.php:
  define('_DB_SER_', 'localhost'); //wpisz poprawny adres serwera
  define('_DB_PORT_', '3306');     //wpisz poprawny numer portu

[PHP]
In php.ini SET max_input_vars >1000

More info [PL]:
http://prestadesign.pl/moduly-f13/aktualizacja-cen-i-stanow-z-csv-t1165-110.html