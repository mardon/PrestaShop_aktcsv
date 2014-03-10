<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
/*
  Zmiany Leszek:
  Moduł testowany w PrestaShop 1.5.6.1
  Do /config/settings.inc.php nalezy dodać:
  define('_DB_SER_', 'localhost'); <--adres serwera jak _DB_SERVER
  define('_DB_PORT_', '3306');     <-- port serwera, domyślny 3306


  dodano:
  if (!defined('_PS_VERSION_')) exit;
  $this->confirmUninstall = $this->l('Chcesz mnie odinstalować?');
  logowanie zapytań SQL (to bedzie w trybie debug)
  obsługa multishop (w testach)
  obsługa cen brutto/netto (obecnie brutto)
  przenesiono:
  wyswietlanie informacji o niepowodzeniu aktualizacji z echo do statusu/podsumowania aktualizacji
  poprawiono/zmieniono:
  1) jesli jest opcja 'atrybuty==1' id_tax zamieniono na id_tax_rules_group /ponieważ nie ma id_tax w ps_products/
  2) dodano isset do sprawdzania, czy jest zmienna
  3) dodano definicję zmiennych, m.in. $output
  4) dodano klamry do IF
  5) dodano else i ustawienie zmiennej na 0, gdy nie zdefiniowana, m.in $zerowanie
  6) częściowo formatowanie kodu
  7) poprawiono if'a z spr.. filtra i strpos w //szukamy wg filtra_1, blad empty needle
  8) domyślnie teraz jest reference (kod produktu)
  9) wyłączono sprawdzanie aktualizacji
  10) zmieniono długości pól input - kosmetyka
  11) usunięto sprawdzanie, czy marza_plus jest == ""
  12) poprawiono if post() na Tools::isSubmit()
 */

class AktCsv extends Module {

    function __construct() {
        $this->name = 'aktcsv';
        $this->tab = 'Moduły Sokon.pl';
        $this->version = 2.0;

        parent::__construct();

        $this->page = basename(__FILE__, '.php');
        $this->displayName = $this->l('Aktualizacja z CSV');
        $this->description = $this->l('Aktualizuje ceny i stany z pliku .CSV');

        $this->confirmUninstall = $this->l('Chcesz mnie odinstalować?');
    }

    function install() {
        if (parent::install() == false) {
            return false;
        }
        Configuration::updateValue('SOKON_SCV_SEPARATOR', ';');
        Configuration::updateValue('SOKON_SCV_NUMER', 'reference');
        Configuration::updateValue('SOKON_SCV_MARZA', '2.00');
        Configuration::updateValue('SOKON_SCV_MARZAPLUS', '0');
        Configuration::updateValue('SOKON_SCV_LIMIT', '1');
        Configuration::updateValue('SOKON_SCV_FILTR1', '');
        return true;
    }

