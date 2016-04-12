<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

@ini_set('max_execution_time', 0);
/** correct Mac error on eof */
@ini_set('auto_detect_line_endings', '1');

class AktCsv extends Module
{
    const PRICE_ONLY = 3;
    const PRICE_STOCK = 2;
    const STOCK_ONLY = 1;
    const PRICE_NET = 0;
    const PRICE_GROSS = 1;
    private $_html = '';
    protected $column_mask = array();
    protected $countMissedProducts = 0;
    protected $db;

    function __construct()
    {
        $this->name = 'aktcsv';
        $this->tab = 'Others';
        $this->version = '4.160410'; //Smolensk edition
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
        Configuration::updateValue($this->name . '_TYPEVALUE0', 'index');
        Configuration::updateValue($this->name . '_TYPEVALUE1', 'name');
        Configuration::updateValue($this->name . '_TYPEVALUE2', 'price');
        Configuration::updateValue($this->name . '_TYPEVALUE3', 'stock');
        Configuration::updateValue($this->name . '_CUSTOMID', '0');        

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
            $this->name . '_TYPEVALUE1'
        ) && Configuration::deleteByName(
            $this->name . '_TYPEVALUE2'
        ) && Configuration::deleteByName(
            $this->name . '_TYPEVALUE3'
        ) && Configuration::deleteByName(
            $this->name . '_TYPEVALUE0'
        ) && Configuration::deleteByName(
            $this->name . '_GROSS'
        )&& Configuration::deleteByName(
            $this->name . '_CUSTOMID'
        );
    }

    //Backoffice "Configure/Konfiguruj"
    public function getContent()
    {
        //TODO: Check module update

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

    private function _uploadCSVFile()
    {
        $error = '';
        if (isset($_FILES['csv_filename']) && !empty($_FILES['csv_filename']['error'])) {
            switch ($_FILES['csv_filename']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                    $error = Tools::displayError(
                        'The uploaded file exceeds the upload_max_filesize directive in php.ini. If your server configuration allows it, you may add a directive in your .htaccess.'
                    );
                    break;
                case UPLOAD_ERR_FORM_SIZE:
                    $error = Tools::displayError(
                        'The uploaded file exceeds the post_max_size directive in php.ini.
						If your server configuration allows it, you may add a directive in your .htaccess.'
                    );
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
            Logger::addLog('AktCSV: CSV Uploaded: ' . Configuration::get($this->name . '_CSVFILE'));
        }
    }

    /**
     *
     */
    private function _updateDB()
    {
        $codeStart = microtime(true);

        $this->db = DB::getInstance();

        $separator = ($separator = Tools::substr(strval(trim(Tools::getValue('separator'))), 0, 1)) ? $separator : ';';
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
        $custom_id= Tools::getValue("custom_id");
        Configuration::updateValue($this->name . '_CUSTOMID', $custom_id);

        $this->receiveTab();

        $logProductsNotInDB = (Tools::getValue("logProductsNotInDB") == "1") ? 1 : 0;
        $clearStocksMode = (Tools::getValue("clearStocksMode") == "1") ? 1 : 0;
        $attributes = (Tools::getValue("atrybuty") == "1") ? 1 : 0;
        Configuration::updateValue($this->name . '_ATRYBUTY', $attributes);
        $id_shop = (int)Tools::getValue("id_shop");

        $handleCSVFile = fopen('../modules/aktcsv/import/' . Configuration::get($this->name . '_CSVFILE'), 'r');

        if ($handleCSVFile === FALSE) {
            die("Nie mozna otworzyc pliku CSV: " . Configuration::get($this->name . '_CSVFILE') . " w katalogu /modules/aktcsv/import/");
        }

        $handleNotInDB = $this->openLogFile();

        $countProductsInCSV = 0;
        $countFoundProducts = 0;
        $foundProductsWithAttribute = 0;
        $counter = 0;

        //TODO: TEST reset stocks
        //1.6. OK
        //1.5.6.2 - need tests
        if ($clearStocksMode) {
            $this->clearStocks();
            $this->clearStockAvailable();
            if ($attributes) {
                $this->clearStocksWithAttributes();
            }
        }


        while (($line = fgetcsv($handleCSVFile, 0, $separator)) !== false) {
            $countProductsInCSV++;

            $info = $this->getMaskedRow($line);
            $reference = $info['index'];
            if (isset($info['price'])) {
                $price = $this->_clearCSVPrice($info['price']);
                $price = $this->calculateFinalPrice($price, $profit, $profit_plus);
            } else {
                $price = null;
            }
            if (isset($info['stock'])) {
                $quantity = $this->_clearCSVIQuantity($info['stock']);
            } else {
                $quantity = null;
            }


            //Product without attribute
            $idProduct = $this->isProductInDB($numer, $reference);

            if ($idProduct > 0) {
                $countFoundProducts++;                

                if($custom_id == 1)
                {                   
                    //Product without customid column in attribute table 
                    $idProductAttribute = $this->getProductAttribute($idProduct);

                    if (!is_null($quantity)) {
                      StockAvailable::setQuantity($idProduct, $idProductAttribute, $quantity, $id_shop);
                    }
                } 
                else
                {
                    $idProductAttribute = 0;

                    if (!is_null($quantity)) {
                        StockAvailable::setQuantity($idProduct, $idProductAttribute, $quantity, $id_shop);
                        $this->_updateProductWithOutAttribute($numer, $reference, null, $quantity);
                    }
                    if (!is_null($price)) {
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
            }

            //Product with attribute
            if ($attributes == 1 && !empty($reference)) {
                $productWithAttribute = $this->isProductWithAttributeInDB($numer, $reference);

                if (!empty($productWithAttribute) && $productWithAttribute['id_product'] > 0) {
                    $foundProductsWithAttribute++;
                    $idProductWithAttribute = $productWithAttribute['id_product'];
                    $idProductAttribute = $productWithAttribute['id_product_attribute'];

                    if (!is_null($quantity)) {
                        StockAvailable::setQuantity($idProductWithAttribute, $idProductAttribute, $quantity, $id_shop);
                        $this->_updateProductWithAttribute($numer, $reference, null, $quantity);
                    }

                    if (!empty($price)) {
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

                if (($attributes == 0 && $idProduct == 0) ||
                    ($attributes == 1 && $idProduct == 0 && empty($productWithAttribute))
                ) {
                    $this->logMissedProduct($handleNotInDB, $numer, $info, $filtr1);
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
             ' . $this->l('Products in file') . ' ' . Configuration::get(
                $this->name . '_CSVFILE'
            ) . ':' . ' <b>' . $countProductsInCSV . '</b>
            <br/>' . $this->l('Products found in DB') . ': <b>' . $countFoundProducts . '</b><br />
            ' . $this->l('Products with attribute found in DB') . ': <b>' . $foundProductsWithAttribute . '</b><br />'
            . $this->l('Set profit') . ': <b>' . $profit . '%, profit(plus): ' . $profit_plus . '</b>.<br/>
            ' . $this->l('Execution time') . ': <b>' . $elapsedTime . '</b> seconds<br/>'
            . $this->l('In file ') .'<a style="text-decoration: underline;" href="' . _MODULE_DIR_ . 'aktcsv/log/missed_products.txt">missed_products.txt</a>, using filtr
            = <b>' . $filtr1 . '</b>,  you can find <b>' . $this->countMissedProducts . '</b> records.<br/>'
        );
    }

    private function clearStocks()
    {
        $values['quantity'] = 0;

        return $this->db->update('product', $values, '', 0, false, false);
    }

    private function clearStockAvailable()
    {
        $values['quantity'] = 0;

        return $this->db->update('stock_available', $values, '', 0, false, false);
    }

    private function clearStocksWithAttributes()
    {
        $values['quantity'] = 0;

        return $this->db->update('product_attribute', $values, '', 0, false, false);
    }

    private function _clearCSVPrice($priceToClear)
    {
        $price = str_replace(",", ".", $priceToClear);
        return str_replace(" ", "", $price);
    }

    private function calculateFinalPrice($price, $profit, $profit_plus)
    {
        $price += $price * ($profit / 100);
        $price += $profit_plus;
        return $price;
    }

    private function _clearCSVIQuantity($amountToClear)
    {
        return str_replace(">", "", $amountToClear);
    }

    /**
     * @param $numer
     * @param $reference
     * @return int
     */
    private function isProductInDB($numer, $reference)
    {
        $reference = trim($reference);
        if (empty($reference)) {
            return 0;
        }

        $idProduct = (int)$this->db->getValue(
            "SELECT id_product FROM `" . _DB_PREFIX_ . "product` WHERE `" . $numer . "`='" . $reference . "'",
            0
        );
        return $idProduct;
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
        return $this->db->update('product', $values, $numer . ' = \'' . $ref . '\' ');
    }

    private function _getTaxRate($idProduct, $id_shop)
    {
        return $this->db->getValue(
            '
                SELECT ' . _DB_PREFIX_ . 'tax.rate
                FROM ' . _DB_PREFIX_ . 'tax, ' . _DB_PREFIX_ . 'product_shop
                WHERE ' . _DB_PREFIX_ . 'product_shop.id_product = ' . $idProduct . '
                AND ' . _DB_PREFIX_ . 'product_shop.id_shop=' . $id_shop . '
                AND ' . _DB_PREFIX_ . 'tax.id_tax = ' . _DB_PREFIX_ . 'product_shop.id_tax_rules_group
        '
        );
    }

    private function _calculateAndFormatNetPrice($price, $taxRate)
    {
        $priceGross = number_format($price, 2, ".", "");
        $taxRate = ($taxRate + 100) / 100;
        $priceNet = ($priceGross / $taxRate);

        return number_format($priceNet, 2, ".", "");
    }

    private function _updateProductPriceInShop($priceNet, $idProduct, $id_shop)
    {
        return $this->db->update(
            'product_shop',
            array(
                'price' => $priceNet,
                'date_upd' => date("Y-m-d H:i:s"),
            ),
            'id_product = \'' . $idProduct . '\'  AND id_shop = \'' . $id_shop . '\' '
        );
    }

    /**
     * @param $numer
     * @param $reference
     * @return array
     */
    private function isProductWithAttributeInDB($numer, $reference)
    {
        $reference = trim($reference);
        if (empty($reference)) {
            return array();
        }

        $sql = "SELECT id_product, id_product_attribute FROM `" . _DB_PREFIX_ . "product_attribute`
                WHERE " . $numer . " = '" . $reference . "'";
        $productWithAttribute = $this->db->getRow($sql, 0);

        return $productWithAttribute;
    }

        /**
     * @param $id_product
     * @return id_product_attribute
     */
    private function getProductAttribute($id_product)
    {
        $sql = "SELECT id_product, id_product_attribute FROM `" . _DB_PREFIX_ . "product_attribute`
                WHERE id_product = " . $id_product;
        $productWithAttribute = $this->db->getRow($sql, 0);

        return $productWithAttribute['id_product_attribute'];
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

        return $this->db->update('product_attribute', $values, $numer . ' = \'' . $ref . '\' ');
    }

    protected function openLogFile()
    {
        $dir = '../modules/aktcsv/log/';
        $file = 'missed_products.txt';
        $filename = $dir . $file;
        // Let's make sure the file exists and is writable first.
        if (is_writable($filename)) {
            return fopen($filename, 'w');
        }
        return false;
    }

    /**
     * @param $handleNotInDB
     * @param string $numer
     * @param array $info
     * @param string $filter
     */
    private function logMissedProduct($handleNotInDB, $numer, $info, $filtr)
    {
    	//Check if name is in filter
    	if (!empty($filtr) && isset($info['name']) && !empty($info['name'])) {
    		$isFound = stripos($info['name'], $filtr);
    		if ($isFound === false) {
    			return;
    		}
    	}
        $line = 'Product not found in DB: ' . $numer . ' = ' . $info["index"];
        if (isset($info['price'])) {
            $line .= ', New price - ' . $info['price'];
        }
        if (isset($info['stock'])) {
            $line .= ', New stock - ' . $info['stock'];
        }
        if (isset($info['name'])) {
            $line .= ', Item Name - ' . $info['name'];
        }
        fwrite($handleNotInDB, $line .  "\n\r");

        $this->countMissedProducts++;
    }

    protected function receiveTab()
    {
        $type_value = Tools::getValue('type_value') ? Tools::getValue('type_value') : array();
        foreach ($type_value as $nb => $type) {
            if ($type != 'no') {
                $this->column_mask[$type] = $nb;
            }
            Configuration::updateValue($this->name . '_TYPEVALUE'.$nb, $type);
        }

    }

    protected  function getMaskedRow($row)
    {
        $res = array();
        if (is_array($this->column_mask))
            foreach ($this->column_mask as $type => $nb)
                $res[$type] = isset($row[$nb]) ? $row[$nb] : null;

        return $res;
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

        if (version_compare(_PS_VERSION_, '1.6', '<')) {
            $this->_html .= '<div class="bootstrap">';
        }
        $this->_html .= '<p>Commercial use? Maybe some small donation?</p>
<div style="max-width: 500px;">
    <form class="form-horizontal" role="form" method="post" action="' . $_SERVER['REQUEST_URI'] . '" enctype="multipart/form-data">
        <div class="panel">
            <div class="panel-heading">
                <i class="icon-file"></i>
            ' . $this->l('Wgraj plik CSV') . '
            </div>
            <div class="panel-body">
                <div class="alert alert-info">' . $this->l('Obslugiwane kolumny w pliku CSV') . ':
                    <p>' . $this->l('(index; nazwa; cena; ilość)') . '</p>
                    <p></p>
                </div>
                <input type="hidden" name="MAX_FILE_SIZE" value="20000000" />
                <input type="file" name="csv_filename" />
            </div>
            <div class="panel-footer">
                <button name="submit_csv" class="btn btn-default pull-right" type="submit">
                    <i class="process-icon-save"></i> ' . $this->l('Wyślij ten plik na serwer') . '
                </button>
            </div>
        </div>
    </form>

    <br />
    <br />

<form class="form-horizontal" role="form" method="post" action="' . $_SERVER['REQUEST_URI'] . '">
<div class="panel">
    <div class="panel-heading">
        <i class="icon-money"></i> ' . $this->l('Główne funkcje modułu') . '
    </div>

    <div class="alert alert-info">' . $this->l(
                'Aktualizacja przeprowadzona będzie z pliku:'
            ) . ' ' . Configuration::get($this->name . '_CSVFILE') . '
    </div>

    <div class="form-group">
        <label class="control-label required" for="separator">
        <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="'
            . $this->l('Wazne! Jaki separator w CSV') . '">
        ' . $this->l('Separator pól w pliku *.csv') . '
        </span>
        </label>
        <input class="form-control" type="text" name="separator" value="' . Configuration::get($this->name . '_SEPARATOR') . '">
    </div>
    <br />
    <div class="form-group">
        <label class="control-label required" for="type_value">
        <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="'
            . $this->l('Please match each column of your source CSV file to one of the destination columns.') . '">
        ' . $this->l('Match columns') . '
        </span>
        </label>
        <table id="table" class="table table-bordered">
        <tr>
        <td>' . $this->l('Column') . ' 1</td>
        <td>' . $this->l('Column') . ' 2</td>
        <td>' . $this->l('Column') . ' 3</td>
        <td>' . $this->l('Column') . ' 4</td>
        </tr>
            <tr>
                <td>
                    <select class="form-control type_value"  id="type_value[0]" name="type_value[0]" >
                      <option value="index"' . ((Configuration::get($this->name . '_TYPEVALUE0') == "index") ? ' selected="selected"' : '') . '>' . $this->l('Index') . '</option>
                      <option value="price"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE0'
                    ) == "price") ? ' selected="selected"' : '') . '>' . $this->l('Price') . '</option>
                      <option value="stock"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE0'
                    ) == "stock") ? ' selected="selected"' : '') . '>' . $this->l('Stock') . '</option>
                      <option value="name"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE0'
                    ) == "name") ? ' selected="selected"' : '') . '>' . $this->l('Name') . '</option>
                    </select>
                </td>
                <td>
                    <select class="form-control type_value"  id="type_value[1]" name="type_value[1]" >
                      <option value="index"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE1'
                    ) == "index") ? ' selected="selected"' : '') . '>' . $this->l('Index') . '</option>
                      <option value="price"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE1'
                    ) == "price") ? ' selected="selected"' : '') . '>' . $this->l('Price') . '</option>
                      <option value="stock"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE1'
                    ) == "stock") ? ' selected="selected"' : '') . '>' . $this->l('Stock') . '</option>
                      <option value="name"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE1'
                    ) == "name") ? ' selected="selected"' : '') . '>' . $this->l('Name') . '</option>
                    </select>
                </td>
                <td>
                    <select class="form-control type_value"  id="type_value[2]" name="type_value[2]" >
                    <option value="no"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE2'
                    ) == "no") ? ' selected="selected"' : '') . '>' . $this->l('Ignore this column') . '</option>
                      <option value="index"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE2'
                    ) == "index") ? ' selected="selected"' : '') . '>' . $this->l('Index') . '</option>
                      <option value="price"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE2'
                    ) == "price") ? ' selected="selected"' : '') . '>' . $this->l('Price') . '</option>
                      <option value="stock"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE2'
                    ) == "stock") ? ' selected="selected"' : '') . '>' . $this->l('Stock') . '</option>
                      <option value="name"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE2'
                    ) == "name") ? ' selected="selected"' : '') . '>' . $this->l('Name') . '</option>
                    </select>
                </td>
                <td>
                    <select class="form-control type_value"  id="type_value[3]" name="type_value[3]" >
                      <option value="no"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE3'
                    ) == "no") ? ' selected="selected"' : '') . '>' . $this->l('Ignore this column') . '</option>
                      <option value="index"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE3'
                    ) == "index") ? ' selected="selected"' : '') . '>' . $this->l('Index') . '</option>
                      <option value="price"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE3'
                    ) == "price") ? ' selected="selected"' : '') . '>' . $this->l('Price') . '</option>
                      <option value="stock"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE3'
                    ) == "stock") ? ' selected="selected"' : '') . '>' . $this->l('Stock') . '</option>
                      <option value="name"' . ((Configuration::get(
                        $this->name . '_TYPEVALUE3'
                    ) == "name") ? ' selected="selected"' : '') . '>' . $this->l('Name') . '</option>
                    </select>
                </td>
            </tr>
        </table>
    </div>
    <br/>
    <div class="form-group">
        <label class="control-label required" for="numer">
        <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="' . $this->l(
                    'Wazne! Wg jakiego klucza szukac produktu w bazie'
                ) . '">
        ' . $this->l('Index is type of:') . '
        </span>
        </label>
        <select class="form-control" name="numer">
          <option value="supplier_reference"' . ((Configuration::get(
                        $this->name . '_NUMER'
                    ) == "supplier_reference") ? ' selected="selected"' : '') . '>' . $this->l('Nr ref. dostawcy') . '</option>
          <option value="reference"' . ((Configuration::get(
                        $this->name . '_NUMER'
                    ) == "reference") ? ' selected="selected"' : '') . '>' . $this->l('Kod produktu') . '</option>
                    <option value="productid"' . ((Configuration::get(
                        $this->name . '_NUMER'
                    ) == "productid") ? ' selected="selected"' : '') . '>productid</option>
          <option value="ean13"' . ((Configuration::get(
                        $this->name . '_NUMER'
                    ) == "ean13") ? ' selected="selected"' : '') . '>EAN13</option>
        </select>
    </div>
    <br/>
    <div class="form-group">
        <label class="control-label required" for="profit">
        <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip"
         data-original-title="Procent kwoty, jaki zostanie dodany do ceny">
        ' . $this->l('Marża w procentach') . '
        </span>
        </label>
        <input class="form-control" type="number" name="profit" value="' . Configuration::get($this->name . '_PROFIT') . '">
    </div>
    <br />
    <div class="form-group">
        <label class="control-label required" for="profit_plus">
        <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip"
         data-original-title="Kwota, ktora zostanie dodana po uwzglednieniu procentowej marzy">
        ' . $this->l('Dodatkowa marża kwotowa') . '
        </span>
        </label>
        <input class="form-control" type="number" step="0.01" name="profit_plus" value="' . Configuration::get(
                        $this->name . '_PROFITPLUS'
                    ) . '">
    </div>
            <br />
    <div class="form-group">
        <label class="control-label required" for="gross">
            <span title="" data-html="true" data-toggle="tooltip" class="label-tooltip" data-original-title="Brutto (wyliczy netto) lub netto.">
            ' . $this->l('Ceny produktów') . '
            </span>
        </label>
        <select class="form-control" name="gross">
          <option value="1" ' . ((Configuration::get($this->name . '_GROSS') == "1") ? ' selected="selected"' : '') . '>' . $this->l('Brutto') . '</option>
          <option value="0" ' . ((Configuration::get($this->name . '_GROSS') == "0") ? ' selected="selected"' : '') . '>' . $this->l('Netto') . '</option>
      </select>
  </div>
  <br />
      <div class="form-group">
          <div class="checkbox disabled">
              <label>
                    <input class="" type="checkbox" name="clearStocksMode" value="1" />' . $this->l(
                'Zerować stany i ceny?'
            ) . ' (Nie przetestowana)
              </label>
        </div>
      </div>
    <br />
    <div class="form-group">
        <div class="checkbox">
         <label>
            <input class="" type="checkbox" name="atrybuty" value="1"  ' . ((Configuration::get($this->name . '_ATRYBUTY') == "1") ? ' checked' : '') . ' />' . $this->l(
                'Mam w bazie produkty z atrybutami'
            ) . '
         </label>
        </div>
    </div>
