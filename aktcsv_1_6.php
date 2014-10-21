<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

@ini_set('max_execution_time', 0);
/** correct Mac error on eof */
@ini_set('auto_detect_line_endings', '1');

class AktCsv extends Module
{
    private $_html = ''; // used to store the html output for the back-office

    const EAN_STOCK_PRICE = 4;    //EAN13*Stock*Price
    const PRICE_ONLY    = 3;
    const PRICE_STOCK   = 2;
    const STOCK_ONLY    = 1;

    const PRICE_NET     = 0;
    const PRICE_GROSS   = 1;

    function __construct()
    {
        $this->name = 'aktcsv';
        $this->tab = 'Others';
        $this->version = '4.141016';
        $this->author = 'LPP';

        $this->ps_versions_compliancy = array('min' => '1.5', 'max' => '1.6');
        $this->bootstrap = true; //Ps 1.6

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
        Configuration::updateValue($this->name . '_PROFIT', '0');
        Configuration::updateValue($this->name . '_PROFITPLUS', '0');
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
            $this->name . '_PROFIT'
        ) && Configuration::deleteByName($this->name . '_PROFITPLUS') && Configuration::deleteByName(
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
        <p>' . $this->l('(EAN*ilość*cena)') . '</p>
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
      <option value="4"'.((Configuration::get($this->name . '_RODZAJAKTUALIZACJI') == "4") ? ' selected="selected"' : '') .'>EAN*ilosc*cena</option>
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
<label class="control-label required" for="profit">
<span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="Procent kwoty, jaki zostanie dodany do ceny">
' . $this->l('Marża w procentach') . '
</span>
</label>
    <input class="form-control" type="number" name="profit" value="' . Configuration::get($this->name . '_PROFIT') . '">
</div>
            <br />
<div class="form-group">
<label class="control-label required" for="profit_plus">
<span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="Kwota, ktora zostanie dodana po uwzglednieniu procentowej marzy">
' . $this->l('Dodatkowa marża kwotowa') . '
</span>
</label>
<input class="form-control" type="number" step="0.01" name="profit_plus" value="' . Configuration::get($this->name . '_PROFITPLUS') . '">
</div>
            <br />
<div class="form-group">
    <label class="control-label required" for="gross">
        <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="Brutto (wyliczy netto) lub netto.">
        ' . $this->l('Ceny produktów') . '
        </span>
    </label>
    <select class="form-control" name="gross">
      <option value="1" selected="selected">' . $this->l('Brutto') . '</option>
      <option value="0">' . $this->l('Netto') . '</option>
  </select>
  </div>
  <br />
      <div class="form-group">
          <div class="checkbox disabled">
              <label>
                    <input class="" type="checkbox" name="clearStocksMode" value="1" />' . $this->l('Zerować stany i ceny?') . ' (Nie przetestowana)
              </label>
        </div>
      </div>
    <br />
    <div class="form-group">
        <div class="checkbox">
         <label>
            <input class="" type="checkbox" name="atrybuty" value="1" checked="checked" />' . $this->l('Mam w bazie produkty z atrybutami') . '
         </label>
        </div>
    </div>
        <br />

<h3>' . $this->l('Opcje tworzenia pliku z brakującymi produktami') . '</h3>
<div class="form-group">
    <div class="checkbox">
         <label>
            <input type="checkbox" name="logProductsNotInDB" value="1" checked="checked" /> ' . $this->l(
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
 PS 1.5.6.2, 1.6.0.9: <b><a href="mailto:leszek.pietrzak@gmail.com">Leszek.Pietrzak@gmail.com</a></b><br />
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
            move_uploaded_file($uploadedFile['tmp_name'], '../modules/aktcsv/import/' . $uploadedFile['name']);
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
        $profit = Tools::getValue("profit");
        Configuration::updateValue($this->name . '_PROFIT', $profit);
        $profit_plus = Tools::getValue("profit_plus");
        Configuration::updateValue($this->name . '_PROFITPLUS', $profit_plus);
        $gross = Tools::getValue("gross");
        Configuration::updateValue($this->name . '_GROSS', $gross);
        $limit = Tools::getValue("limit");
        Configuration::updateValue($this->name . '_LIMIT', $limit);
        $filtr1 = Tools::getValue("filtr1");
        Configuration::updateValue($this->name . '_FILTR1', $filtr1);
        $updateMode = (int)Tools::getValue("rodzaj_aktualizacji");
        Configuration::updateValue($this->name . '_RODZAJAKTUALIZACJI', $updateMode);


        $logProductsNotInDB = (Tools::getValue("logProductsNotInDB") == "1") ? 1 : 0;
        $clearStocksMode = (Tools::getValue("clearStocksMode") == "1") ? 1 : 0;
        $attributes = (Tools::getValue("atrybuty") == "1") ? 1 : 0;
        $id_shop = (int)Tools::getValue("id_shop");

        $handleCSVFile = fopen('../modules/aktcsv/import/' . Configuration::get($this->name . '_CSVFILE'), 'r');
        $handleNotInDB = fopen('../modules/aktcsv/log/missed_products.txt', 'w');

        $countProductsInCSV = 0;
        $countFoundProducts = 0;
        $foundProductsWithAttribute = 0;
        $countMissedProducts = 0;
        $counter = 0;
        $log = '';

        //TODO: TEST reset stocks
        if ($clearStocksMode) {
            $this->clearStocks();
            $this->clearStockAvailable();
            if ($attributes) $this->clearStocksWithAttributes();
        }


        while (($data = fgetcsv($handleCSVFile, 0, $separator)) !== false) {
            $countProductsInCSV++;

            $reference = $data[0];

            switch ($updateMode) {
                case self::PRICE_ONLY:
                    $price = $this->_clearCSVPrice($data[1]);
                    $price = $this->calculateFinalPrice($price, $profit, $profit_plus);
                    break;
                case self::STOCK_ONLY:
                    $quantity = $this->_clearCSVIQuantity($data[1]);
                    break;
                case self::PRICE_STOCK:
                    $quantity = $this->_clearCSVIQuantity($data[3]);
                    $price = $this->_clearCSVPrice($data[2]);
                    $price = $this->calculateFinalPrice($price, $profit, $profit_plus);
                    break;
                case self::EAN_STOCK_PRICE:
                    $quantity = $this->_clearCSVIQuantity($data[1]);
                    $price = $this->_clearCSVPrice($data[2]);
                    $price = $this->calculateFinalPrice($price, $profit, $profit_plus);
                    break;
                default:
                    exit('No implemented');
                    break;
            }


            //Product without attribute
            $idProduct = $this->isProductInDB($numer, $reference);

            if ($idProduct > 0) {
                $countFoundProducts++;

                if ($updateMode != self::PRICE_ONLY) {
                    StockAvailable::setQuantity($idProduct, 0, $quantity, $id_shop);
                    $this->_updateProductWithOutAttribute($numer, $reference, null, $quantity);
                }
                if ($updateMode != self::STOCK_ONLY) {
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

                    if ($updateMode != self::PRICE_ONLY) {
                        StockAvailable::setQuantity($idProduct, $idProductAttribute, $quantity, $id_shop);
                        $this->_updateProductWithAttribute($numer, $reference, null, $quantity);
                    }

                    if ($updateMode != self::STOCK_ONLY) {
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


            if ($logProductsNotInDB == 1) {

                if (($attributes == 0 && $idProduct == 0 ) ||
                   ($attributes == 1 && $idProduct == 0 && empty($productWithAttribute))) {
                    $countMissedProducts++;
                    $this->logMissedProduct($handleNotInDB, $reference, $price, $quantity);
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
        if ($logProductsNotInDB != 1) {
            $filtr1 = $this->l('Zablokowaleś sprawdzanie produktów.');
        }

        $codeEnd = microtime(true);
        $elapsedTime = round($codeEnd - $codeStart, 2);

        $this->_html .= $this->displayConfirmation(
            '<h4>Success</h4>
             ' . $this->l('Products in file') . ' ' . Configuration::get($this->name . '_CSVFILE') . ':'. ' <b>' . $countProductsInCSV . '</b>
            <br/>' . $this->l('Products found in DB') . ': <b>' . $countFoundProducts . '</b><br />
            ' . $this->l('Products with attribute found in DB') . ': <b>' . $foundProductsWithAttribute . '</b><br />'
            . $this->l('Set profit') . ': <b>' . $profit . '%, profit(plus): '.$profit_plus.'</b>.<br/>
            ' . $this->l('Execution time') . ': <b>' . $elapsedTime . '</b> seconds<br/>'
            .  $this->l('In file "missed_products.txt": filtr') . '= <b>' . $filtr1 . '</b>, Nnumber of records: <b>' . $countMissedProducts . '</b>.<br/>'
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

    private function calculateFinalPrice($price, $profit, $profit_plus)
    {
        $price += $price * ($profit / 100);
        $price += $profit_plus;
        return $price;
    }

    private function _getTaxRate($idProduct, $id_shop)
    {
        return DB::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
                SELECT '._DB_PREFIX_.'tax.rate
                FROM '._DB_PREFIX_.'tax, '._DB_PREFIX_.'product_shop
                WHERE '._DB_PREFIX_.'product_shop.id_product = ' . $idProduct . '
                AND '._DB_PREFIX_.'product_shop.id_shop=' . $id_shop . '
                AND '._DB_PREFIX_.'tax.id_tax = '._DB_PREFIX_.'product_shop.id_tax_rules_group
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

    /**
     * @param $handleNotInDB
     * @param $reference
     * @param $price
     * @param $quantity
     */
    private function logMissedProduct($handleNotInDB, $reference, $price, $quantity)
    {
        fwrite($handleNotInDB, "\n\r");
        fwrite(
            $handleNotInDB,
            'Nieznaleziony produkt w bazie: $numer = ' . $reference . '. Nowa cena - ' . $price . ' i ilość - ' . $quantity
        );

        @ob_flush();
        @flush();
    }

//product_shop 	Product shop associations 	id_product, id_shop

//Not used:
//Product attribute shop associations
//product_attribute_shop.`price`

}
