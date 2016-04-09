PrestaShop_aktcsv
================================================

[PHP]
In php.ini SET max_input_vars >1000

[PL]
Moduł aktualizacyjny stany magazynowe i ceny produktów (lub tylko stany) w PrestaShop 1.4, 1.5.4.1, 1.5.6.2 i 1.6.0.9

Nie dziala w kazdej konfiguracji. Nie testowany na wersjach nowszych niz 1.6.0.9.

http://prestadesign.pl/topic/1138-aktualizacja-cen-i-stan%C3%B3w-z-csv/page-9


[Ostrzezenie]
- Najpier zrob kopie zapasowa bazy danych! Preferably using outside tools such as mysqldump
Then examine the backup file - does it look like it holds all your data??
- Turn on MAINTENANCE MODE. You don't want customers confusing things.
- TEST IT THOROUGHLY. If you are not convinced everything is right, restore from your backup!