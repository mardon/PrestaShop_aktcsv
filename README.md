PrestaShop_aktcsv
=================

Update price and stock quantity in PrestaShop 1.5.6 from CSV

based on AktCSV from sokon.pl for PS 1.4 [PL]

  You need add to /config/settings.inc.php:
  define('_DB_SER_', 'localhost'); //Database server address
  define('_DB_PORT_', '3306');     //Database server port

  Multishop feature - untested.

  Features not yet implemented:
  - resume the import if the maximum run time is exceeded
  - products with a price of zero may automatically be deactivated
  - chceck for update

More info [PL]:
http://prestadesign.pl/moduly-f13/aktualizacja-cen-i-stanow-z-csv-t1165-110.html