    public function getContent() {
        $output = null;
        // SPRAWDZAMY CZY JEST NOWSZA WERSJA
        /*
          $upd     = file_get_contents("http://www.sokon.pl/prestashop/moduly/aktcsv.txt");
          $upd_log = file_get_contents("http://www.sokon.pl/prestashop/moduly/aktcsv.log");
          if ($upd > $this->version)
          {
          $output = '<div class="warning warn" style="margin-bottom:30px; width:95%;"><h3>Twoja wersja modułu to: wersja '.$this->version.'. Dostępna jest nowa wersja modułu (ver.'.$upd.') : <a style="text-decoration: underline;" href="http://www.sokon.pl/prestashop/moduly/aktcsv.zip">Pobierz ją</a>!</h3>'.$upd_log.'</div>';
          }
         */
        $output .= '<h2>' . $this->displayName . '</h2>';

        // jeśli wysyłamy plik na serwer
        if (Tools::isSubmit("plik")) {

            // wrzucamy plik do katalogu
            $f = $_FILES['nazwa_pliku'];
            if (isset($f['name'])) {
                //    move_uploaded_file($f['tmp_name'], '/modules/aktcsv/'.$f['name']);
                move_uploaded_file($f['tmp_name'], '../modules/' . $this->name . '/' . $f['name']);

                Configuration::updateValue('SOKON_SCV_PLIK', $f['name']);
            }
            $output .= $this->displayConfirmation('Plik załadowany. <br/>Załadowałeś plik: <b>"' . Configuration::get('SOKON_SCV_PLIK') . '"</b> o rozmiarze: <b>' . $_FILES['nazwa_pliku']['size'] . '</b> bajtów.<br />');
        }


// jeśli aktualizujemy cennik    
        if (Tools::isSubmit("aktualizuj")) {

            $separator = Tools::getValue("separator");
            Configuration::updateValue('SOKON_SCV_SEPARATOR', $separator);
            $numer = Tools::getValue("numer");
            Configuration::updateValue('SOKON_SCV_NUMER', $numer);
            $marza = Tools::getValue("marza");
            Configuration::updateValue('SOKON_SCV_MARZA', $marza);
            $marza_plus = Tools::getValue("marza_plus");
            Configuration::updateValue('SOKON_SCV_MARZAPLUS', $marza_plus);
            $limit = Tools::getValue("limit");
            Configuration::updateValue('SOKON_SCV_LIMIT', $limit);
            $filtr1 = Tools::getValue("filtr1");
            Configuration::updateValue('SOKON_SCV_FILTR1', $filtr1);

            $brakujace = (Tools::getValue("brakujace") == "tak") ? 1 : 0;
            $zerowanie = (Tools::getValue("zerowanie") == "tak") ? 1 : 0;
            $atrybuty  = (Tools::getValue("atrybuty")  == "tak") ? 1 : 0;
            $id_shop   = (int)Tools::getValue("id_shop");
            
            $start = microtime(true);  // czas start
//Poprawka by KSEIKO
            $db = new mysqli(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, _DB_PORT_);

            $uchwyt = fopen('../modules/aktcsv/' . Configuration::get('SOKON_SCV_PLIK'), "r");
            //$writeFd = @fopen("../modules/aktcsv/log.txt", 'w');   // do zapisu logu operacji
            $writeBraki = @fopen("../modules/aktcsv/brakujace.txt", 'w');   // do zapisu brakujących

            $wpisow  = 0;
            $zmian_p = 0;
            $zmian_a = 0;
            $dopliku = 0;
            $counter = 0;
            $log     = '';

//NAJPIERW ZERUJEMY WSZYSTKie ceny i stany

            if ($zerowanie == 1) {
                //     $db->query("UPDATE "._DB_PREFIX_."product SET quantity=0, price=0"); //zmiana przez vivaldi
                //     $db->query("UPDATE "._DB_PREFIX_."product_attribute SET quantity=0");
            }            

            while (($data = fgetcsv($uchwyt, 0, $separator)) !== FALSE) {
                $wpisow++;

                //czyścimy zbedne znaki z csvki
                $cena = str_replace(",", ".", $data[2]);  // jeśli w cenie jest ","
                $cena = str_replace(" ", "", $cena);      // jeśli w cenie jest " "
                $ilosc = str_replace(">", "", $data[3]);  // jeśli w ilości jest ">"

                $cena *= $marza; //marza ogólna
                $cena += $marza_plus; //dodajemy stała kwotę
                
                //Aktualizuje cene i ilosc dla produktow bez atrybutu
                $lpp_id_product = Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE ' . $numer . '=\'' . $data[0] . '\' ', 0);
     
                if ($lpp_id_product > 0) {
                    $zmian_p++;
                                     
                    $lpp_teraz_jest = StockAvailable::getQuantityAvailableByProduct($lpp_id_product, 0, $id_shop);
                    $ret = StockAvailable::setQuantity($lpp_id_product, 0, $ilosc, $id_shop);

                    //zmiana ceny
                    $lpp_id_podatku = Db::getInstance()->getValue('SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'product_shop WHERE id_product = \'' . $lpp_id_product . '\' AND id_shop=\'' . $id_shop . '\' ');
                    $lpp_podatek = Db::getInstance()->getValue('SELECT rate FROM ' . _DB_PREFIX_ . 'tax WHERE id_tax = \'' . $lpp_id_podatku . '\' ');

                    // chyba wszystko mamy???? to liczymy... zmiana przez vivaldi
                    $lpp_cena_brutto = number_format($cena, 2, ".", "");
                    $lpp_podatek = ($lpp_podatek + 100) / 100;
                    $lpp_cena_netto = number_format(($lpp_cena_brutto / $lpp_podatek), 2, ".", "");

                    $lpp_akt_cena = 'UPDATE ' . _DB_PREFIX_ . 'product_shop SET price = \'' . $lpp_cena_netto . '\', date_upd=NOW() WHERE ';
                    $lpp_akt_cena .= ' id_product = \'' . $lpp_id_product . '\' ';
                    $lpp_akt_cena .= ' AND id_shop = \'' . $id_shop . '\' ';

                    $db->query($lpp_akt_cena);

                    
                    $zapytanie = 'UPDATE ' . _DB_PREFIX_ . 'product SET quantity = \'' . $ilosc . '\', price = \'' . $lpp_cena_netto . '\' WHERE ';
                    $zapytanie .= '' . $numer . '=\'' . $data[0] . '\' ';
                    $db->query($zapytanie);                       

                }//if tryb bez atrybutu
                //  AKTUALIZACJA STANÓW DLA PRODUKTÓW Z ATRYBUTAMI
                if ($atrybuty == 1) {
                    //Lechus PrestaShop 1.5.6
                    //Ilosc: ps_stock_available -> quantity
                    //StockAvailable::getQuantityAvailableByProduct($row['id_product'], $row['id_product_attribute'])
                    //Zmienic ilosc, zaktualizowac sume produktow product_id
                    //Cena: ps_product_shop ->price
                    //1. SELECT id_product_attribute, id_product FROM ps_product_attribute WHERE reference = ?
                    //2. UPDATE ps_stock_available SET quantity=? WHERE id_product_attribute=? AND id_product=? AND id_shop=1(?)
                    //3. Pobierz podatek - oblicz netto dla ceny brutto z CSV
                    // UPDATE ps_product_shop SET price=?, date_upd=NOW() WHERE id_product=? AND id_shop=1(?)

                    $lpp_id_product_atr = Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE ' . $numer . '=\'' . $data[0] . '\' ', 0);

                    if ($lpp_id_product_atr > 0) {
                        $lpp_id_product = $lpp_id_product_atr; //TODO: poprawic
                        $zmian_a++;
                        $lpp_id_product_attribute = Db::getInstance()->getValue('SELECT id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute WHERE ' . $numer . '=\'' . $data[0] . '\' ', 0);
 
                        $lpp_teraz_jest = StockAvailable::getQuantityAvailableByProduct($lpp_id_product, $lpp_id_product_attribute, $id_shop);
                        /*
                          $lpp_akt_ilosc = 'UPDATE '._DB_PREFIX_.'stock_available SET quantity = \''.$ilosc.'\' WHERE ';
                          $lpp_akt_ilosc .= 'id_product_attribute = \''.$lpp_id_product_attribute.'\' ';
                          $lpp_akt_ilosc .= ' AND id_product = \''.$lpp_id_product.'\' ';
                          $lpp_akt_ilosc .= ' AND id_shop = \''.$id_shop.'\' ';
                          $db->query($lpp_akt_ilosc);
                          fwrite($writeFd, $lpp_akt_ilosc);
                          fwrite($writeFd, "\n\r lpp_akt_ilosc, Zmienił?= ");
                         */
                        StockAvailable::setQuantity($lpp_id_product, $lpp_id_product_attribute, $ilosc, $id_shop);
                        
                        //cena brutto z plikuCSV = $cena
                        //pobiera podatek, aby wyliczyc netto     
                        $lpp_id_podatku = Db::getInstance()->getValue('SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'product_shop WHERE id_product = \'' . $lpp_id_product . '\' AND id_shop=\'' . $id_shop . '\' ');
                        $lpp_podatek = Db::getInstance()->getValue('SELECT rate FROM ' . _DB_PREFIX_ . 'tax WHERE id_tax = \'' . $lpp_id_podatku . '\' ');

                        // chyba wszystko mamy???? to liczymy... zmiana przez vivaldi
                        $lpp_cena_brutto = number_format($cena, 2, ".", "");
                        $lpp_podatek = ($lpp_podatek + 100) / 100;
                        $lpp_cena_netto = number_format(($lpp_cena_brutto / $lpp_podatek), 2, ".", "");

                        $lpp_akt_cena = 'UPDATE ' . _DB_PREFIX_ . 'product_shop SET price = \'' . $lpp_cena_netto . '\', date_upd=NOW() WHERE ';
                        $lpp_akt_cena .= ' id_product = \'' . $lpp_id_product . '\' ';
                        $lpp_akt_cena .= ' AND id_shop = \'' . $id_shop . '\' ';
                        $db->query($lpp_akt_cena);

                        $zapytanie = 'UPDATE ' . _DB_PREFIX_ . 'product_attribute SET quantity = \'' . $ilosc . '\', price = \'' . $lpp_cena_netto . '\' WHERE ';
                        $zapytanie .= '' . $numer . '=\'' . $data[0] . '\' ';
                        $db->query($zapytanie);                       
                        
                    }//IF ZNALEZIONO ID_PRODUCT
                }//if atrybuty
                
                //jeśli sprawdzamy produkty ktorych nie ma w sklepie
                if ($brakujace == 1) {
                    //szukamy wg filtra_1
                    if ($lpp_id_product == '' && $lpp_id_product_atr=='') {    // nie znaleziono produktu ani bez Atr, ani z Atrybutem
                        if ((($filtr1 == "") && ($cena != "0.00") && ($ilosc >= $limit)) OR
                                (($filtr1 != "") && (strpos($data[1], $filtr1, 0) !== false) && ($cena != "0.00") && ($ilosc >= $limit))) {
                            $log .= 'Nieznaleziony produkt w bazie: indeks - <b>' . $data[0] . '</b> nazwa - <b>' . $data[1] . '</b> cena - <b>' . $cena . '</b>  ilość - <b>' . $ilosc . '</b><br />';
                            fwrite($writeBraki, "\n\r");
                            fwrite($writeBraki, 'Nieznaleziony produkt w bazie: Indeks - ' . $data[0] . '  nazwa - ' . $data[1] . '   cena - ' . $cena . '   ilość - ' . $ilosc);
                            $dopliku++;

                            @ob_flush();
                            @flush();
                        }
                    }
                } else {    //jeśli nie sprawdzamy produktów ktorych nie ma w sklepie (aby ominąć limit czasu dla skryptu)
                    $counter++;
                    if (($counter / 100) == (int) ($counter / 100)) {
                        echo '~';
                    }
                    if ($counter > 8000) {
                        echo '<br />';
                        $counter = 0;
                        @ob_flush();
                        @flush();
                    }
                }
                
                $lpp_id_product = '';
            }//while
            fclose($uchwyt);
            fclose($writeBraki); // zamyka brakujące


