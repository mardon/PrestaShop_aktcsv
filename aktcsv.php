<?php

/*
  Module Name: AktCSV
  Module URI: https://github.com/Lechus
  Description: Aktualizuje stany i ceny w Prestashop 1.5.6.2, 1.6.0.6
  Version: 3.2
  Author: Leszek Pietrzak
  Author URI: https://github.com/Lechus
 * 
 * 2014-04-11: PrestaShop 1.6.0.6: updating stocks only.
 * 2014-09-29: PrestaShop 1.6.0.6: updating price only.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AktCsv extends Module
{
    private $_html = ''; // used to store the html output for the back-office

    const PRICE_ONLY = 3;
    const STOCK_ONLY = 1;
    const PRICE_STOCK = 2;

    function __construct()
    {
        $this->name = 'aktcsv';
        $this->tab = 'Others';
        $this->version = '3.141003';
        $this->author = 'LPP';

        parent::__construct();

        $this->page = basename(__FILE__, '.php');
        $this->displayName = $this->l('Aktualizacja z CSV');
        $this->description = $this->l('Aktualizuje ceny i stany z pliku .CSV');

        $this->confirmUninstall = $this->l('Chcesz mnie odinstalować?');
    }

    public function install()
    {
        if (version_compare(_PS_VERSION_, '1.5', '<')) { // let's check the PrestaShop version here
            $this->_errors[] = $this->l('Wymagana PrestaShop minimum 1.5.');
            return false;
        }

        if (parent::install() == false) {
            return false;
        }
        // Default settings
        Configuration::updateValue($this->name . '_SEPARATOR', ';');
        Configuration::updateValue($this->name . '_NUMER', 'reference');
        Configuration::updateValue($this->name . '_MARZA', '1.00');
        Configuration::updateValue($this->name . '_MARZAPLUS', '0');
        Configuration::updateValue($this->name . '_LIMIT', '1');
        Configuration::updateValue($this->name . '_FILTR1', '');
        Configuration::updateValue($this->name . '_CSVFILE', '');
        return true;
    }

    public function uninstall()
    {
        return parent::uninstall() && Configuration::deleteByName(
            $this->name . '_SEPARATOR'
        ) && Configuration::deleteByName($this->name . '_NUMER') && Configuration::deleteByName(
            $this->name . '_MARZA'
        ) && Configuration::deleteByName($this->name . '_MARZAPLUS') && Configuration::deleteByName(
            $this->name . '_LIMIT'
        ) && Configuration::deleteByName($this->name . '_FILTR1') && Configuration::deleteByName(
            $this->name . '_CSVFILE'
        );
    }

    //Backoffice "Configure/Konfiguruj"
    public function getContent()
    {
        //TODO: Module update

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

    public function displayForm()
    {
        $shop_name = '';
        $id_shop = 0;

        if (Shop::getContext() != Shop::CONTEXT_GROUP) {
            $context = Context::getContext();
            $id_shop = (int)$context->shop->id;
            $shop_name = $context->shop->domain;
        }

        $this->_html .= '
<fieldset>
<legend>'
            . $this->l('Wybierz plik CSV') . ' "*.csv" ' . $this->l(
                '(nr kat; nazwa; cena; ilość)'
            ) . ' lub ' . $this->l('(index; ilość)')
            . '</legend>
<form method="post" action="' . $_SERVER['REQUEST_URI'] . '" enctype="multipart/form-data">
<input type="hidden" name="MAX_FILE_SIZE" value="20000000" />
<input type="file" name="csv_filename" />
<input type="submit" name="submit_csv" value="' . $this->l('Wyślij ten plik na serwer') . '" class="button" />
</form></fieldset>
<br />
<br />
<fieldset><legend>' . $this->l('Główne funkcje modułu') . '</legend>

<form method="post"  action="' . $_SERVER['REQUEST_URI'] . '"> 
' . $this->l('Aktualizacja przeprowadzona będzie z pliku:') . ' <b>' . Configuration::get($this->name . '_CSVFILE') . '</b><br />
<input type="text" name="separator" value="' . Configuration::get(
                $this->name . '_SEPARATOR'
            ) . '" size="10"> ' . $this->l('Separator pól w pliku *.csv') . '</input>
    <br /><br />
<select name="rodzaj_aktualizacji">
  <option value="2">Ceny i stany</option>
  <option value="1"> Tylko stany</option>
  <option value="3" selected="selected"> Tylko ceny</option>
  <option value="2" disabled> -- ---- -------------</option>
</select> ' . $this->l('Rodzaj aktualizacji') . '<br /><br />

<select name="numer">
  <option value="supplier_reference"> Nr ref. dostawcy</option>
  <option value="reference" selected="selected"> Kod produktu</option>
  <option value="ean13"> Kod EAN13</option></select> ' . $this->l('Wybierz numeru 1 kol.') . '<br />
<input type="text" name="marza" value="' . Configuration::get($this->name . '_MARZA') . '" size="11"> ' . $this->l(
                'Jaką ustalamy marżę? (np. 1.20 - 20%)'
            ) . '</input><br />

<input type="text" name="marza_plus" value="' . Configuration::get(
                $this->name . '_MARZAPLUS'
            ) . '" size="11"> ' . $this->l('Stała kwota dodawana do ceny poza marżą') . '</input><br />
<select name="brutto">
  <option value="1" selected="selected">Brutto</option>
  <option value="0" disabled> Netto</option>
  <option value="0" disabled> -- ---- -------------</option>
  </select> ' . $this->l('Ceny produktów') . '<br /><br />
      
<input type="checkbox" name="zerowanie" value="tak" disabled /> ' . $this->l('Zerować stany i ceny?') . '<br />
<input type="checkbox" name="atrybuty" value="tak" checked="checked" /> ' . $this->l(
                'Mam w bazie produkty z atrybutami'
            ) . '<br /><br />

<p><b>' . $this->l('Opcje tworzenia pliku z brakującymi produktami') . '</b></p>
<input type="checkbox" name="productNotInDB" value="tak" checked="checked" /> ' . $this->l(
                'Sprawdzać produkty których nie ma w sklepie a są w pliku *csv?'
            ) . '<br />
<input type="text" name="limit" value="' . Configuration::get($this->name . '_LIMIT') . '" size="11"> ' . $this->l(
                'Jaki limit sztuk na magazynie?'
            ) . '</input><br />
<input type="text" name="filtr1" value="' . Configuration::get($this->name . '_FILTR1') . '" size="11"> ' . $this->l(
                'Filtr 1 Co wyszukujemy?'
            ) . '</input><br />
<p><b>' . $this->l('Id_shop, w którym robimy aktualizacje') . '</b></p>
<input type="text" name="id_shop" value="' . $id_shop . '" size="11"> ' . $this->l($shop_name) . '</input><br />
<p><input type="submit" name="submit_update" value="' . $this->l(
                'Przeprowadź aktualizację'
            ) . '" class="button" /> ' . $this->l('Może trochę potrwać! - bądź cierpliwy...') . '</P>
</form> 

</fieldset>
<br />
<br />
<fieldset>
<legend>' . $this->l('Dodatki') . '</legend>
<p>' . $this->l('Ostatnio wygenerowany plik z brakującymi produktam:') . ' '
            . '<b><a style="text-decoration: underline;" href="' . _MODULE_DIR_ . 'aktcsv/missed_products.txt">missed_products.txt</a></b>
                 </p>
</fieldset>
<br />
<br />
<br />
<fieldset>
<legend><img src="../img/admin/comment.gif"/>Informacje</legend>
<p style="text-align:center;">Potrzebujesz pomocy, modyfikacji?<br />
 PS 1.5.6.2: <b><a href="mailto:leszek.pietrzak@gmail.com">Leszek.Pietrzak@gmail.com</a></b><br />
</p><br />
<p>
Moduł ten aktualizuje ceny oraz stany magazynowe z pliku *.csv . Plik musi mieć nastepującą postać:<br /> 
kod;nazwa produktu;cena;ilość lub index;ilosc <br />
Produkty w bazie rozpoznawane są po numerze referencyjnym dostawcy, kodzie produktu lub kodzie EAN13. <br />
Skrypt tworzy plik o nazwie "missed_products.txt" w którym zapisywane są produkty znajdujące się w pliku csv
a ktorych nie ma w bazie sklepu. Dodatkowo produkty zapisywane do tego pliku możemy ograniczyć do produktów
zawierających w nazwie określone slowa (wpisując je w pola "Filtr_1" ), oraz tylko do produktów o ilości
nie mniejszej niż wpisana w pole "Limit sztuk na magazynie".<br /><br />
Moduł można w prosty sposób dostosować do swoich potrzeb (kod modułu jest dokladnie opisany). 
Jesli jednak nie czujesz się na siłach aby zrobić to samemu zapraszam do kontaktu.
</p>
</fieldset>';
        return $this->_html;
    }

    private function _uploadCSVFile()
    {
        $uploadedFile = $_FILES['csv_filename'];
        if (isset($uploadedFile['name'])) {
            move_uploaded_file($uploadedFile['tmp_name'], '../modules/' . 'aktcsv/' . $uploadedFile['name']);
            Configuration::updateValue($this->name . '_CSVFILE', $uploadedFile['name']);
        }
        $this->_html .= $this->displayConfirmation(
            'Plik załadowany. <br/>Załadowałeś plik: '
            . '<b>"' . Configuration::get($this->name . '_CSVFILE') . '"</b>,'
            . ' size: <b>' . $_FILES['csv_filename']['size'] . '</b> bytes.<br />'
        );
        Logger::addLog('AktCSV module: Plik załadowany.');
    }

    private function _updateDB()
    {
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
        $updateMode = (int)Tools::getValue("rodzaj_aktualizacji");
        Configuration::updateValue($this->name . '_RODZAJAKTUALIZACJI', rodzaj_aktualizacji);


        $productNotInDB = (Tools::getValue("productNotInDB") == "tak") ? 1 : 0;
        $zerowanie = (Tools::getValue("zerowanie") == "tak") ? 1 : 0;
        $attributes = (Tools::getValue("atrybuty") == "tak") ? 1 : 0;
        $id_shop = (int)Tools::getValue("id_shop");

        $handleCSVFile = fopen('../modules/aktcsv/' . Configuration::get($this->name . '_CSVFILE'), 'r');
        $handleNotInDB = @fopen('../modules/aktcsv/missed_products.txt', 'w'); // do zapisu brakujących

        $wpisow = 0;
        $zmian_p = 0;
        $znalezionych_p = 0;
        $znalezionych_a = 0;
        $dopliku = 0;
        $counter = 0;
        $log = '';

        //TODO: Implement updated option for reset prices and stocks

        while (($data = fgetcsv($handleCSVFile, 0, $separator)) !== false) {
            $wpisow++;

            $reference = $data[0];

            switch ($updateMode) {
                case self::PRICE_ONLY:
                    $price = $this->_clearCSVPrice($data[1]);
                    $price *= $marza;
                    $price += $marza_plus;
                    break;
                case self::STOCK_ONLY:
                    $quantity = $this->_clearCSVIlosc($data[1]);
                    break;
                case self::PRICE_STOCK:
                    $price = $this->_clearCSVPrice($data[2]);
                    $quantity = $this->_clearCSVIlosc($data[3]);
                    $price *= $marza;
                    $price += $marza_plus;
                    break;
                default:
                    exit('No implemented');
                    break;
            }


            //Product without attribute
            $idProduct = (int)Db::getInstance()->getValue(
                'SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE ' . $numer . '=\'' . $reference . '\' ',
                0
            );

            if ($idProduct > 0) {
                $znalezionych_p++;

                if ($updateMode == self::STOCK_ONLY || $updateMode == self::PRICE_STOCK) {
                    StockAvailable::setQuantity($idProduct, 0, $quantity, $id_shop);
                    $this->_updateProductWithOutAttribute($numer, $reference, null, $quantity);
                }
                if ($updateMode == self::PRICE_ONLY || $updateMode == self::PRICE_STOCK) {
                    $taxRate = $this->_getTaxRate($idProduct, $id_shop);
                    $priceNet = $this->_calculateAndFormatNetPrice($price, $taxRate);

                    $this->_updateProductPriceInShop($priceNet, $idProduct, $id_shop);
                    $this->_updateProductWithOutAttribute($numer, $reference, $priceNet, null);
                }
            }

            //Product with attribute
            if ($attributes == 1) {
                $productWithAttribute = Db::getInstance()->getRow(
                    'SELECT id_product, id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute`'
                    . ' WHERE ' . $numer . '=\'' . $reference . '\' ',
                    0
                );

                if (!empty($productWithAttribute) && $productWithAttribute['id_product'] > 0) {
                    $znalezionych_a++;
                    $idProductWithAttribute = $productWithAttribute['id_product'];
                    $idProductAttribute = $productWithAttribute['id_product_attribute'];

                    if ($updateMode == self::STOCK_ONLY || $updateMode == self::PRICE_STOCK) {
                        StockAvailable::setQuantity($idProduct, $idProductAttribute, $quantity, $id_shop);
                        $this->_updateProductWithAttribute($numer, $reference, null, $quantity);
                    }

                    if ($updateMode == self::PRICE_ONLY || $updateMode == self::PRICE_STOCK) {
                        $taxRate = $this->_getTaxRate($idProductWithAttribute, $id_shop);
                        $priceNet = $this->_calculateAndFormatNetPrice($price, $taxRate);

                        $this->_updateProductPriceInShop($priceNet, $idProductWithAttribute, $id_shop);
                        $this->_updateProductWithAttribute($numer, $reference, $priceNet, null);
                    }

                }
            }

            if ($productNotInDB == 1) {
                //szukamy wg filtra_1
                if ($idProduct == '' && $idProduct_atr == '') { // nie znaleziono produktu ani bez Atr, ani z Atrybutem
                    if ((($filtr1 == "") && ($price != "0.00") && ($quantity >= $limit)) or
                        (($filtr1 != "") && (strpos(
                                    $quantity,
                                    $filtr1,
                                    0
                                ) !== false) && ($price != "0.00") && ($quantity >= $limit))
                    ) {
                        $log .= 'Nieznaleziony produkt w bazie: indeks - <b>' . $reference . '</b> nazwa - <b>' . $quantity . '</b> cena - <b>' . $price . '</b>  ilość - <b>' . $quantity . '</b><br />';
                        fwrite($handleNotInDB, "\n\r");
                        fwrite(
                            $handleNotInDB,
                            'Nieznaleziony produkt w bazie: Indeks - ' . $reference . '  nazwa - ' . $quantity . '   cena - ' . $price . '   ilość - ' . $quantity
                        );
                        $dopliku++;

                        @ob_flush();
                        @flush();
                    }
                }
            } else { //jeśli nie sprawdzamy produktów ktorych nie ma w sklepie (aby ominąć limit czasu dla skryptu)
                $counter++;
                if (($counter / 100) == (int)($counter / 100)) {
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
        fclose($handleNotInDB);

        if ($filtr1 == "") {
            $filtr1 = "All missing products";
        }
        if ($productNotInDB != 1) {
            $filtr1 = "Checking products disabled.";
        }

        $codeEnd = microtime(true); //koniec wykonywania skryptu
        $elapsedTime = round($codeEnd - $codeStart, 2);

        $this->_html .= $this->displayConfirmation(
            '<b>Success</b><br/>Products quantity in file ' . Configuration::get($this->name . '_CSVFILE') . ':'
            . ' <b>' . $wpisow . '</b><br/>Found products: <b>' . $znalezionych_p . '</b><br />Found product with attribute: <b>' . $znalezionych_a . '</b><br />'
            . 'Set profit: <b>' . (($marza - 1) * 100) . '%.</b><br/>Execution time: <b>' . $elapsedTime . '</b> seconds<br/>'
            . 'In file "missed_products.txt" I wrote: <b>' . $filtr1 . '</b> (number of records: <b>' . $dopliku . '</b>).<br/>'
        );
    }

    private function _clearCSVPrice($priceToClear)
    {
        $price = str_replace(",", ".", $priceToClear);
        return str_replace(" ", "", $price);
    }

    private function _clearCSVIlosc($amountToClear)
    {
        return str_replace(">", "", $amountToClear);
    }

    private function _calculateAndFormatNetPrice($price, $taxRate)
    {
        $priceGross = number_format($price, 2, ".", "");
        $taxRate = ($taxRate + 100) / 100;
        return number_format(($priceGross / $taxRate), 2, ".", "");
    }

    private function _getTaxRate($idProduct, $id_shop)
    {
        $idTax = Db::getInstance()->getValue(
            'SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'product_shop WHERE id_product = \'' . $idProduct . '\' AND id_shop=\'' . $id_shop . '\' '
        );
        return Db::getInstance()->getValue(
            'SELECT rate FROM ' . _DB_PREFIX_ . 'tax WHERE id_tax = \'' . $idTax . '\' '
        );
    }

    private function _updateProductPriceInShop($priceNet, $idProduct, $id_shop)
    {
        return Db::getInstance()->update('product_shop', array(
                'price' => (float)$priceNet,
                'date_upd' => date("Y-m-d H:i:s"),
            ), 'id_product = \'' . $idProduct . '\'  AND id_shop = \'' . $id_shop . '\' '
        );
    }

    private function _updateProductWithAttribute($numer, $ref, $priceNet = null, $quantity = null)
    {
        $values = array();
        if (!is_null($priceNet)) {
            $values['quantity'] = $quantity;
        }
        if (!is_null($quantity)) {
            $values['price'] = $priceNet;
        }

        return Db::getInstance()->update('product_attribute', $values, $numer . ' = \'' . $ref . '\' ');
    }

    private function _updateProductWithOutAttribute($numer, $ref, $priceNet = null, $quantity = null)
    {
        $values = array();
        if (!is_null($priceNet)) {
            $values['quantity'] = $quantity;
        }
        if (!is_null($quantity)) {
            $values['price'] = $priceNet;
        }
        return Db::getInstance()->update('product', $values, $numer . ' = \'' . $ref . '\' ');
    }

    private function _getIdProductAttribute($numer, $ref)
    {
        return Db::getInstance()->getValue(
            'SELECT id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute WHERE ' . $numer . '=\'' . $ref . '\' ',
            0
        );
    }
}
//product_shop 	Product shop associations 	id_product, id_shop

//Not used:
//Product attribute shop associations
//product_attribute_shop.`price`
