PrestaShop_aktcsv
================================================

Update price and stock quantity (or just stock or price)  in PrestaShop 1.5.6.2 and 1.6.0.6 from CSV

based on AktCSV from sokon.pl for PS 1.4 [PL]

Download working [PL] aktcsv.zip from zip subfolder
Install in PrestaShop 1.5.6.2

  Multishop feature - untested.

  Features not yet implemented:
  - resume the import if the maximum run time is exceeded
  - products with a price of zero may automatically be deactivated
  - chceck for update
  - zero prices and quantity before update

[PL]
Moduł aktualizacyjny stany magazynowe i ceny produktów (lub tylko stany) w PrestaShop 1.5.6.2 i 1.6.0.6

Plik aktcsv_ps14.php - oryginał, dla wersji PS 1.4.

Instalacja
- Zapisz działający aktcsv.zip z podkatalogu zip na dysk
- Panel administracyjny -> Dodaj moduł ->Zainstaluj


[PHP]
In php.ini SET max_input_vars >1000

More info [PL]:
http://prestadesign.pl/moduly-f13/aktualizacja-cen-i-stanow-z-csv-t1165-110.html


[Warnings]
- BACKUP YOUR DATABASE FIRST! Preferably using outside tools such as mysqldump
Then examine the backup file - does it look like it holds all your data??
- Turn on MAINTENANCE MODE. You don't want customers confusing things.
- TEST IT THOROUGHLY. If you are not convinced everything is right, restore from your backup!