<br/>
    <div class="form-group">
        <div class="checkbox">
         <label>
            <input class="" type="checkbox" name="custom_id" value="1" ' . ((Configuration::get($this->name . '_CUSTOMID') == "1") ? ' checked' : '') . '/>' . $this->l(
                'Mam w bazie produkty z wlasnym id'
            ) . '
         </label>
        </div>
    </div>
    <br />

    <h3>' . $this->l('Opcje tworzenia pliku z brakującymi produktami') . '</h3>
    <div class="form-group">
        <div class="checkbox">
             <label>
                <input type="checkbox" name="logProductsNotInDB" value="1" checked="checked" /> ' . $this->l(
                    'Sprawdzać produkty których nie ma w sklepie a są w pliku *csv?'
                ) . '
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
        <input type="submit" name="submit_update" value="' . $this->l('Przeprowadź aktualizację') . '" class="buttonFinish btn btn-lg btn-success" />
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
    <div class="panel-body">
        <p>' . $this->l('Ostatnio wygenerowany plik z brakującymi produktam:') . ' '
                . '<b><a style="text-decoration: underline;" href="' . _MODULE_DIR_ . 'aktcsv/log/missed_products.txt">missed_products.txt</a></b>
        </p>
        <p>' . $this->l('Jesli nie mozna otworzyc pliku, to musisz uzyc FTP.') . '</p>
    </div>
