<?php

/*
  Module Name: AktCSV
  Module URI: https://github.com/Lechus
  Description: Update stock and price of products in Prestashop 1.5.6
  Version: 3.0
  Author: Leszek Pietrzak
  Author URI: https://github.com/Lechus

  Notice:
  Module based on AktCSV 2.0 by Sokon.pl for PS 1.4 [PL]
  Not finished!
  You need add to /config/settings.inc.php:
  define('_DB_SER_', 'localhost'); //Database server address
  define('_DB_PORT_', '3306');     //Database server port

  Multishop feature - untested.

  Features not yet implemented:
  - resume the import if the maximum run time is exceeded
  - products with a price of zero may automatically be deactivated
  - chceck for update

 */


if (!defined('_PS_VERSION_')) {
    exit;
}
/*
  dodano:
  obsługa cen brutto/netto (obecnie brutto)
  UPDATE combinations and stock

  poprawiono/zmieniono:
  1) jesli jest opcja 'atrybuty==1' id_tax zamieniono na id_tax_rules_group /ponieważ nie ma id_tax w ps_products/
  5) dodano else i ustawienie zmiennej na 0, gdy nie zdefiniowana, m.in $zerowanie
  7) poprawiono if'a z spr.. filtra i strpos w //szukamy wg filtra_1, blad empty needle
  11) usunięto sprawdzanie, czy marza_plus jest == ""
  13) zerowanie - opcja jest disabled

 */

class AktCsv extends Module {

    private $_html = '';
    protected $db;
    
