PrestaShop_aktcsv
================================================

Update price and stock quantity (or just stock or price) in PrestaShop 1.4, 1.5.6.2 and 1.6.0.9 from CSV.

Support for PS 1.4 by sokon.pl module.

Install in PrestaShop 1.5.6.2, 1.6.0.9:
Download zip. Extract, change folder name to aktcsv, zip to aktcsv.zip. Install in PS.

  Multishop feature - untested.

  Features not yet implemented:
  - resume the import if the maximum run time is exceeded
  - products with a price of zero may automatically be deactivated
  - chceck for update

[PHP]
In php.ini SET max_input_vars >1000

[PL]
Moduł aktualizacyjny stany magazynowe i ceny produktów (lub tylko stany) w PrestaShop 1.4, 1.5.6.2 i 1.6.0.9

http://prestadesign.pl/moduly-f13/aktualizacja-cen-i-stanow-z-csv-t1165-110.html


[Warnings]
- BACKUP YOUR DATABASE FIRST! Preferably using outside tools such as mysqldump
Then examine the backup file - does it look like it holds all your data??
- Turn on MAINTENANCE MODE. You don't want customers confusing things.
- TEST IT THOROUGHLY. If you are not convinced everything is right, restore from your backup!