</div>

</div><!-- max-width-->

<br />
<br />
<br />

<div class="panel panel-info">
  <div class="panel-heading"><i class="icon-lightbulb"></i>
    ' . $this->l('Informacje') . '</div>
  <div class="panel-body">
     <p style="text-align:center;">Potrzebujesz pomocy, modyfikacji? Zapytaj na forum.</p>
    <br />
    <p>' . $this->l('Moduł ten aktualizuje ceny oraz stany magazynowe z pliku *.csv.') . '
    <br />
    ' . $this->l(
                'Produkty w bazie rozpoznawane są po numerze referencyjnym dostawcy, kodzie produktu lub kodzie EAN13.'
            ) . '
     <br />
     ' . $this->l(
                'Skrypt tworzy plik o nazwie `missed_products.txt` w którym zapisywane są produkty znajdujące się w pliku csv
    a ktorych nie ma w bazie sklepu. Dodatkowo produkty zapisywane do tego pliku możemy ograniczyć do produktów
    zawierających w nazwie określone slowa (wpisując je w pola "Filtr_1"), oraz tylko do produktów o ilości
    nie mniejszej niż wpisana w pole `Limit sztuk na magazynie`'
            ) . '.</p>
    <p>
    ' . $this->l('Moduł można szybko dostosować do indywidualnych potrzeb.') . '
    </p>
  </div>