            unset($db);  // zamyka połączenie z bazą

            $koniec = microtime(true);   //koniec wykonywania skryptu
            $czas = round($koniec - $start, 2);

            if ($filtr1 == "") {
                $filtr1 = "wszystkie brakujące pozycje";
            }
            if ($brakujace != 1) {
                $filtr1 = "Zablokowaleś sprawdzanie produktów.";
            }
            $output .= $this->displayConfirmation('<b>Aktualizacja przeprowadzona pomyślnie</b><br/>Produktów w pliku ' . Configuration::get('SOKON_SCV_PLIK') . ': <b>' . $wpisow . '</b><br/>Poprawionych produktów w bazie: <b>' . $zmian_p . '</b><br />Poprawionych atrybutów w bazie: <b>' . $zmian_a . '</b><br />Marżę ustlilem na: <b>' . (($marza - 1) * 100) . '%.</b><br/>Czas przetwarzania skryptu: <b>' . $czas . '</b> sekund<br/>W pliku "brakujace.txt" zapisałem: <b>' . $filtr1 . '</b> (ilość wpisanych pozycji: <b>' . $dopliku . '</b>).<br/><b>Log:</b><br/>' . $log);
        }//if post[aktualizuj]
        //BEZ AKCJI WYSWIETLA formularze
        return $output . $this->displayForm();
    }

    public function displayForm() {

        global $cookie, $currentIndex;
        $id_shop = null;
        $shop_name = '';
        $context = Context::getContext();

        // if there is no $id_shop, gets the context one
        if ($id_shop === null && Shop::getContext() != Shop::CONTEXT_GROUP) {
            $id_shop = (int) $context->shop->id;
            $shop_name = $context->shop->domain;
        }

        $output = '
<fieldset><legend>' . $this->l('Wybierz plik o nazwie') . ' "*.csv" ' . $this->l('(nr kat; nazwa; cena; ilość)') . '</legend>
<form method="post" action="' . $_SERVER['REQUEST_URI'] . '" enctype="multipart/form-data">
<input type="hidden" name="MAX_FILE_SIZE" value="20000000" />
<input type="file" name="nazwa_pliku" />
<input type="submit" name="plik" value="' . $this->l('Wyślij ten plik na serwer') . '" class="button" />
</form></fieldset>
<br />
<br />
<fieldset><legend>' . $this->l('Główne funkcje modułu') . '</legend>

<form method="post"  action="' . $_SERVER['REQUEST_URI'] . '"> 
' . $this->l('Aktualizacja przeprowadzona będzie z pliku:') . ' <b>' . Configuration::get('SOKON_SCV_PLIK') . '</b><br />
<input type="text" name="separator" value="' . Configuration::get('SOKON_SCV_SEPARATOR') . '" size="10"> ' . $this->l('Separator pól w pliku *.csv') . '</input><br /><br />
<select name="numer">
  <option value="supplier_reference"> Nr ref. dostawcy</option>
  <option value="reference" selected="selected"> Kod produktu</option>
  <option value="ean13"> Kod EAN13</option></select> ' . $this->l('Wybierz numeru 1 kol.') . '<br />
<input type="text" name="marza" value="' . Configuration::get('SOKON_SCV_MARZA') . '" size="11"> ' . $this->l('Jaką ustalamy marżę? (np. 1.20 - 20%)') . '</input><br />

<input type="text" name="marza_plus" value="' . Configuration::get('SOKON_SCV_MARZAPLUS') . '" size="11"> ' . $this->l('Stała kwota dodawana do ceny poza marżą.') . '</input><br />
<select name="brutto">
  <option value="1" selected="selected">Brutto</option>
  <option value="0" disabled> Netto</option>
  <option value="0" disabled> -- ---- ----------------</option>
  </select> ' . $this->l('Ceny produktów') . '<br /><br />
      
<input type="checkbox" name="zerowanie" value="tak" checked="checked" /> ' . $this->l('Zerować stany i ceny?') . '<br />
<input type="checkbox" name="atrybuty" value="tak" checked="checked" /> ' . $this->l('Mam w bazie produkty z atrybutami') . '<br /><br />

<p><b>' . $this->l('Opcje tworzenia pliku z brakującymi produktami') . '</b></p>
<input type="checkbox" name="brakujace" value="tak" checked="checked" /> ' . $this->l('Sprawdzać produkty których nie ma w sklepie a są w pliku *csv?') . '<br />
<input type="text" name="limit" value="' . Configuration::get('SOKON_SCV_LIMIT') . '" size="11"> ' . $this->l('Jaki limit sztuk na magazynie?') . '</input><br />
<input type="text" name="filtr1" value="' . Configuration::get('SOKON_SCV_FILTR1') . '" size="11"> ' . $this->l('Filtr 1 Co wyszukujemy?') . '</input><br />
<p><b>' . $this->l('Id_shop, w którym robimy aktualizacje') . '</b></p>
<input type="text" name="id_shop" value="' . $id_shop . '" size="11"> ' . $this->l($shop_name) . '</input><br />
<p><input type="submit" name="aktualizuj" value="' . $this->l('Przeprowadź aktualizację') . '" class="button" /> ' . $this->l('Może trochę potrwać! - bądź cierpliwy...') . '</P>
</form> 

</fieldset>
<br />
<br />
<fieldset>
<legend>' . $this->l('Dodatki') . '</legend>
<p>' . $this->l('Ostatnio wygenerowany plik z brakującymi produktami:') . ' <b><a href="brakujace.txt">Brakujące.txt</a></b></p>
</fieldset>
<br />
<br />
<br />
<fieldset>
<legend><img src="../img/admin/comment.gif"/>Informacje</legend>
<p style="text-align:center;">Jeśli potrzebujesz pomocy w dostosowaniu tego modulu do Twoich potrzeb skontaktuj się z nami:<br />
www: <b><a href="http://www.sokon.pl">Sokon.pl</a></b><br />
e-mail: <b><a href="mailto:sokon@sokon.pl">sokon@sokon.pl</a></b><br />
Poprawki do PS 1.5.6: <b><a href="mailto:leszek.pietrzak@gmail.com">Leszek.Pietrzak@gmail.com</a></b><br />
</p><br />
<p>
Moduł ten aktualizuje ceny oraz stany magazynowe z pliku *.csv . Plik musi mieć nastepującą postać:<br /> 
kod;nazwa produktu;cena;ilość. <br />
Produkty w bazie rozpoznawane są po numerze referencyjnym dostawcy, kodzie produktu lub kodzie EAN13. <br />
Skrypt tworzy plik o nazwie "brakujace.txt" w którym zapisywane są produkty znajdujące się w pliku csv a ktorych nie ma w bazie sklepu. Dodatkowo produkty zapisywane do tego pliku możemy ograniczyć do produktów zawierających w nazwie określone slowa (wpisując je w pola "Filtr_1" ), oraz tylko do produktów o ilości nie mniejszej niż wpisana w pole "Limit sztuk na magazynie".<br /><br />
Moduł można w prosty sposób dostosować do swoich potrzeb (kod modułu jest dokladnie opisany). Jesli jednak nie czujesz się na siłach aby zrobić to samemu zapraszam do kontaktu.
</p>
</fieldset>';
        return $output;
    }

}

// NA POTEM...
// <script type="text/javascript">
//$("legend#gl").click(function() {
//  $("div#rozw").slideToggle("slow");
//});
//</script>  

?>