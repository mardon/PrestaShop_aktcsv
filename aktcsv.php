<?php

/*
  Module Name: AktCSV
  Module URI: https://github.com/Lechus
  Description: Aktualizuje stany i ceny w Prestashop 1.5.6.2, 1.6.0.6
  Version: 3.141015
  Author: Leszek Pietrzak
  Author URI: https://github.com/Lechus
 * 
 * 2014-04-11: PrestaShop 1.6.0.6: updating stocks only.
 * 2014-09-29: PrestaShop 1.6.0.6: updating price only.
 * 2014-10: PrestaShop 1.6.0.9 - EAN*Amount*Price
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

@ini_set('max_execution_time', 0);
/** correct Mac error on eof */
@ini_set('auto_detect_line_endings', '1');

class AktCsv extends Module
{
    private $_html = ''; // used to store the html output for the back-office

    const EAN_STOCK_NET = 4;    //EAN13*Stock*PriceNet
    const PRICE_ONLY    = 3;
    const PRICE_STOCK   = 2;
    const STOCK_ONLY    = 1;

    const PRICE_NET     = 0;
    const PRICE_GROSS   = 1;

    function __construct()
    {
        $this->bootstrap = true; //Ps 1.6
        $this->name = 'aktcsv';
        $this->tab = 'Others';
        $this->version = '3.141015';
        $this->author = 'LPP';

        parent::__construct();

        $this->page = basename(__FILE__, '.php');
        $this->displayName = $this->l('Aktualizacja sklepu z pliku CSV');
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
        Configuration::updateValue($this->name . '_SEPARATOR', '*');
        Configuration::updateValue($this->name . '_NUMER', 'ean13');
        Configuration::updateValue($this->name . '_MARZA', '0');
        Configuration::updateValue($this->name . '_MARZAPLUS', '0');
        Configuration::updateValue($this->name . '_LIMIT', '1');
        Configuration::updateValue($this->name . '_FILTR1', '');
        Configuration::updateValue($this->name . '_CSVFILE', '');
        Configuration::updateValue($this->name . '_GROSS', '0');
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
        ) && Configuration::deleteByName(
            $this->name . '_GROSS'
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

        $this->_html .= '<div style="max-width: 500px;">
<form class="form-horizontal" role="form" method="post" action="' . $_SERVER['REQUEST_URI'] . '" enctype="multipart/form-data">
<div class="panel">
    <div class="panel-heading">
    <i class="icon-file"></i>
    '. $this->l('Wgraj plik CSV') . '
    </div>
    <div class="alert alert-info">'. $this->l('Obslugiwane formaty pliku CSV') . ':
        <p>' . $this->l('(index; nazwa; cena; ilość)') . '</p>
        <p>' . $this->l('(index; ilość)').'</p>
        <p>' . $this->l('(index; cena)').'</p>
        <p>' . $this->l('(EAN*ilość*cena netto)') . '</p>
    </div>
    <input type="hidden" name="MAX_FILE_SIZE" value="20000000" />
    <input type="file" name="csv_filename" />
    <div class="panel-footer">
        <button name="submit_csv" class="btn btn-default pull-right" type="submit"><i class="process-icon-save"></i> ' . $this->l('Wyślij ten plik na serwer') . '</button>
    </div>
</div>
</form>

<br />
<br />

<form class="form-horizontal" role="form" method="post"  action="' . $_SERVER['REQUEST_URI'] . '">
<div class="panel">
    <div class="panel-heading">
    <i class="icon-money"></i>
    ' . $this->l('Główne funkcje modułu') . '
    </div>

    <div class="alert alert-info">' . $this->l('Aktualizacja przeprowadzona będzie z pliku:') . ' ' . Configuration::get($this->name . '_CSVFILE') . '
    </div>

<div class="form-group">
<label class="control-label required" for="separator">
<span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="' . $this->l('Wazne! Jaki separator w CSV').'">
' . $this->l('Separator pól w pliku *.csv') . '
</span>
</label>
<input class="form-control" type="text" name="separator" value="' . Configuration::get($this->name . '_SEPARATOR') . '">
</div>
    <br />
<div class="form-group">
    <label class="control-label required" for="numer">
    <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="' . $this->l('Wazne! Co aktualizujemy').'">
    ' . $this->l('Rodzaj aktualizacji') . '
    </span>
    </label>
    <select class="form-control" name="rodzaj_aktualizacji">
      <option value="2"'.((Configuration::get($this->name . '_RODZAJAKTUALIZACJI') == "2") ? ' selected="selected"' : '') .'>Ceny i stany</option>
      <option value="1"'.((Configuration::get($this->name . '_RODZAJAKTUALIZACJI') == "1") ? ' selected="selected"' : '') .'>Tylko stany</option>
      <option value="3"'.((Configuration::get($this->name . '_RODZAJAKTUALIZACJI') == "3") ? ' selected="selected"' : '') .'>Tylko ceny</option>
      <option value="4"'.((Configuration::get($this->name . '_RODZAJAKTUALIZACJI') == "4") ? ' selected="selected"' : '') .'>EAN*ilosc*cena netto</option>
    </select>
</div>
<br />
<div class="form-group">
    <label class="control-label required" for="numer">
    <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="' . $this->l('Wazne! Wg jakiego klucza szukac produktu w bazie').'">
    ' . $this->l('Wybierz numeru 1 kol.') . '
    </span>
    </label>
    <select class="form-control" name="numer">
      <option value="supplier_reference">' . $this->l('Nr ref. dostawcy') . '</option>
      <option value="reference">' . $this->l('Kod produktu') . '</option>
      <option value="ean13" selected="selected">EAN13</option>
    </select>

</div>
<br/>
<div class="form-group">
<label class="control-label required" for="marza">
<span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="Procent kwoty, jaki zostanie dodany do ceny">
' . $this->l('Marża w procentach') . '
</span>
</label>
    <input class="form-control" type="number" name="marza" value="' . Configuration::get($this->name . '_MARZA') . '">
</div>
            <br />
<div class="form-group">
<label class="control-label required" for="marza_plus">
<span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="Kwota, ktora zostanie dodana po uwzglednieniu procentowej marzy">
' . $this->l('Dodatkowa marża kwotowa') . '
</span>
</label>
<input class="form-control" type="number" step="0.01" name="marza_plus" value="' . Configuration::get($this->name . '_MARZAPLUS') . '">
</div>
            <br />
<div class="form-group">
    <label class="control-label required" for="gross">
        <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="Brutto (wyliczy netto) lub netto.">
        ' . $this->l('Ceny produktów') . '
        </span>
    </label>
    <select class="form-control" name="gross">
      <option value="1" selected="selected">' . $this->l('Brutto') . ' (Netto dla EAN13)</option>
      <option value="0" disabled>' . $this->l('Netto') . '</option>
  </select>
  </div>
  <br />
      <div class="form-group">
          <div class="checkbox disabled">
              <label>
                    <input class="" type="checkbox" name="zerowanie" value="tak" />' . $this->l('Zerować stany i ceny?') . ' (Nie przetestowana)
              </label>
        </div>
      </div>
    <br />
    <div class="form-group">
        <div class="checkbox">
         <label>
            <input class="" type="checkbox" name="atrybuty" value="tak" checked="checked" />' . $this->l('Mam w bazie produkty z atrybutami') . '
         </label>
        </div>
    </div>
        <br />

<h3>' . $this->l('Opcje tworzenia pliku z brakującymi produktami') . '</h3>
<div class="form-group">
    <div class="checkbox">
         <label>
            <input type="checkbox" name="productNotInDB" value="tak" checked="checked" /> ' . $this->l(
                        'Sprawdzać produkty których nie ma w sklepie a są w pliku *csv?' ) . '
         </label>
    </div>
</div>
<br />
    <div class="form-group">
        <label class="control-label required" for="limit">
        <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="Jaki limit sztuk na magazynie?">
        ' . $this->l('Jaki limit sztuk na magazynie?') . '
        </span>
        </label>
        <input type="text" name="limit" value="' . Configuration::get($this->name . '_LIMIT') . '">
    </div>
    <br />
    <div class="form-group">
        <label class="control-label required" for="filtr1">
        <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="Filtr do wyszukiwania ... produktow">
        ' . $this->l('Filtr 1 Co wyszukujemy?') . '
        </span>
        </label>
            <input type="text" name="filtr1" value="' . Configuration::get($this->name . '_FILTR1') . '">
    </div>
            <br />
            <br/>
<h3>' . $this->l('Id_shop, w którym chcesz dokonac aktualizacji') . '</h3>
<div class="form-group">
    <label class="control-label required" for="id_shop">
        <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="W ktorym sklepie zrobic aktualizacje? Ignoruj jesli nie uzywasz Multistore.">
        ' . $shop_name . '
    </label>
    <input type="text" name="id_shop" value="' . $id_shop . '">
</div>
<br />
<div class="panel-footer">
    <input type="submit" name="submit_update" value="' . $this->l('Przeprowadź aktualizację') . '" class="buttonFinish btn btn-success" />
     ' . $this->l('Może trochę potrwać! - bądź cierpliwy...') . '
</div>
</div>
</form>

<br />
<br />

<div class="panel">
<div class="panel-heading">
' . $this->l('Log file') . '
</div>
    <p>' . $this->l('Ostatnio wygenerowany plik z brakującymi produktam:') . ' '
        . '<b><a style="text-decoration: underline;" href="' . _MODULE_DIR_ . 'aktcsv/missed_products.txt">missed_products.txt</a></b>
    </p>
    <p>' . $this->l('Jesli nie mozna otworzyc pliku, to musisz uzyc FTP.') . '</p>
</div>

</div><!-- max-width-->
<br />
<br />
<br />

<div class="panel panel-info">
  <div class="panel-heading"><i class="icon-lightbulb"></i>
    ' . $this->l('Informacje') .'</div>
  <div class="panel-body">
    <p style="text-align:center;">Potrzebujesz pomocy, modyfikacji?<br />
 PS 1.5.6.2: <b><a href="mailto:leszek.pietrzak@gmail.com">Leszek.Pietrzak@gmail.com</a></b><br />
    </p>
    <br />
    <p>' . $this->l('Moduł ten aktualizuje ceny oraz stany magazynowe z pliku *.csv.') .'
    <br />
    ' . $this->l('Produkty w bazie rozpoznawane są po numerze referencyjnym dostawcy, kodzie produktu lub kodzie EAN13.') .'
     <br />
     ' . $this->l('Skrypt tworzy plik o nazwie `missed_products.txt` w którym zapisywane są produkty znajdujące się w pliku csv
    a ktorych nie ma w bazie sklepu. Dodatkowo produkty zapisywane do tego pliku możemy ograniczyć do produktów
    zawierających w nazwie określone slowa (wpisując je w pola "Filtr_1"), oraz tylko do produktów o ilości
    nie mniejszej niż wpisana w pole `Limit sztuk na magazynie`') .'.</p>
    <p>
    ' . $this->l('Moduł można szybko dostosować do indywidualnych potrzeb.') .'
    </p>
  </div>
  </div>';
        return $this->_html;
    }

    private function _uploadCSVFile()
    {
        $error = '';
        if (isset($_FILES['csv_filename']) && !empty($_FILES['csv_filename']['error']))
        {
            switch ($_FILES['csv_filename']['error'])
            {
                case UPLOAD_ERR_INI_SIZE:
                    $error = Tools::displayError('The uploaded file exceeds the upload_max_filesize directive in php.ini. If your server configuration allows it, you may add a directive in your .htaccess.');
                    break;
                case UPLOAD_ERR_FORM_SIZE:
                    $error = Tools::displayError('The uploaded file exceeds the post_max_size directive in php.ini.
						If your server configuration allows it, you may add a directive in your .htaccess.');
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error = Tools::displayError('The uploaded file was only partially uploaded.');
                    break;
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error = Tools::displayError('No file was uploaded.');
                    break;
                    break;
            }
            $this->_html .= $error;
        } else {

        $uploadedFile = $_FILES['csv_filename'];
        if (isset($uploadedFile['name'])) {
            move_uploaded_file($uploadedFile['tmp_name'], '../modules/' . 'aktcsv/' . $uploadedFile['name']);
            Configuration::updateValue($this->name . '_CSVFILE', $uploadedFile['name']);
        }
        $this->_html .= $this->displayConfirmation(
            'Wgrano plik: '
            . '<b>"' . Configuration::get($this->name . '_CSVFILE') . '"</b>,'
            . ' size: <b>' . $_FILES['csv_filename']['size'] . '</b> bytes.<br />'
        );
        Logger::addLog('AktCSV: CSV Uploaded: '.Configuration::get($this->name . '_CSVFILE'));
        }
    }

    private function _updateDB()
    {
        $codeStart = microtime(true);

        $separator = ($separator = Tools::substr(strval(trim(Tools::getValue('separator'))), 0, 1)) ? $separator :  ';';
        Configuration::updateValue($this->name . '_SEPARATOR', $separator);
        $numer = Tools::getValue("numer");
        Configuration::updateValue($this->name . '_NUMER', $numer);
        $marza = Tools::getValue("marza");
        Configuration::updateValue($this->name . '_MARZA', $marza);
        $marza_plus = Tools::getValue("marza_plus");
        Configuration::updateValue($this->name . '_MARZAPLUS', $marza_plus);
        $gross = Tools::getValue("gross");
        Configuration::updateValue($this->name . '_GROSS', $gross);
        $limit = Tools::getValue("limit");
        Configuration::updateValue($this->name . '_LIMIT', $limit);
        $filtr1 = Tools::getValue("filtr1");
        Configuration::updateValue($this->name . '_FILTR1', $filtr1);
        $updateMode = (int)Tools::getValue("rodzaj_aktualizacji");
        Configuration::updateValue($this->name . '_RODZAJAKTUALIZACJI', $updateMode);


        $productNotInDB = (Tools::getValue("productNotInDB") == "tak") ? 1 : 0;
        $zerowanie = (Tools::getValue("zerowanie") == "tak") ? 1 : 0;
        $attributes = (Tools::getValue("atrybuty") == "tak") ? 1 : 0;
        $id_shop = (int)Tools::getValue("id_shop");

        $handleCSVFile = fopen('../modules/aktcsv/' . Configuration::get($this->name . '_CSVFILE'), 'r');
        $handleNotInDB = fopen('../modules/aktcsv/missed_products.txt', 'w');

        $wpisow = 0;
        $zmian_p = 0;
        $znalezionych_p = 0;
        $foundProductsWithAttribute = 0;
        $dopliku = 0;
        $counter = 0;
        $log = '';

        //TODO: TEST reset stocks
        if ($zerowanie) {
            $this->clearStocks();
            $this->clearStockAvailable();
            if ($attributes) $this->clearStocksWithAttributes();
        }


        while (($data = fgetcsv($handleCSVFile, 0, $separator)) !== false) {
            $wpisow++;

            $reference = $data[0];

            switch ($updateMode) {
                case self::PRICE_ONLY:
                    $price = $this->_clearCSVPrice($data[1]);
                    $price = $this->calculateFinalPrice($price, $marza, $marza_plus);
                    break;
                case self::STOCK_ONLY:
                    $quantity = $this->_clearCSVIQuantity($data[1]);
                    break;
                case self::PRICE_STOCK:
                    $quantity = $this->_clearCSVIQuantity($data[3]);
                    $price = $this->_clearCSVPrice($data[2]);
                    $price = $this->calculateFinalPrice($price, $marza, $marza_plus);
                    break;
                case self::EAN_STOCK_NET:
                    $quantity = $this->_clearCSVIQuantity($data[1]);
                    $price = $this->_clearCSVPrice($data[2]);
                    $price = $this->calculateFinalPrice($price, $marza, $marza_plus);
                    $gross = self::PRICE_NET;
                    break;
                default:
                    exit('No implemented');
                    break;
            }

            //Product without attribute
            $idProduct = $this->isProductInDB($numer, $reference);

            if ($idProduct > 0) {
                $znalezionych_p++;

                if ($updateMode == self::STOCK_ONLY || $updateMode == self::PRICE_STOCK || $updateMode == self::EAN_STOCK_NET) {
                    StockAvailable::setQuantity($idProduct, 0, $quantity, $id_shop);
                    $this->_updateProductWithOutAttribute($numer, $reference, null, $quantity);
                }
                if ($updateMode == self::PRICE_ONLY || $updateMode == self::PRICE_STOCK || $updateMode == self::EAN_STOCK_NET) {
                    if ($gross == self::PRICE_GROSS) {
                        $taxRate = $this->_getTaxRate($idProduct, $id_shop);
                        $priceNet = $this->_calculateAndFormatNetPrice($price, $taxRate);
                    } else {
                        $priceNet = $price;
                    }

                    $this->_updateProductPriceInShop($priceNet, $idProduct, $id_shop);
                    $this->_updateProductWithOutAttribute($numer, $reference, $priceNet, null);
                }
            }

            //Product with attribute
            if ($attributes == 1 && !empty($reference)) {
                $productWithAttribute = $this->isProductWithAttributeInDB($numer, $reference);

                if (!empty($productWithAttribute) && $productWithAttribute['id_product'] > 0) {
                    $foundProductsWithAttribute++;
                    $idProductWithAttribute = $productWithAttribute['id_product'];
                    $idProductAttribute = $productWithAttribute['id_product_attribute'];

                    if ($updateMode == self::STOCK_ONLY || $updateMode == self::PRICE_STOCK || $updateMode == self::EAN_STOCK_NET) {
                        StockAvailable::setQuantity($idProduct, $idProductAttribute, $quantity, $id_shop);
                        $this->_updateProductWithAttribute($numer, $reference, null, $quantity);
                    }

                    if ($updateMode == self::PRICE_ONLY || $updateMode == self::PRICE_STOCK || $updateMode == self::EAN_STOCK_NET) {
                        if ($gross == self::PRICE_GROSS) {
                            $taxRate = $this->_getTaxRate($idProductWithAttribute, $id_shop);
                            $priceNet = $this->_calculateAndFormatNetPrice($price, $taxRate);
                        } else {
                            $priceNet = $price;
                        }

                        $this->_updateProductPriceInShop($priceNet, $idProductWithAttribute, $id_shop);
                        $this->_updateProductWithAttribute($numer, $reference, $priceNet, null);
                    }

                }
            }

            if ($productNotInDB == 1) {
                //search using filtr_1
                if ($idProduct == '' && $idProductWithAttribute == '') { // nie znaleziono produktu ani bez Atr, ani z Atrybutem
                    if ((($filtr1 == "") && ($price != "0.00") && ($quantity >= $limit)) or
                        (($filtr1 != "") && (strpos($quantity, $filtr1, 0) !== false)
                            && ($price != "0.00") && ($quantity >= $limit))
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
            } else { //legacy code to be removed
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
            $filtr1 = $this->l('Wszystkie brakujące pozycje');
        }
        if ($productNotInDB != 1) {
            $filtr1 = $this->l('Zablokowaleś sprawdzanie produktów.');
        }

        $codeEnd = microtime(true);
        $elapsedTime = round($codeEnd - $codeStart, 2);

        $this->_html .= $this->displayConfirmation(
            '<h4>Success</h4>
             Products quantity in file ' . Configuration::get($this->name . '_CSVFILE') . ':'
            . ' <b>' . $wpisow . '</b><br/>Found products: <b>' . $znalezionych_p . '</b><br />Found product with attribute: <b>' . $foundProductsWithAttribute . '</b><br />'
            . 'Set profit: <b>' . $marza . '%.</b><br/>Execution time: <b>' . $elapsedTime . '</b> seconds<br/>'
            . 'In file "missed_products.txt" I wrote: <b>' . $filtr1 . '</b> (number of records: <b>' . $dopliku . '</b>).<br/>'
        );
    }

    private function _clearCSVPrice($priceToClear)
    {
        $price = str_replace(",", ".", $priceToClear);
        return str_replace(" ", "", $price);
    }

    private function _clearCSVIQuantity($amountToClear)
    {
         return str_replace(">", "", $amountToClear);
    }

    private function _calculateAndFormatNetPrice($price, $taxRate)
    {
        $priceGross = number_format($price, 2, ".", "");
        $taxRate = ($taxRate + 100) / 100;
        return number_format(($priceGross / $taxRate), 2, ".", "");
    }

    private function calculateFinalPrice($price, $marza, $marza_plus)
    {
        $price += $price * ($marza / 100);
        $price += $marza_plus;
        return $price;
    }

    private function _getTaxRate($idProduct, $id_shop)
    {
        return DB::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
                SELECT '._DB_PREFIX_.'_tax.rate
                FROM '._DB_PREFIX_.'_tax, '._DB_PREFIX_.'_product_shop
                WHERE '._DB_PREFIX_.'_product_shop.id_product = ' . $idProduct . '
                AND '._DB_PREFIX_.'__product_shop.id_shop=' . $id_shop . '
                AND '._DB_PREFIX_.'_tax.id_tax = ps_product_shop.id_tax_rules_group
        ');

        /*
        $idTax = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'product_shop WHERE id_product = \'' . $idProduct . '\' AND id_shop=\'' . $id_shop . '\' '
        );
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT rate FROM ' . _DB_PREFIX_ . 'tax WHERE id_tax = \'' . $idTax . '\' '
        );
        */
    }

    private function _updateProductPriceInShop($priceNet, $idProduct, $id_shop)
    {
        return Db::getInstance()->update('product_shop', array(
                'price' => $priceNet,
                'date_upd' => date("Y-m-d H:i:s"),
            ), 'id_product = \'' . $idProduct . '\'  AND id_shop = \'' . $id_shop . '\' '
        );
    }

    private function _updateProductWithAttribute($numer, $ref, $priceNet = null, $quantity = null)
    {
        $values = array();
        if (!is_null($quantity)) {
            $values['quantity'] = $quantity;
        }
        if (!is_null($priceNet)) {
            $values['price'] = $priceNet;
        }

        return Db::getInstance()->update('product_attribute', $values, $numer . ' = \'' . $ref . '\' ');
    }

    private function _updateProductWithOutAttribute($numer, $ref, $priceNet = null, $quantity = null)
    {
        $values = array();
        if (!is_null($quantity)) {
            $values['quantity'] = $quantity;
        }
        if (!is_null($priceNet)) {
            $values['price'] = $priceNet;
        }
        return Db::getInstance()->update('product', $values, $numer . ' = \'' . $ref . '\' ');
    }

    /**
     * @param $numer
     * @param $reference
     * @return array
     */
    private function isProductWithAttributeInDB($numer, $reference)
    {
        $reference = trim($reference);
        if (empty($reference)) return 0;

        $productWithAttribute = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            'SELECT id_product, id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute`'
            . ' WHERE `' . $numer . '`=\'' . $reference . '\'',
            0
        );
        return $productWithAttribute;
    }

    /**
     * @param $numer
     * @param $reference
     * @return int
     */
    private function isProductInDB($numer, $reference)
    {
        $reference = trim($reference);
        if (empty($reference)) return 0;

        $idProduct = (int)Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            "SELECT id_product FROM `" . _DB_PREFIX_ . "product` WHERE `" . $numer . "`='" . $reference . "'",
            0
        );
        return $idProduct;
    }

    private function clearStocks()
    {
        $values['quantity'] = 0;

        return Db::getInstance()->update('product', $values, '', 0, false, false);
    }

    private function clearStocksWithAttributes()
    {
        $values['quantity'] = 0;

        return Db::getInstance()->update('product_attribute', $values, '', 0, false, false);
    }

    private function clearStockAvailable()
    {
        $values['quantity'] = 0;

        return Db::getInstance()->update('stock_available', $values, '', 0, false, false);
    }

//product_shop 	Product shop associations 	id_product, id_shop

//Not used:
//Product attribute shop associations
//product_attribute_shop.`price`

}