</div>';

    $style15 =  '</div>
<style type="text/css">
#content .bootstrap .alert.alert-info, .bootstrap #carrier_wizard .alert-info.wizard_error {
    background-color: #f8fcfe;
    border: 1px solid #c5e9f3;
    color: #31b0d5;
    padding-left: 50px;
    position: relative;
}
#content .bootstrap .alert-info {
    background-color: #d9edf7;
    border-color: #bce8f1;
    color: #31708f;
}
#content .bootstrap .alert, .bootstrap #carrier_wizard .wizard_error {
    border: 1px solid transparent;
    border-radius: 3px;
    margin-bottom: 17px;
    padding: 15px;
}
#content .bootstrap .alert.alert-info:before, .bootstrap #carrier_wizard .alert-info.wizard_error:before {
    color: #81cfe6;
    display: block;
    height: 25px;
    left: 7px;
    position: absolute;
    top: 6px;
    width: 25px;
}
.bootstrap p {
    margin: 0 0 8.5px;
}
.bootstrap .panel-body:before, .bootstrap .panel-body:after {
    content: " ";
    display: table;
}

.bootstrap .panel-body:after {
    clear: both;
}
.bootstrap .panel-body:before, .bootstrap .panel-body:after {
    content: " ";
    display: table;
}

.bootstrap .panel-body {
    padding: 15px;
}
.bootstrap .panel .panel-footer, .bootstrap #dash_version .panel-footer, .bootstrap .message-item-initial .message-item-initial-body .panel-footer, .bootstrap .timeline .timeline-item .timeline-caption .timeline-panel .panel-footer {
    background-color: #fcfdfe;
    border-color: #eee;
    height: 73px;
    margin: 15px -20px -20px;
}
.bootstrap .panel-footer {
    background-color: #f5f5f5;
    border-bottom-left-radius: 2px;
    border-bottom-right-radius: 2px;
    border-top: 1px solid #ddd;
    padding: 10px 15px;
}
.bootstrap .panel .panel-heading, .bootstrap #dash_version .panel-heading, .bootstrap .message-item-initial .message-item-initial-body .panel-heading, .bootstrap .timeline .timeline-item .timeline-caption .timeline-panel .panel-heading {
    color: #555;
    font-family: "Ubuntu Condensed",Helvetica,Arial,sans-serif;
    font-size: 14px;
    font-weight: 400;
    height: 32px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.bootstrap h3:not(.modal-title), .bootstrap .panel-heading {
    -moz-border-bottom-colors: none;
    -moz-border-left-colors: none;
    -moz-border-right-colors: none;
    -moz-border-top-colors: none;
    border-color: -moz-use-text-color -moz-use-text-color #eee;
    border-image: none;
    border-style: none none solid;
    border-width: medium medium 1px;
    font-size: 1.2em;
    height: 2.2em;
    line-height: 2.2em;
    margin: -20px -16px 15px;
    padding: 0 0 0 5px;
    text-transform: uppercase;
}
.bootstrap .panel-heading {
    border-bottom: 1px solid transparent;
    border-top-left-radius: 2px;
    border-top-right-radius: 2px;
    padding: 10px 15px;
}
.bootstrap .panel, .bootstrap #dash_version, .bootstrap .message-item-initial .message-item-initial-body, .bootstrap .timeline .timeline-item .timeline-caption .timeline-panel {
    background-color: #fff;
    border: 1px solid #e6e6e6;
    border-radius: 5px;
    box-shadow: 0 2px 0 rgba(0, 0, 0, 0.1), 0 0 0 3px #fff inset;
    margin-bottom: 20px;
    padding: 20px;
    position: relative;
}
.bootstrap .panel, .bootstrap #dash_version, .bootstrap .message-item-initial .message-item-initial-body, .bootstrap .timeline .timeline-item .timeline-caption .timeline-panel {
    background-color: #fff;
    border: 1px solid #e6e6e6;
    border-radius: 5px;
    box-shadow: 0 2px 0 rgba(0, 0, 0, 0.1), 0 0 0 3px #fff inset;
    margin-bottom: 20px;
    padding: 20px;
    position: relative;
}
}
.bootstrap .radio label, .bootstrap .checkbox label {
    cursor: pointer;
    font-weight: normal;
    margin-bottom: 0;
    padding-left: 20px;
}
.bootstrap label {
    display: inline-block;
    font-weight: bold;
    margin-bottom: 5px;
    max-width: 100%;
}
.bootstrap .radio label, .bootstrap .checkbox label {
    cursor: pointer;
    font-weight: normal;
    margin-bottom: 0;
    padding-left: 20px;
}
.bootstrap label {
    display: inline-block;
    font-weight: bold;
    margin-bottom: 5px;
    max-width: 100%;
}
.bootstrap .form-horizontal .radio, .bootstrap .form-horizontal .checkbox {
    min-height: 22px;
}
.bootstrap .form-horizontal .radio, .bootstrap .form-horizontal .checkbox, .bootstrap .form-horizontal .radio-inline, .bootstrap .form-horizontal .checkbox-inline {
    margin-bottom: 0;
    margin-top: 0;
    padding-top: 5px;
}
.bootstrap .radio, .bootstrap .checkbox {
    display: block;
    margin-bottom: 10px;
    margin-top: 10px;
    min-height: 17px;
}
.bootstrap .form-horizontal .form-group:before, .bootstrap .form-horizontal .form-group:after {
    content: " ";
    display: table;
}
.bootstrap *:before, .bootstrap *:after {
    box-sizing: border-box;
}
.bootstrap .form-horizontal .form-group:after {
    clear: both;
}
.bootstrap .form-horizontal .form-group:before, .bootstrap .form-horizontal .form-group:after {
    content: " ";
    display: table;
}
.bootstrap *:before, .bootstrap *:after {
    box-sizing: border-box;
}
.bootstrap .form-horizontal .form-group {
    margin-left: -5px;
    margin-right: -5px;
}
.bootstrap .form-group {
    margin-bottom: 15px;
}
.bootstrap * {
    box-sizing: border-box;
}
</style>';

        if (version_compare(_PS_VERSION_, '1.6', '<')) {
            $this->_html .= $style15;
        }
            return $this->_html;
    }

//product_shop 	Product shop associations 	id_product, id_shop

//Not used:
//Product attribute shop associations
//product_attribute_shop.`price`

//2016-04-12: Jesli nie mozna otworzyc pliku CSV - skrypt konczy dzialanie, zamiast wpadac w petle.
}
