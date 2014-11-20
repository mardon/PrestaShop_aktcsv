<?php
/*
  Module Name: AktCSV
  Module URI: https://github.com/Lechus/PrestaShop_aktcsv
  Description: Update Stock and Price in PrestaShop 1.4, 1.5.4.1, 1.5.6.2, 1.6.0.9
  Version: 4.141016
  Author URI: https://github.com/Lechus
  Dev version:
  - add support for PS 1.4 (original module by Sokon.pl)
  - module folders structure for PS 1.6

 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (version_compare(_PS_VERSION_, '1.5.4.0', '<'))
{
	require(dirname(__FILE__)."/aktcsv_1_4.php");
}
else
{
	//require(dirname(__FILE__)."/controllers/admin/aktcsv.php");
  require(dirname(__FILE__)."/aktcsv_1_6.php");
}