    function __construct() {
        $this->name = 'aktcsv';
        $this->tab = 'LPP';
        $this->version = 3.0;

        parent::__construct();

        $this->page = basename(__FILE__, '.php');
        $this->displayName = $this->l('Update from CSV');
        $this->description = $this->l('Update price and stock from CSV file.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    function install() {
        if (parent::install() == false) {
            return false;
        }
        // Set some defaults
        Configuration::updateValue($this->name . '_SEPARATOR', ';');
        Configuration::updateValue($this->name . '_NUMER', 'reference');
        Configuration::updateValue($this->name . '_MARZA', '2.00');
        Configuration::updateValue($this->name . '_MARZAPLUS', '0');
        Configuration::updateValue($this->name . '_LIMIT', '1');
        Configuration::updateValue($this->name . '_FILTR1', '');
        Configuration::updateValue($this->name . '_CSVFILE', '');
        return true;
    }

    //The getContent() function is called when the Configure link on a module is clicked.
    public function getContent() {
        //TODO: Implement Check for updates

        $this->_html .= '<h2>' . $this->displayName . '</h2>';

        // Send new CSV file to server
        if (Tools::isSubmit("submit_csv")) {
            $this->_uploadCSVFile();
        }

        // Update prices
        if (Tools::isSubmit("submit_update")) {
            $this->_updateDB();
        }

        $this->displayForm();
        return $this->_html;
    }

    public function displayForm() {
        $shop_name = '';
        $id_shop   = 0;

        if (Shop::getContext() != Shop::CONTEXT_GROUP) {
            $context = Context::getContext();
            $id_shop = (int) $context->shop->id;
            $shop_name = $context->shop->domain;
        }

        $this->_html = '
<fieldset><legend>' . $this->l('Choose a CSV file') . ' "*.csv" ' . $this->l('(cat no; name; price; amount)') . '</legend>
<form method="post" action="' . $_SERVER['REQUEST_URI'] . '" enctype="multipart/form-data">
<input type="hidden" name="MAX_FILE_SIZE" value="20000000" />
<input type="file" name="csv_filename" />
<input type="submit" name="submit_csv" value="' . $this->l('Upload file') . '" class="button" />
</form></fieldset>
<br />
<br />
<fieldset><legend>' . $this->l('Main module functions') . '</legend>

<form method="post"  action="' . $_SERVER['REQUEST_URI'] . '"> 
' . $this->l('Update DB from file:') . ' <b>' . Configuration::get($this->name . '_CSVFILE') . '</b><br />
<input type="text" name="separator" value="' . Configuration::get($this->name . '_SEPARATOR') . '" size="10"> ' . $this->l('Separator character *.csv') . '</input><br /><br />
<select name="numer">
  <option value="supplier_reference"> Supplier reference</option>
  <option value="reference" selected="selected"> Reference</option>
  <option value="ean13"> EAN13</option></select> ' . $this->l('Choose type of 1. column') . '<br />
<input type="text" name="marza" value="' . Configuration::get($this->name . '_MARZA') . '" size="11"> ' . $this->l('Set profit? (ex. 1.20 - 20%)') . '</input><br />

<input type="text" name="marza_plus" value="' . Configuration::get($this->name . '_MARZAPLUS') . '" size="11"> ' . $this->l('Constant profit added to price') . '</input><br />
<select name="brutto">
  <option value="1" selected="selected">Gross</option>
  <option value="0" disabled> Net</option>
  <option value="0" disabled> -- ---- -------------</option>
  </select> ' . $this->l('Price type') . '<br /><br />
      
<input type="checkbox" name="zerowanie" value="tak" disabled /> ' . $this->l('Zero prices and stocks?') . '<br />
<input type="checkbox" name="atrybuty" value="tak" checked="checked" /> ' . $this->l('Products with attributes?') . '<br /><br />

<p><b>' . $this->l('Opcje tworzenia pliku z brakującymi produktami') . '</b></p>
<input type="checkbox" name="productNotInDB" value="tak" checked="checked" /> ' . $this->l('Sprawdzać produkty których nie ma w sklepie a są w pliku *csv?') . '<br />
<input type="text" name="limit" value="' . Configuration::get($this->name . '_LIMIT') . '" size="11"> ' . $this->l('Jaki limit sztuk na magazynie?') . '</input><br />
<input type="text" name="filtr1" value="' . Configuration::get($this->name . '_FILTR1') . '" size="11"> ' . $this->l('Filtr 1 Co wyszukujemy?') . '</input><br />
<p><b>' . $this->l('Id_shop, w którym robimy aktualizacje') . '</b></p>
<input type="text" name="id_shop" value="' . $id_shop . '" size="11"> ' . $this->l($shop_name) . '</input><br />
<p><input type="submit" name="submit_update" value="' . $this->l('Przeprowadź aktualizację') . '" class="button" /> ' . $this->l('Może trochę potrwać! - bądź cierpliwy...') . '</P>
</form> 

</fieldset>
<br />
<br />
<fieldset>
<legend>' . $this->l('Addons') . '</legend>
<p>' . $this->l('missed products log file:') . ' <b><a style="text-decoration: underline;" href="' . _MODULE_DIR_ . $this->name . '/missed_products.txt">missed_products.txt</a></b></p>
</fieldset>
<br />
<br />
<br />
<fieldset>
<legend><img src="../img/admin/comment.gif"/>Description</legend>
<p style="text-align:center;">Need help or modification? Contact with us:<br />
Origininal code for PS 1.4
www: <b><a href="http://www.sokon.pl">Sokon.pl</a></b><br />
e-mail: <b><a href="mailto:sokon@sokon.pl">sokon@sokon.pl</a></b><br />
Modified for PS 1.5.6.1: <b><a href="mailto:leszek.pietrzak@gmail.com">Leszek.Pietrzak@gmail.com</a></b><br />
</p><br />
<p>
Moduł ten aktualizuje ceny oraz stany magazynowe z pliku *.csv . Plik musi mieć nastepującą postać:<br /> 
kod;nazwa produktu;cena;ilość. <br />
Produkty w bazie rozpoznawane są po numerze referencyjnym dostawcy, kodzie produktu lub kodzie EAN13. <br />
Skrypt tworzy plik o nazwie "missed_products.txt" w którym zapisywane są produkty znajdujące się w pliku csv a ktorych nie ma w bazie sklepu. Dodatkowo produkty zapisywane do tego pliku możemy ograniczyć do produktów zawierających w nazwie określone slowa (wpisując je w pola "Filtr_1" ), oraz tylko do produktów o ilości nie mniejszej niż wpisana w pole "Limit sztuk na magazynie".<br /><br />
Moduł można w prosty sposób dostosować do swoich potrzeb (kod modułu jest dokladnie opisany). Jesli jednak nie czujesz się na siłach aby zrobić to samemu zapraszam do kontaktu.
</p>
</fieldset>';
        return $this->_html;
    }

    private function _uploadCSVFile() {
        $uploadedFile = $_FILES['csv_filename'];
        if (isset($uploadedFile['name'])) {
            move_uploaded_file($uploadedFile['tmp_name'], '../modules/' . $this->name . '/' . $uploadedFile['name']);
            Configuration::updateValue($this->name . '_CSVFILE', $uploadedFile['name']);
        }
        $this->_html .= $this->displayConfirmation('File uploaded. <br/>You send file: <b>"' . Configuration::get($this->name . '_CSVFILE') . '"</b>, size: <b>' . $_FILES['csv_filename']['size'] . '</b> bytes.<br />');
    }

    public function _updateDB() {
        $codeStart = microtime(true);

        $separator = Tools::getValue("separator");
        Configuration::updateValue($this->name . '_SEPARATOR', $separator);
        $numer = Tools::getValue("numer");
        Configuration::updateValue($this->name . '_NUMER', $numer);
        $marza = Tools::getValue("marza");
        Configuration::updateValue($this->name . '_MARZA', $marza);
        $marza_plus = Tools::getValue("marza_plus");
        Configuration::updateValue($this->name . '_MARZAPLUS', $marza_plus);
        $limit = Tools::getValue("limit");
        Configuration::updateValue($this->name . '_LIMIT', $limit);
        $filtr1 = Tools::getValue("filtr1");
        Configuration::updateValue($this->name . '_FILTR1', $filtr1);

        $productNotInDB = (Tools::getValue("productNotInDB") == "tak") ? 1 : 0;
        $zerowanie = (Tools::getValue("zerowanie") == "tak") ? 1 : 0;
        $atrybuty = (Tools::getValue("atrybuty") == "tak") ? 1 : 0;
        $id_shop = (int) Tools::getValue("id_shop");

//Poprawka by KSEIKO
        $this->db = new mysqli(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, _DB_PORT_);

        $handleCSVFile = fopen('../modules/aktcsv/' . Configuration::get($this->name . '_CSVFILE'), 'r');
        $handleNotInDB = @fopen('../modules/aktcsv/missed_products.txt', 'w');   // do zapisu brakujących

        $wpisow = 0;
        $zmian_p = 0;
        $zmian_a = 0;
        $dopliku = 0;
        $counter = 0;
        $log = '';

        //TODO: Implement updated option for reset prices and stocks

        while (($data = fgetcsv($handleCSVFile, 0, $separator)) !== FALSE) {
            $wpisow++;

            $price = $this->_clearCVSPrice($data[2]);
            $quantity = $this->_clearCVSIlosc($data[3]);

            $price *= $marza;
            $price += $marza_plus;

            //Product without attribute
            $idProduct = (int)Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE ' . $numer . '=\'' . $data[0] . '\' ', 0);

            if ($idProduct > 0) {
                $zmian_p++;

                StockAvailable::setQuantity($idProduct, 0, $quantity, $id_shop);

                $taxRate = $this->_getTaxRate($idProduct, $id_shop);
                $priceNet = $this->_calculateAndFormatNetPrice($price, $taxRate);

                $this->updateProductPriceInShop($priceNet, $idProduct, $id_shop);
                $this->updateProductithOutAttribute($priceNet, $quantity, $numer, $data[0]);
            }
            
            //Product with attribute
            if ($atrybuty == 1) {
                $idProduct_atr = (int)Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE ' . $numer . '=\'' . $data[0] . '\' ', 0);

                if ($idProduct_atr > 0) {
                    $zmian_a++;
                    $idProductAttribute = $this->_getIdProductAttribute($numer, $data[0]);

                    StockAvailable::setQuantity($idProduct, $idProduct_atrAttribute, $quantity, $id_shop);
  
                    $taxRate = $this->_getTaxRate($idProduct_atr, $id_shop);
                    $priceNet = $this->_calculateAndFormatNetPrice($price, $taxRate);

                    $this->updateProductPriceInShop($priceNet, $idProduct_atr, $id_shop);
                    $this->updateProductWithAttribute($priceNet, $quantity, $numer, $data[0]);
                }
            }
            
            if ($productNotInDB == 1) {
                //szukamy wg filtra_1
                if ($idProduct == '' && $idProduct_atr == '') {    // nie znaleziono produktu ani bez Atr, ani z Atrybutem
                    if ((($filtr1 == "") && ($price != "0.00") && ($quantity >= $limit)) OR
                            (($filtr1 != "") && (strpos($data[1], $filtr1, 0) !== false) && ($price != "0.00") && ($quantity >= $limit))) {
                        $log .= 'Nieznaleziony produkt w bazie: indeks - <b>' . $data[0] . '</b> nazwa - <b>' . $data[1] . '</b> cena - <b>' . $price . '</b>  ilość - <b>' . $quantity . '</b><br />';
                        fwrite($handleNotInDB, "\n\r");
                        fwrite($handleNotInDB, 'Nieznaleziony produkt w bazie: Indeks - ' . $data[0] . '  nazwa - ' . $data[1] . '   cena - ' . $price . '   ilość - ' . $quantity);
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

            $idProduct = '';
        }//while
        fclose($handleCSVFile);
        fclose($handleNotInDB); // zamyka brakujące

        unset($this->db);  // zamyka połączenie z bazą

        if ($filtr1 == "") {
            $filtr1 = "All missing products";
        }
        if ($productNotInDB != 1) {
            $filtr1 = "Checking products disabled.";
        }

        $codeEnd = microtime(true);   //koniec wykonywania skryptu
        $elapsedTime = round($codeEnd - $codeStart, 2);

        $this->_html .= $this->displayConfirmation('<b>Success</b><br/>Products quantity in file ' . Configuration::get($this->name . '_CSVFILE') . ':'
                . ' <b>' . $wpisow . '</b><br/>Modified products: <b>' . $zmian_p . '</b><br />Modiefied attributes: <b>' . $zmian_a . '</b><br />'
                . 'Set profit: <b>' . (($marza - 1) * 100) . '%.</b><br/>Execution time: <b>' . $elapsedTime . '</b> seconds<br/>'
                . 'In file "missed_products.txt" I wrote: <b>' . $filtr1 . '</b> (number of records: <b>' . $dopliku . '</b>).<br/>');
    }

    public function _clearCVSPrice($priceToClear) {
        $price = str_replace(",", ".", $priceToClear);
        return str_replace(" ", "", $price);
    }

    public function _clearCVSIlosc($amountToClear) {
        return str_replace(">", "", $amountToClear);
    }

    public function _calculateAndFormatNetPrice($price, $taxRate) {
        $priceGross = number_format($price, 2, ".", "");
        $taxRate = ($taxRate + 100) / 100;
        return number_format(($priceGross / $taxRate), 2, ".", "");
    }
    
    public function _getTaxRate($idProduct, $id_shop) {
        $idTax = Db::getInstance()->getValue('SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'product_shop WHERE id_product = \'' . $idProduct . '\' AND id_shop=\'' . $id_shop . '\' ');
        return Db::getInstance()->getValue('SELECT rate FROM ' . _DB_PREFIX_ . 'tax WHERE id_tax = \'' . $idTax . '\' ');
    }

    public function updateProductPriceInShop($priceNet, $idProduct, $id_shop) {
        $queryUpdatePrice = 'UPDATE ' . _DB_PREFIX_ . 'product_shop SET price = \'' . $priceNet . '\', date_upd=NOW() WHERE ';
        $queryUpdatePrice .= ' id_product = \'' . $idProduct . '\' ';
        $queryUpdatePrice .= ' AND id_shop = \'' . $id_shop . '\' ';
        $this->db->query($queryUpdatePrice);
    }

    public function updateProductWithAttribute($priceNet, $quantity, $numer, $ref) {
        $queryUpdatePriceAndQuantity = 'UPDATE ' . _DB_PREFIX_ . 'product_attribute SET quantity = \'' . $quantity . '\', price = \'' . $priceNet . '\' WHERE ';
        $queryUpdatePriceAndQuantity .= '' . $numer . '=\'' . $ref . '\' ';
        $this->db->query($queryUpdatePriceAndQuantity);
        
    }

    public function updateProductithOutAttribute($priceNet, $quantity, $numer, $ref) {
        $queryUpdateQuantity = 'UPDATE ' . _DB_PREFIX_ . 'product SET quantity = \'' . $quantity . '\', price = \'' . $priceNet . '\' WHERE ';
        $queryUpdateQuantity .= '' . $numer . '=\'' . $ref . '\' ';
        $this->db->query($queryUpdateQuantity);
    }

    public function _getIdProductAttribute($numer, $ref) {
        return Db::getInstance()->getValue('SELECT id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute WHERE ' . $numer . '=\'' . $ref . '\' ', 0);
    }

}

// End of: aktcsv_en.php