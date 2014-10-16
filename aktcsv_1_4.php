<?php
class AktCsv extends Module
{
	function __construct()
	{
		$this->name = 'aktcsv';
		$this->tab = 'Moduły Sokon.pl';
		$this->version = 2.0;

		parent::__construct(); 

		$this->page = basename(__FILE__, '.php');
		$this->displayName = $this->l('Aktualizacja z CSV');
		$this->description = $this->l('Aktualizuje ceny i stany z pliku .CSV');
	}

	function install()
	{
		if (!parent::install())
			return false;
    Configuration::updateValue('SOKON_SCV_SEPARATOR', ';');
    Configuration::updateValue('SOKON_SCV_NUMER', 'supplier_reference');
    Configuration::updateValue('SOKON_SCV_MARZA', '2.00');
    Configuration::updateValue('SOKON_SCV_MARZAPLUS', '0');
    Configuration::updateValue('SOKON_SCV_LIMIT', '1');
    Configuration::updateValue('SOKON_SCV_FILTR1', '');		
		return true;
	}


public function getContent()
	{
	// SPRAWDZAMY CZY JEST NOWSZA WERSJA
$upd = file_get_contents("http://www.sokon.pl/prestashop/moduly/aktcsv.txt");
$upd_log = file_get_contents("http://www.sokon.pl/prestashop/moduly/aktcsv.log");
if ($upd > $this->version)
$output = '<div class="warning warn" style="margin-bottom:30px; width:95%;"><h3>Twoja wersja modułu to: wersja '.$this->version.'. Dostępna jest nowa wersja modułu (ver.'.$upd.') : <a style="text-decoration: underline;" href="http://www.sokon.pl/prestashop/moduly/aktcsv.zip">Pobierz ją</a>!</h3>'.$upd_log.'</div>';


$output .= '<h2>' . $this->displayName . '</h2>';


// jeśli wysyłamy plik na serwer
if($_POST["plik"]) 
  { 

    // wrzucamy plik do katalogu
    $f = $_FILES['nazwa_pliku'];
    if(isset($f['name']))
      {
        move_uploaded_file($f['tmp_name'], '../modules/import/'.$this->name.'/'.$f['name']);

        Configuration::updateValue('SOKON_SCV_PLIK', $f['name']);
      }
    $output .= $this->displayConfirmation('Plik załadowany. <br/>Załadowałeś plik: <b>"' . Configuration::get('SOKON_SCV_PLIK') . '"</b> o rozmiarze: <b>' . $_FILES['nazwa_pliku']['size'] . '</b> bajtów.<br />');  
    }


// jeśli aktualizujemy cennik    
if($_POST["aktualizuj"]) 
  {

$separator=$_POST["separator"];
Configuration::updateValue('SOKON_SCV_SEPARATOR', $separator);
$numer=$_POST["numer"];
Configuration::updateValue('SOKON_SCV_NUMER', $numer);
$marza=$_POST["marza"];
Configuration::updateValue('SOKON_SCV_MARZA', $marza);
$marza_plus=$_POST["marza_plus"];
if ($marza_plus=="") $marza_plus="0";
Configuration::updateValue('SOKON_SCV_MARZAPLUS', $marza_plus);
$limit=$_POST["limit"];
Configuration::updateValue('SOKON_SCV_LIMIT', $limit);
$filtr1=$_POST["filtr1"];
Configuration::updateValue('SOKON_SCV_FILTR1', $filtr1);


if ($_POST["brakujace"]=="tak") $brakujace=1;
if ($_POST["zerowanie"]=="tak") $zerowanie=1;
if ($_POST["atrybuty"]=="tak") $atrybuty=1;

  $start = microtime(true);  // czas start
  
//Poprawka by KSEIKO
@ $db=new mysqli(_DB_SER_,_DB_USER_,_DB_PASSWD_,_DB_NAME_,_DB_PORT_);
     $uchwyt = fopen ('../modules/aktcsv/import/'.Configuration::get('SOKON_SCV_PLIK'),"r");
     $writeFd = @fopen("../modules/aktcsv/log/brakujace.txt", 'w');   // do zapisu brakujących
  $wpisow=0;
  $zmian_p=0;
  $zmian_a=0;
  $dopliku=0;
  $counter=0;
  
 


//NAJPIERW ZERUJEMY WSZYSTKie ceny i stany

if ($zerowanie == 1)
  {
 //     $db->query("UPDATE "._DB_PREFIX_."product SET quantity=0, price=0"); //zmiana przez vivaldi
 //     $db->query("UPDATE "._DB_PREFIX_."product_attribute SET quantity=0");
  }
      
      

    while (($data = fgetcsv($uchwyt, 0, $separator)) !== FALSE)  
      {
      $wpisow++;
            
      //czyścimy zbedne znaki z csvki
      $cena = str_replace(",", ".", $data[2]);   // jeśli w cenie jest ","
      $cena = str_replace(" ", "", $cena);       // jeśli w cenie jest " "
      $ilosc = str_replace(">", "", $data[3]);   // jeśli w ilości jest ">"
      
      $cena=$cena*$marza; //marza ogólna
      $cena=$cena+$marza_plus; //dodajemy stała kwotę
      
$zapytanie = 'UPDATE `'._DB_PREFIX_.'product` SET `quantity` = "'.$ilosc.'", `price` = "'.$cena.'" WHERE ';
$zapytanie .= '`'.$numer.'`="'.$data[0].'" ';      
 
 
$db->query($zapytanie);
    
      if ($db->affected_rows == 1) $zmian_p++;    //dokonano zmiany
    
    
    if ($brakujace == 1)     //jeśli sprawdzamy produkty ktorych nie ma w sklpeie
  {
          //szukamy wg filtra_1
          if ($db->affected_rows!=1)    // nie dokonano zmiany
            {
             if (((Strpos($data[1],$filtr1,0) !== false) && ($cena != "0.00") && ($ilosc >= $limit)) 
                  OR 
                  (($filtr1 =="") && ($cena != "0.00") && ($ilosc >= $limit)))
              {
              echo 'Niewpisany produkt: indeks - <b>'. $data[0] . '</b> nazwa - <b>'.$data[1] . '</b> cena - <b>'.$cena . '</b>  ilość - <b>'.$ilosc . '</b><br />';
                fwrite($writeFd, 'Indeks - '. $data[0] . '  nazwa - '.$data[1] . '   cena - '.$cena . '   ilość - '.$ilosc);
                fwrite($writeFd, "\n\r");
                $dopliku++;
      
              @ob_flush();
              @flush();
              
              }
            }
   }   
   else {    //jeśli nie sprawdzamy produktów ktorych nie ma w sklepie (aby ominąć limit czasu dla skryptu)
   $counter++;
   if (($counter/100) == (int)($counter/100)) echo '~';
   if ($counter>8000) {
   echo '<br />';
   $counter=0;
   @ob_flush();
   @flush();
   }  }    
   
   
   
   
     
     
     
     
     
 
//  AKTUALIZACJA STANÓW DLA PRODUKTÓW Z ATRYBUTAMI
if ($atrybuty == 1)
  {
  
  //Vivaldi test

  $produkt_bez_atr = Db::getInstance()->getValue('SELECT `id_product` FROM `'._DB_PREFIX_.'product_attribute` WHERE `'.$numer.'`="'.$data[0].'" '); 
 $cena_produkt_bez_atr = Db::getInstance()->getValue('SELECT `price` FROM `'._DB_PREFIX_.'product` WHERE `id_product` = "'.$produkt_bez_atr.'" '); 
 $id_podatku = Db::getInstance()->getValue('SELECT `id_tax` FROM `'._DB_PREFIX_.'product` WHERE `id_product` = "'.$produkt_bez_atr.'" '); 
 $podatek = Db::getInstance()->getValue('SELECT `rate` FROM `'._DB_PREFIX_.'tax` WHERE `id_tax` = "'.$id_podatku.'" '); 

 // chyba wszystko mamy???? to liczymy... zmiana przez vivaldi

 $cena_produkt_bez_atr=number_format($cena_produkt_bez_atr, 2, ".", "");
 $podatek=($podatek+100)/100;
 $cena_atr = number_format((($cena - $cena_produkt_bez_atr)/10), 2, ".", "") * $podatek * 10; 
   
  //Vivaldi test koniec
  
  
$zapytanie = 'UPDATE `'._DB_PREFIX_.'product_attribute` SET `quantity` = "'.$ilosc.'", `price` = "'.$cena_atr.'"  WHERE ';
$zapytanie .= '`'.$numer.'` = "'.$data[0].'" ';    

      
$db->query($zapytanie);
      if ($db->affected_rows == 1) $zmian_a++;    //dokonano zmiany

   } 
   
   
   
   
   
   
   
   
   

      }
  fclose ($uchwyt);
  fclose($writeFd); // zamyka brakujące


   
  unset($db);  // zamyka połączenie z bazą
  
$koniec = microtime(true);   //koniec wykonywania skryptu
$czas=round($koniec-$start,2);
  
  if ($filtr1=="") $filtr1="wszystkie brakujące pozycje";
  if ($brakujace!=1) $filtr1="Zablokowaleś sprawdzanie produktów.";
  
  $output .= $this->displayConfirmation('<b>Aktualizacja przeprowadzona pomyślnie</b><br/>Produktów w pliku '.Configuration::get('SOKON_SCV_PLIK').': <b>'.$wpisow.'</b><br/>Poprawionych produktów w bazie: <b>'.$zmian_p.'</b><br />Poprawionych atrybutów w bazie: <b>'.$zmian_a.'</b><br />Marżę ustlilem na: <b>'.(($marza-1)*100).'%.</b><br/>Czas przetwarzania skryptu: <b>'.$czas.'</b> sekund<br/>W pliku "brakujace.txt" zapisałem: <b>'.$filtr1.'</b> (ilość wpisanych pozycji: <b>'.$dopliku.'</b>).');
     
  }


  //BEZ AKCJI WYSWIETLA formularze
  return $output.$this->displayForm(); 
    
}



public function displayForm()
  {
  
  global $cookie,$currentIndex;
	$output = '
<fieldset><legend>'.$this->l('Wybierz plik o nazwie').' "*.csv" '.$this->l('(nr kat; nazwa; cena; ilość)').'</legend>
<form method="post" action="'.$_SERVER['REQUEST_URI'].'" enctype="multipart/form-data">
<input type="hidden" name="MAX_FILE_SIZE" value="20000000" />
<input type="file" name="nazwa_pliku" />
<input type="submit" name="plik" value="'.$this->l('Wyślij ten plik na serwer').'" class="button" />
</form></fieldset>
<br />
<br />
<fieldset><legend>'.$this->l('Główne funkcje modułu').'</legend>

<form method="post"  action="'.$_SERVER['REQUEST_URI'].'"> 
'.$this->l('Aktualizacja przeprowadzona będzie z pliku:').' <b>'.Configuration::get('SOKON_SCV_PLIK').'</b><br />
<input type="text" name="separator" value="'.Configuration::get('SOKON_SCV_SEPARATOR').'" size="1">'.$this->l('Separator pól w pliku *.csv').'</input><br /><br />
<select name="numer">
  <option value="supplier_reference" selected="selected"> Nr ref. dostawcy</option>
  <option value="reference"> Kod produktu</option>
  <option value="ean13"> Kod EAN13</option></select>'.$this->l('Wybierz numeru 1 kol.').'<br />
<input type="text" name="marza" value="'.Configuration::get('SOKON_SCV_MARZA').'" size="3">'.$this->l('Jaką ustalamy marżę? (np. 1.20 - 20%)').'</input><br />

<input type="text" name="marza_plus" value="'.Configuration::get('SOKON_SCV_MARZAPLUS').'" size="3">'.$this->l('Stała kwota dodawana do ceny poza marżą.').'</input><br /><br />

<input type="checkbox" name="zerowanie" value="tak" checked="checked" />'.$this->l('Zerować stany i ceny?').'<br />
<input type="checkbox" name="atrybuty" value="tak" checked="checked" />'.$this->l('Mam w bazie produkty z atrybutami').'<br /><br />

<p><b>'.$this->l('Opcje tworzenia pliku z brakującymi produktami').'</b></p>
<input type="checkbox" name="brakujace" value="tak" checked="checked" />'.$this->l('Sprawdzać produkty których nie ma w sklepie a są w pliku *csv?').'<br />
<input type="text" name="limit" value="'.Configuration::get('SOKON_SCV_LIMIT').'" size="3">'.$this->l('Jaki limit sztuk na magazynie?').'</input><br />
<input type="text" name="filtr1" value="'.Configuration::get('SOKON_SCV_FILTR1').'" size="10">'.$this->l('Filtr 1 Co wyszukujemy?').'</input><br />
<p><input type="submit" name="aktualizuj" value="'.$this->l('Przeprowadź aktualizację').'" class="button" /> '.$this->l('Może trochę potrwać! - bądź cierpliwy...').'</P>
</form>

 

</fieldset>
<br />
<br />
<fieldset>
<legend>'.$this->l('Dodatki').'</legend>
<p>'.$this->l('Ostatnio wygenerowany plik z brakującymi produktami:').' <b><a href="brakujace.txt">Brakujące.txt</a></b></p>
</fieldset>
<br />
<br />
<br />
<fieldset>
<legend><img src="../img/admin/comment.gif"/>Informacje</legend>
<p style="text-align:center;">Jeśli potrzebujesz pomocy w dostosowaniu tego modulu do Twoich potrzeb skontaktuj się z nami:<br />
www: <b><a href="http://www.sokon.pl">Sokon.pl</a></b><br />
e-mail: <b><a href="mailto:sokon@sokon.pl">sokon@sokon.pl</a></b><br />
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
