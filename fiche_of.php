<?php

set_time_limit(120);

require('config.php');

dol_include_once('/of/class/ordre_fabrication_asset.class.php');
dol_include_once('/of/lib/of.lib.php');
dol_include_once('/core/lib/ajax.lib.php');
dol_include_once('/core/lib/product.lib.php');
dol_include_once('/core/lib/admin.lib.php');
dol_include_once('/product/class/product.class.php');
dol_include_once('/commande/class/commande.class.php');
dol_include_once('/fourn/class/fournisseur.commande.class.php');
dol_include_once('/product/class/html.formproduct.class.php');
dol_include_once('/core/lib/date.lib.php');
dol_include_once('/core/lib/pdf.lib.php');
dol_include_once('/nomenclature/class/nomenclature.class.php');

dol_include_once('/quality/class/quality.class.php');

dol_include_once('/' . ATM_ASSET_NAME . '/class/asset.class.php'); // TODO à remove avec les déclaration d'objet TAsset_type

if(!$user->rights->of->of->lire) accessforbidden();

// Load traductions files requiredby by page
$langs->load("other");
$langs->load("orders");
$langs->load("of@of");

$hookmanager->initHooks(array('ofcard'));

$PDOdb=new TPDOdb;
$assetOf=new TAssetOF;
$id = GETPOST('id', 'int');
if (!empty($id))
{
	$assetOf->load($PDOdb, $id);
	if ($assetOf->entity != $conf->entity) accessforbidden();
}

// Get parameters
_action();

// Protection if external user
if ($user->societe_id > 0)
{
	//accessforbidden();
}

function _action() {
	global $user, $db, $conf, $langs;
	$PDOdb=new TPDOdb;
	//$PDOdb->debug=true;

	/*******************************************************************
	* ACTIONS
	*
	* Put here all code to do according to value of "action" parameter
	********************************************************************/

	$action=__get('action','view');
	switch($action) {
		case 'new':
		case 'add':
			$assetOf=new TAssetOF;
			$assetOf->set_values($_REQUEST);

			$fk_product = __get('fk_product',0,'int');
			$fk_nomenclature = __get('fk_nomenclature',0,'int');

			_fiche($PDOdb, $assetOf,'edit', $fk_product, $fk_nomenclature);

			break;

		case 'edit':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id']);

			_fiche($PDOdb,$assetOf,'edit');
			break;

		case 'quick-save':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, GETPOST('id'), false);
			$assetOf->set_values($_REQUEST);

			$assetOf->save($PDOdb);
			_fiche($PDOdb,$assetOf, 'view' );
			break;

		case 'create':
		case 'save':
			$assetOf=new TAssetOF;
			if(!empty($_REQUEST['id'])) {
				$assetOf->load($PDOdb, $_REQUEST['id'], false);
				$mode = 'view';
			}
			else {

				$mode = $action == 'create' ? 'view' : 'edit';
			}

			$assetOf->set_values($_REQUEST);

			$fk_product = __get('fk_product_to_add',0);
			$quantity_to_create = __get('quantity_to_create',1);
			$fk_nomenclature = __get('fk_nomenclature',0);
			if($fk_product > 0) {
				$assetOf->addLine($PDOdb, $fk_product, 'TO_MAKE',$quantity_to_create,0,'',$fk_nomenclature);
			}

			if(!empty($_REQUEST['TAssetOFLine']))
			{
				foreach($_REQUEST['TAssetOFLine'] as $k=>$row)
				{
				    if(!isset( $assetOf->TAssetOFLine[$k] ))  $assetOf->TAssetOFLine[$k] = new TAssetOFLine;

					if (!empty($conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED))
					{
						$assetOf->TAssetOFLine[$k]->set_workstations($PDOdb, $row['fk_workstation']);
						//unset($row['fk_workstation']);
					}

					$assetOf->TAssetOFLine[$k]->set_values($row);
				}

				foreach($assetOf->TAssetOFLine as &$line) {
					$line->TAssetOFLine=array();
				}
			}

			if(!empty($_REQUEST['TAssetWorkstationOF'])) {
				foreach($_REQUEST['TAssetWorkstationOF'] as $k=>$row)
				{
					//Association des utilisateurs à un poste de travail
					if (!empty($conf->global->ASSET_DEFINED_USER_BY_WORKSTATION))
					{
						$assetOf->TAssetWorkstationOF[$k]->set_users($PDOdb, $row['fk_user']);
						unset($row['fk_user']);
					}

					//Association des opérations à une poste de travail (mode opératoire)
					if (!empty($conf->global->ASSET_DEFINED_OPERATION_BY_WORKSTATION))
					{
						$assetOf->TAssetWorkstationOF[$k]->set_tasks($PDOdb, $row['fk_task']);
						unset($row['fk_task']);
					}

					$assetOf->TAssetWorkstationOF[$k]->set_values($row);
				}
			}

			$assetOf->entity = $conf->entity;

			//Permet de mettre à jour le lot de l'OF parent
			if (!empty($assetOf->fk_assetOf_parent)) $assetOf->update_parent = true;
			$assetOf->save($PDOdb);
            $assetOf->load($PDOdb,$assetOf->rowid); // Pour remettre à jour les  données (je suis tombé plusieurs fois sur ce cas après un save)

			_fiche($PDOdb,$assetOf, $mode);

			break;

		case 'valider':
			$error = 0;
			$assetOf=new TAssetOF;
            $id = GETPOST('id');
            if(empty($id)) exit('Where is Waldo ?');

			$assetOf->load($PDOdb, $id);

           //Si use_lot alors check de la saisie du lot pour chaque ligne avant validation
			if (!empty($conf->global->USE_LOT_IN_OF) && !empty($conf->global->OF_LOT_MANDATORY)) {
				if (!$assetOf->checkLotIsFill())
				{
					_fiche($PDOdb,$assetOf, 'view');
					break;
				}
			}

			$res = $assetOf->validate($PDOdb);

			if ($res > 0)
			{
				//Relaod de l'objet OF parce que createOfAndCommandesFourn() fait tellement de truc que c'est le bordel

				$assetOf=new TAssetOF;
				if(!empty($_REQUEST['id'])) $assetOf->load($PDOdb, $_REQUEST['id'], false);
			}

			_fiche($PDOdb, $assetOf, 'view');

			break;

		case 'reload_pmp':
			$assetOf=new TAssetOF;
			$id = GETPOST('id');
			if(empty($id)) exit('Where is Waldo ?');

			$assetOf->load($PDOdb, $id);
			$assetOf->set_fourniture_cost(true);
			$assetOf->save($PDOdb);

			setEventMessage($langs->trans("pmpReloaded"));

			header("location:".$_SERVER['PHP_SELF']."?id=".$id);
			exit;

			break;

		case 'lancer':
			$assetOf=new TAssetOF;
            $id = GETPOST('id');
            if(empty($id)) exit('Where is Waldo ?');

			$assetOf->load($PDOdb,$id);

			$assetOf->openOF($PDOdb);

			// Possibilité qu'un OF reste à l'état VALID si pas assé de quantité en équipement MAIS erreur non bloquante (c'est voulu)
			if (!empty($assetOf->error)) setEventMessage($assetOf->error, 'errors'); // ->errors est traité dans _fiche()

			$assetOf->load($PDOdb,$id);
			_fiche($PDOdb, $assetOf, 'view');

			break;

		case 'terminer':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id']);
			$assetOf->closeOF($PDOdb);
			$assetOf->load($PDOdb, $_REQUEST['id']);

			_fiche($PDOdb,$assetOf, 'view');

			break;

		case 'delete':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id']);

			//$PDOdb->db->debug=true;
			$assetOf->delete($PDOdb);


			setEventMessage($langs->trans('OFAssetDeleted', $assetOf->ref));

			header('Location: '.dol_buildpath('/of/liste_of.php?delete_ok=1',1));
			exit;

			break;

		case 'createDocOF':

			$id_of = $_REQUEST['id'];

			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $id_of, false);

			if(empty($conf->global->OF_PRINT_IN_PDF)) {
				generateODTOF($PDOdb, $assetOf, true);

			}
			else {

				$TOFToGenerate = array($assetOf->rowid);

				if($conf->global->ASSET_CONCAT_PDF) $assetOf->getListeOFEnfants($PDOdb, $TOFToGenerate, $assetOf->rowid);
	//			var_dump($TOFToGenerate);exit;
				foreach($TOFToGenerate as $id_of) {

					$assetOf=new TAssetOF;
					$assetOf->load($PDOdb, $id_of, false);
					//echo $id_of;
					$TRes[] = generateODTOF($PDOdb, $assetOf);
					//echo '...ok<br />';
				}

				$TFilePath = get_tab_file_path($TRes);
			//	var_dump($TFilePath);exit;
				if($conf->global->ASSET_CONCAT_PDF) {
					ob_start();
					$pdf=pdf_getInstance();
					if (class_exists('TCPDF'))
					{
						$pdf->setPrintHeader(false);
						$pdf->setPrintFooter(false);
					}
					$pdf->SetFont(pdf_getPDFFont($langs));

					if ($conf->global->MAIN_DISABLE_PDF_COMPRESSION) $pdf->SetCompression(false);
					//$pdf->SetCompression(false);

					$pagecount = concatPDFOF($pdf, $TFilePath);

					if ($pagecount)
					{
						$pdf->Output($TFilePath[0],'F');
						if (! empty($conf->global->MAIN_UMASK))
						{
							@chmod($file, octdec($conf->global->MAIN_UMASK));
						}
					}
					ob_clean();
				}

				header("Location: ".DOL_URL_ROOT."/document.php?modulepart=of&entity=1&file=".$TRes[0]['dir_name']."/".$TRes[0]['num_of'].".pdf");
			}

			break;

		case 'addAssetLink':
			$assetOf=new TAssetOF;
            $assetOf->load($PDOdb, __get('id', 0, 'int'));

			$idLine = __get('idLine', 0, 'int');
			$idAsset = __get('idAsset', 0, 'int');

			if ($idLine && $idAsset)
			{
				$find = false;
				foreach ($assetOf->TAssetOFLine as $TAssetOFLine)
				{
					if ($TAssetOFLine->getId() == $idLine)
					{
						$find = true;

						$asset = new TAsset;
						$asset->load($PDOdb, $idAsset);
						$TAssetOFLine->addAssetLink($asset);
						break;
					}
				}

				if (!$find) setEventMessage($langs->trans('error_of_on_id_asset'), 'errors');
			}
			else
			{
				setEventMessage($langs->trans('error_of_wrong_id_asset'), 'errors');
			}

           _fiche($PDOdb, $assetOf, 'edit');

			break;

        case 'deleteAssetLink':
            $assetOf=new TAssetOF;
            $assetOf->load($PDOdb, __get('id', 0, 'int'));

			$idLine = __get('idLine', 0, 'int');
			$idAsset = __get('idAsset', 0, 'int');

			if ($idLine && $idAsset)
			{
				TAsset::del_element_element($PDOdb, $idLine, $idAsset, 'TAsset');
			}
			else
			{
				setEventMessage($langs->trans('error_of_no_ids'), 'errors');
			}

           _fiche($PDOdb, $assetOf, 'edit');

           break;

		default:

			$assetOf=new TAssetOF;
			if(GETPOST('id')>0) $assetOf->load($PDOdb, GETPOST('id'), false);
			else if(GETPOST('ref')!='') $assetOf->loadBy($PDOdb, GETPOST('ref'), 'numero', false);

			_fiche($PDOdb, $assetOf, 'view');

			break;
	}

}

function mergeObjectAttr(&$prod, &$Tab, $prefix='object_attr_')
{
	foreach ($prod as $key => $value)
	{
		if (is_object($value)) continue;
		else if (is_array($value)) mergeObjectAttr($value, $Tab, 'object_attr_'.$key.'_');
		else $Tab[$prefix.$key] = $value;
	}
}

function generateODTOF(&$PDOdb, &$assetOf, $direct= false) {

	global $db,$conf, $TProductCachegenerateODTOF,$langs;

	$TBS=new TTemplateTBS();
	dol_include_once("/product/class/product.class.php");

	$TToMake = array(); // Tableau envoyé à la fonction render contenant les informations concernant les produit à fabriquer
	$TNeeded = array(); // Tableau envoyé à la fonction render contenant les informations concernant les produit nécessaires
	$TWorkstations = array(); // Tableau envoyé à la fonction render contenant les informations concernant les stations de travail
	$TWorkstationUser = array(); // Tableau de liaison entre les postes et les utilisateurs
	$TWorkstationTask = array(); // Tableau de liaison entre les postes et les tâches 'mode opératoire'
	$TAssetWorkstation = array(); // Tableau de liaison entre les composants et les postes de travails
	$TControl = array(); // Tableau de liaison entre l'OF et les controles associés

	$societe = new Societe($db);
	$societe->fetch($assetOf->fk_soc);

	//pre($societe,true); exit;

	if (!empty($conf->quality->enabled))
	{
		$TControl = $assetOf->getControlPDF($PDOdb);
	}

	if(empty($TProductCachegenerateODTOF))$TProductCachegenerateODTOF=array();

	// On charge les tableaux de produits à fabriquer, et celui des produits nécessaires
	foreach($assetOf->TAssetOFLine as $k=>&$v) {

		if(!isset($TProductCachegenerateODTOF[$v->fk_product])) {

			$prod_cache = new Product($db);
			if($prod_cache->fetch($v->fk_product)>0) {
				$prod_cache->fetch_optionals($prod_cache->id);
				$TProductCachegenerateODTOF[$v->fk_product]=$prod_cache;
			}
		}
		else{
			//echo 'cache '.$v->fk_product.':'.$TProductCachegenerateODTOF[$v->fk_product]->ref.' / '.$TProductCachegenerateODTOF[$v->fk_product]->id.'<br />';
		}

		$prod = &$TProductCachegenerateODTOF[$v->fk_product];

		if($conf->nomenclature->enabled){

				$n = new TNomenclature;

				if(!empty($v->fk_nomenclature)) {
					$n->load($PDOdb, $v->fk_nomenclature);
					$TTypesProductsNomenclature = $n->getArrayTypesProducts();
				}

		}

		$qty = !empty($v->qty_needed) ? $v->qty_needed : $v->qty;

		if(!empty($conf->{ ATM_ASSET_NAME }->enabled)) {
			$TAssetType = new TAsset_type;
			$TAssetType->load($PDOdb, $prod->array_options['options_type_asset']);
			$unitLabel = ($TAssetType->measuring_units == 'unit' || $TAssetType->gestion_stock == 'UNIT') ? $langs->transnoentities('unit_s_') : measuring_units_string($prod->weight_units,'weight');

		}
		else{
			$unitLabel = $langs->transnoentities('unit_s_');
		}

		$TAsset = $v->getAssetLinked($PDOdb);
		if($v->type == "TO_MAKE") {
			$TToMake[$k] = array(
				'type' => $v->type
				, 'qte' => $v->qty.' '.utf8_decode($unitLabel) //pour les TO_MAKE, c forcément qty (valeur écran) qui est ok
				, 'nomProd' => $prod->ref
				, 'designation' => utf8_decode(dol_string_nohtmltag($prod->label))
				, 'dateBesoin' => date("d/m/Y", $assetOf->date_besoin)
				, 'lot_number' => $v->lot_number ? "\n(Lot numero ".$v->lot_number.")" : ""
				, 'value_lot_number' => $v->lot_number
				, 'code_suivi_ponderal' => $prod->array_options['options_suivi_ponderal'] ? "\n".$prod->array_options['options_suivi_ponderal'] : "\n(Aucun)"
				, 'TAsset' => $TAsset
				, 'TAssetStr' => _getSerialNumbers($TAsset)
			);

			mergeObjectAttr($prod, $TToMake[$k]);
		}
		else if($v->type == "NEEDED") {
			$TNeeded[$k] = array(
				'type' => $conf->nomenclature->enabled ? $TTypesProductsNomenclature[$v->fk_product] : $v->type
				, 'qte' => $qty
				, 'nomProd' => $prod->ref
				, 'designation' => utf8_decode($prod->label)
				, 'dateBesoin' => date("d/m/Y", $assetOf->date_besoin)
				, 'poids' => ($prod->weight) ? $prod->weight : 1
				, 'unitPoids' => utf8_decode($unitLabel)
				, 'finished' => $prod->finished?"PM":"MP"
				, 'lot_number' => $v->lot_number ? "\n(Lot numero ".$v->lot_number.")" : ""
				, 'value_lot_number' => $v->lot_number
				, 'code_suivi_ponderal' => $prod->array_options['options_suivi_ponderal'] ? "\n(Code suivi ponderal : ".$prod->array_options['options_suivi_ponderal'].")" : ""
				, 'note_private' => utf8_decode($v->note_private)
				, 'TAsset' => $TAsset
				, 'TAssetStr' => _getSerialNumbers($TAsset)
			);

			mergeObjectAttr($prod, $TNeeded[$k]);

			if (!empty($conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED))
			{
				$TAssetWorkstation[$k] = array(
					'nomProd'=>utf8_decode($prod->label)
					,'workstations'=>utf8_decode($v->getWorkstationsPDF($db))
				);
			}

		}

	}
//exit;
	// On charge le tableau d'infos sur les stations de travail de l'OF courant
	foreach($assetOf->TAssetWorkstationOF as $k => $v) {

	    // numéroOF_nom ou id du poste
	    $code = $assetOf->numero . "_" . $v->id ;
	    
		$TWorkstations[] = array(
			'libelle' => utf8_decode($v->ws->libelle)
			//,'nb_hour_max' => utf8_decode($v->ws->nb_hour_max)
			,'nb_hour_max' => utf8_decode($v->ws->nb_hour_capacity)
			,'nb_hour_real' => utf8_decode($v->nb_hour_real)
			,'nb_hour_preparation' => utf8_decode($v->nb_hour_prepare)
			,'nb_heures_prevues' => utf8_decode($v->nb_hour)
			,'note_private' => utf8_decode($v->note_private)
		    	,'barcode' => getBarCode($code)
		);

		if (!empty($conf->global->ASSET_DEFINED_USER_BY_WORKSTATION))
		{
			$TWorkstationUser[] = array(
				'workstation'=>utf8_decode($v->ws->libelle)
				,'users'=>utf8_decode($v->getUsersPDF($PDOdb))
			);
		}

		if (!empty($conf->global->ASSET_DEFINED_OPERATION_BY_WORKSTATION))
		{
			$TWorkstationTask[] = array(
				'workstation'=>utf8_decode($v->ws->libelle)
				,'tasks'=>utf8_decode($v->getTasksPDF($PDOdb))
			);
		}

	}

	$dirName = 'OF'.$assetOf->rowid.'('.date("d_m_Y").')';
	$dir = DOL_DATA_ROOT.( $conf->entity>1 ? '/'.$conf->entity : ''  ).'/of/'.$dirName.'/';

	@mkdir($dir, 0777, true);

	if(defined('TEMPLATE_OF')){
		$template = TEMPLATE_OF;
	}
	else{
		if (!empty($conf->global->TEMPLATE_OF)) $template = $conf->global->TEMPLATE_OF;
		else $template = "templateOF.odt";
		//$template = "templateOF.doc";
	}

	$refcmd = '';
	if(!empty($assetOf->fk_commande)) {
		$cmd = new Commande($db);
		$cmd->fetch($assetOf->fk_commande);
		$refcmd = $cmd->ref;
	}

	$barcode_pic = getBarCodePicture($assetOf);
//var_dump($TToMake);

	$locationTemplate = DOL_DATA_ROOT.'/of/template/'.$template;
	if (!file_exists($locationTemplate)) $locationTemplate = dol_buildpath('/of/exempleTemplate/'.$template);

	$logo = '';
	if(defined('MAIN_INFO_SOCIETE_LOGO')){
	    $logo = DOL_DATA_ROOT."/mycompany/logos/".MAIN_INFO_SOCIETE_LOGO;
	}
	 
	
	$file_path = $TBS->render($locationTemplate
		,array(
			'lignesToMake'=>$TToMake
			,'lignesNeeded'=>$TNeeded
			,'lignesWorkstation'=>$TWorkstations
			,'lignesAssetWorkstations'=>$TAssetWorkstation
			,'lignesUser'=>$TWorkstationUser
			,'lignesTask'=>$TWorkstationTask
			,'lignesControl'=>$TControl
		)
		,array(
			'date'=>date("d/m/Y")
			,'time'=>dol_now()
			,'numeroOF'=>$assetOf->numero
//			,'statutOF'=>utf8_decode(TAssetOF::$TStatus[$assetOf->status])
			,'statutOF'=>$langs->transnoentitiesnoconv(TAssetOF::$TStatus[$assetOf->status])
			,'prioriteOF'=>utf8_decode(TAssetOF::$TOrdre[$assetOf->ordre])
			,'date_lancement'=>date("d/m/Y", $assetOf->date_lancement)
			,'date_besoin'=>date("d/m/Y", $assetOf->date_besoin)
			,'refcmd'=>$refcmd
			,'societe'=>$societe->name
		    	,'logo'=> $logo
			,'barcode'=>$barcode_pic
			,'use_lot'=>(int) $conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED
			,'defined_user'=>(int) $conf->global->ASSET_DEFINED_USER_BY_WORKSTATION
			,'defined_task'=>(int) $conf->global->ASSET_DEFINED_OPERATION_BY_WORKSTATION
			,'use_control'=>(int) $conf->global->ASSET_USE_CONTROL
			,'note_of'=>$assetOf->note
		)
		,array()
		,array(
			'outFile'=>$dir.$assetOf->numero.".odt"
			,"convertToPDF"=>(!empty($conf->global->OF_PRINT_IN_PDF))
			//'outFile'=>$dir.$assetOf->numero.".doc"
		)

	);

	if($direct) {
		if (!empty($conf->global->OF_PRINT_IN_PDF)) header("Location: ".DOL_URL_ROOT."/document.php?modulepart=of&entity=1&file=".$dirName."/".$assetOf->numero.".pdf");
		else header("Location: ".DOL_URL_ROOT."/document.php?modulepart=of&entity=1&file=".$dirName."/".$assetOf->numero.".odt");

	}
	else {
		return array('file_path'=>$file_path, 'dir_name'=>$dirName, 'num_of'=>$assetOf->numero);
	}

}

function _getSerialNumbers($TAsset)
{
	$str = '';
	if (!empty($TAsset))
	{
		foreach ($TAsset as &$asset)
		{
			$str.= '- ['.$asset->lot_number.'] '.$asset->serial_number."\n";
		}
	}

	return $str;
}

function drawCross($im, $color, $x, $y){

	imageline($im, $x - 10, $y, $x + 10, $y, $color);
    imageline($im, $x, $y- 10, $x, $y + 10, $color);

}

function getBarCodePicture(&$assetOf) {

	dol_include_once('/of/php_barcode/php-barcode.php');

	$code = $assetOf->numero;

  	$fontSize = 10;   // GD1 in px ; GD2 in point
  	$marge    = 10;   // between barcode and hri in pixel
  	$x        = 145;  // barcode center
  	$y        = 50;  // barcode center
  	$height   = 50;   // barcode height in 1D ; module size in 2D
  	$width    = 2;    // barcode height in 1D ; not use in 2D
  	$angle    = 0;   // rotation in degrees : nb : non horizontable barcode might not be usable because of pixelisation

  	$type     = 'code128';

 	$im     = imagecreatetruecolor(300, 100);
  	$black  = ImageColorAllocate($im,0x00,0x00,0x00);
  	$white  = ImageColorAllocate($im,0xff,0xff,0xff);
  	$red    = ImageColorAllocate($im,0xff,0x00,0x00);
  	$blue   = ImageColorAllocate($im,0x00,0x00,0xff);
  	imagefilledrectangle($im, 0, 0, 300, 300, $white);

  	$data = Barcode::gd($im, $black, $x, $y, $angle, $type, array('code'=>$code), $width, $height);
  	if ( isset($font) ){
    	$box = imagettfbbox($fontSize, 0, $font, $data['hri']);
		$len = $box[2] - $box[0];
		Barcode::rotate(-$len / 2, ($data['height'] / 2) + $fontSize + $marge, $angle, $xt, $yt);
		imagettftext($im, $fontSize, $angle, $x + $xt, $y + $yt, $blue, $font, $data['hri']);
	}

	$tmpfname = tempnam(sys_get_temp_dir(), 'barcode_pic');
	imagepng($im, $tmpfname);
	imagedestroy($im);

	return $tmpfname;

}


function getBarCode($code='') {
    global $conf,$db;
    $modulepart = 'barcode';
    $generator='tcpdfbarcode';
    
    $encoding='C128';
    
    $readable="Y";
        
    $dirbarcode=array_merge(array("/core/modules/barcode/doc/"),$conf->modules_parts['barcode']);
        
    $result='';
        
    foreach($dirbarcode as $reldir)
    {
        $dir=dol_buildpath($reldir,0);
        $newdir=dol_osencode($dir);
        
        // Check if directory exists (we do not use dol_is_dir to avoid loading files.lib.php)
        if (! is_dir($newdir)) continue;
        
        $result=@include_once $newdir.$generator.'.modules.php';
        if ($result) break;
    }
        
    // Load barcode class
    $classname = "mod".ucfirst($generator);
    $module = new $classname($db);
    if ($module->encodingIsSupported($encoding))
    {
        if($module->writeBarCode($code,$encoding,$readable)>0)
        {
            return $conf->barcode->dir_temp.'/barcode_'.$code.'_'.$encoding.'.png';
        }
    }
    
    return '';
}

function get_tab_file_path($TRes) {

	$tab = array();

	foreach($TRes as $TData) {
		$tab[] = strtr($TData['file_path'], array('.odt'=>'.pdf'));
	}

	return $tab;

}

function _get_line_order_extrafields($fk_commandedet) {
    global $db, $langs,$conf;

    if($fk_commandedet<=0) return '';

    dol_include_once('/commande/class/commande.class.php');

    $line = new OrderLine($db);
    $line->fetch_optionals($fk_commandedet);

    $extrafieldsline = new ExtraFields($db);
    $extrafieldsline->fetch_name_optionals_label($line->table_element);

    if(!empty($conf->global->OF_SHOW_LINE_ORDER_EXTRAFIELD_JUST_THEM)) {
        $TIn = explode(',', $conf->global->OF_SHOW_LINE_ORDER_EXTRAFIELD_JUST_THEM);

        foreach($extrafieldsline->attribute_label as $field=>$data) {

            if(!in_array($field, $TIn)) {
                unset($extrafieldsline->attribute_label[$field]);

            }

        }

    }

    return '<table class="noborder">'.$line->showOptionals($extrafieldsline,'view',array('style'=>'oddeven','colspan'=>1)).'</table>';

}

function _fiche_ligne(&$form, &$of, $type){
	global $db, $conf, $langs,$hookmanager,$user;
//TODO rules guys ! To Facto ! AA
	$formProduct = new FormProduct($db);

    $PDOdb=new TPDOdb;
	$TRes = array();
	foreach($of->TAssetOFLine as $k=>&$TAssetOFLine){
		$product = &$TAssetOFLine->product;
        if(is_null($product)) {
            $product=new Product($db);
            $product->fetch($TAssetOFLine->fk_product);
			$product->fetch_optionals();
        }


		$conditionnement = $TAssetOFLine->conditionnement;

		if(!empty($conf->{ ATM_ASSET_NAME }->enabled)) {
			$TAssetType = new TAsset_type;
			$TAssetType->load($PDOdb, $product->array_options['options_type_asset']);
			$conditionnement_unit = ($TAssetType->measuring_units == 'unit' || $TAssetType->gestion_stock == 'UNIT') ? 'unité(s)' : $TAssetOFLine->libUnite();
		}
		else{
			$conditionnement_unit = 'unité(s)'; // TODO translate
		}
		//$conditionnement_unit = $TAssetOFLine->libUnite();

		if($TAssetOFLine->measuring_units!='unit' && !empty($TAssetOFLine->measuring_units)) {
            $conditionnement_label = ' / '.$conditionnement." ".$conditionnement_unit;
            $conditionnement_label_edit = ' par '.$form->texte('', 'TAssetOFLine['.$k.'][conditionnement]', $conditionnement, 5,5,'','').$conditionnement_unit;

		}
        else{
            $conditionnement_label=$conditionnement_label_edit='';
        }

        if($TAssetOFLine->type == 'NEEDED' && $type == 'NEEDED'){
			$stock_needed = TAssetOF::getProductStock($product->id);
			$stock_theo = TAssetOF::getProductStock($product->id,0,true,true);

			$label = $product->getNomUrl(1).' '.$product->label;
			$label.= ' - '.$langs->trans("Stock") . ' : ' . ($stock_needed>0 ? $stock_needed : '<span style="color:red;font-weight:bold;">'.$stock_needed.'</span>');
			$label.= ' - '.$langs->trans("StockTheo") . ' : ' . ($stock_theo>0 ? $stock_theo : '<span style="color:red;font-weight:bold;">'.$stock_theo.'</span>');
			$label.= _fiche_ligne_asset($PDOdb,$form, $of, $TAssetOFLine, 'NEEDED');

			$TLine = array(
					'id'=>$TAssetOFLine->getId()
					,'idprod'=>$form->hidden('TAssetOFLine['.$k.'][fk_product]', $product->id)
					,'lot_number'=>($of->status=='DRAFT') ? $form->texte('', 'TAssetOFLine['.$k.'][lot_number]', $TAssetOFLine->lot_number, 15,50,'type_product="NEEDED" fk_product="'.$product->id.'" rel="lot-'.$TAssetOFLine->getId().'" ','TAssetOFLineLot') : $TAssetOFLine->lot_number
					,'libelle'=>$label
			        ,'cost'=>(empty($user->rights->of->of->price) ? '' : price(price2num($TAssetOFLine->compo_planned_cost,'MT'),0,'',1,-1,-1,$conf->currency).$conditionnement_label)
    			    ,'qty_needed'=>$TAssetOFLine->qty_needed
    			    ,'qty'=>(($of->status=='DRAFT' && $form->type_aff== "edit") ? $form->texte('', 'TAssetOFLine['.$k.'][qty]', $TAssetOFLine->qty, 5,50) : $TAssetOFLine->qty)
					,'qty_planned'=>$TAssetOFLine->qty
					,'qty_used'=>((($of->status=='OPEN' || $of->status == 'CLOSE') && $form->type_aff) ? $form->texte('', 'TAssetOFLine['.$k.'][qty_used]', $TAssetOFLine->qty_used, 5,50) : $TAssetOFLine->qty_used.(empty($user->rights->of->of->price) ? '' : ' x '.price(price2num($TAssetOFLine->compo_cost,'MT'),0,'',1,-1,-1,$conf->currency)))
					,'qty_toadd'=> $TAssetOFLine->qty - $TAssetOFLine->qty_used
					,'workstations'=> $conf->workstation->enabled ? $TAssetOFLine->visu_checkbox_workstation($db, $of, $form, 'TAssetOFLine['.$k.'][fk_workstation][]') : ''
					,'delete'=> ($form->type_aff=='edit' && ($of->status=='DRAFT' || (!empty($conf->global->OF_USE_DESTOCKAGE_PARTIEL) && $of->status!='CLOSE' && empty($TAssetOFLine->qty_used))) ) ? '<a href="javascript:deleteLine('.$TAssetOFLine->getId().',\'NEEDED\');">'.img_picto('Supprimer', 'delete.png').'</a>' : ''
					,'fk_entrepot' => !empty($conf->global->ASSET_MANUAL_WAREHOUSE) && ($of->status == 'DRAFT' || $of->status == 'VALID') && $form->type_aff == 'edit' ? $formProduct->selectWarehouses($TAssetOFLine->fk_entrepot, 'TAssetOFLine['.$k.'][fk_entrepot]', '', 0, 0, $TAssetOFLine->fk_product) : $TAssetOFLine->getLibelleEntrepot($PDOdb)
		            ,'note_private'=>(($of->status=='DRAFT') ? $form->zonetexte('', 'TAssetOFLine['.$k.'][note_private]', $TAssetOFLine->note_private, 50,1) : $TAssetOFLine->note_private)

			);

			mergeObjectAttr($product, $TLine);
			$action = $form->type_aff;
			$parameter=array('of'=>&$of, 'line'=>&$TLine,'type'=>'NEEDED');
			$res = $hookmanager->executeHooks('lineObjectOptions', $parameter, $TAssetOFLine, $action);

			if($res>0 && !empty($hookmanager->resArray)) {

				$TLine = $hookmanager->resArray;

			}

			$TRes[] = $TLine;
		}
		elseif($TAssetOFLine->type == "TO_MAKE" && $type == "TO_MAKE"){
			if(empty($TAssetOFLine->TFournisseurPrice)) {

				$TAssetOFLine->loadFournisseurPrice($PDOdb);
			}



			// Permet de sélectionner par défaut "(Fournisseur "Interne" => Fabrication interne)" si le produit TO_MAKE n'a pas de stock lorsqu'on est en mode edit et que la ligne TO_MAKE n'a pas encore de prix fournisseur enregistré
			dol_include_once('/product/class/product.class.php');
			$p = new Product($db);
			$selected = 0;

			if($p->fetch($TAssetOFLine->fk_product)) {
				$p->load_stock();
				$p->stock_reel;
				if($TAssetOFLine->type === 'TO_MAKE' && $p->stock_reel <= 0 && $_REQUEST['action'] === 'edit') $selected = -2;
			}
			// *************************************************************




			$Tab=array();
			foreach($TAssetOFLine->TFournisseurPrice as &$objPrice) {

				$label = "";

				//Si on a un prix fournisseur pour le produit
				if($objPrice->price > 0)
				{
					$unit = $objPrice->quantity == 1 ? $langs->trans('OFUnit') : $langs->trans('OFUnits');
					$label .= floatval($objPrice->price).' '.$conf->currency.' - '.$objPrice->quantity.' '.$unit.' -';
				}

				//Affiche le nom du fournisseur
				$label .= ' ('.$langs->trans('OFSupplierLineName', utf8_encode ($objPrice->name));

				//Prix unitaire minimum si renseigné dans le PF
				if($objPrice->quantity > 0){
					$label .= ' '.$langs->trans('OFSupplierLineMinQty', $objPrice->quantity);
				}

				//Affiche le type du PF :
				if($objPrice->compose_fourni){//			soit on fabrique les composants
					$label .= ' =>'.$langs->trans('OFSupplierLineComp');
				}
				elseif($objPrice->quantity <= 0){//			soit on a le produit finis déjà en stock
					$label .= ' =>'.$langs->trans('OFSupplierLineStockRemoval');
				}

				if($objPrice->quantity > 0){//				soit on commande a un fournisseur
					$label .= ' =>'.$langs->trans('OFSupplierLineSupOrder');
				}

				$label .= ")";

				$Tab[ $objPrice->rowid ] = array(
												'label' => $label,
												'compose_fourni' => ($objPrice->compose_fourni) ? $objPrice->compose_fourni : 0
											);

			}

			if ($conf->nomenclature->enabled) {
				dol_include_once('/nomenclature/class/nomenclature.class.php');

				if ($of->status == 'DRAFT' && !$TAssetOFLine->nomenclature_valide) {
					$TNomenclature = TNomenclature::get($PDOdb, $TAssetOFLine->fk_product, true);

					if(count($TNomenclature) > 0 ) {
						$nomenclature = '<div>'.$form->combo('', 'TAssetOFLine['.$k.'][fk_nomenclature]', $TNomenclature, $TAssetOFLine->fk_nomenclature);

						if ($form->type_aff=='edit') {
							$nomenclature .= '<a href="#" class="valider_nomenclature" data-id_of="' . $of->getId() . '" data-product="' . $TAssetOFLine->fk_product . '" data-of_line="' . $TAssetOFLine->rowid . '">'.$langs->trans('OFValidate').'</a>';
						}
						else {
							$nomenclature .= ' - <span class="error">'.img_picto('','warning').' '.$langs->trans('NomenclatureToSelect').' '.img_picto('','warning').'</span>';
						}

						$nomenclature.='</div>';
					}
					else{
						$nomenclature='';
					}

				} else {
					$n=new TNomenclature;
					$n->load($PDOdb, $TAssetOFLine->fk_nomenclature);
					$nomenclature = '<div>' .(String) $n;
					$picture = ($TAssetOFLine->nomenclature_valide ? 'ok.png' : 'no.png');
					$nomenclature .= ' <img src="img/' . $picture . '" style="padding-left: 2px; vertical-align: middle;" /></div>';
				}


			}

			//($of->status=='DRAFT') ? $form->combo('', 'TAssetOFLine['.$k.'][fk_nomenclature]', _getArrayNomenclature($PDOdb, $TAssetOFLine), $TAssetOFLine->fk_nomenclature) : _getTitleNomenclature($PDOdb, $TAssetOFLine->fk_nomenclature)
			$stock_tomake = TAssetOF::getProductStock($product->id);

			$TLine= array(
				'id'=>$TAssetOFLine->getId()
				,'idprod'=>$form->hidden('TAssetOFLine['.$k.'][fk_product]', $product->id)
				,'lot_number'=>($of->status=='DRAFT') ? $form->texte('', 'TAssetOFLine['.$k.'][lot_number]', $TAssetOFLine->lot_number, 15,50,'type_product="TO_MAKE" fk_product="'.$product->id.'"','TAssetOFLineLot') : $TAssetOFLine->lot_number
				,'libelle'=>$product->getNomUrl(1).' '.$product->label.' - '.$langs->trans("Stock")." : "
				        .$stock_tomake._fiche_ligne_asset($PDOdb,$form, $of, $TAssetOFLine, 'TO_MAKE')
			        ,'nomenclature'=>$nomenclature
				,'addneeded'=> ($form->type_aff=='edit' && $of->status=='DRAFT') ? '<a href="#null" statut="'.$of->status.'" onclick="updateQtyNeededForMaking('.$of->getId().','.$TAssetOFLine->getId().',this);">'.img_picto($langs->trans('UpdateNeededQty'), 'object_technic.png').'</a>' : ''
				,'qty'=>($of->status=='DRAFT') ? $form->texte('', 'TAssetOFLine['.$k.'][qty]', $TAssetOFLine->qty, 5,5,'','').$conditionnement_label_edit : $TAssetOFLine->qty.$conditionnement_label
				,'qty_used'=>($of->status=='OPEN' || $of->status=='CLOSE') ? $form->texte('', 'TAssetOFLine['.$k.'][qty_used]', $TAssetOFLine->qty_used, 5,5,'','').$conditionnement_label_edit : $TAssetOFLine->qty_used.$conditionnement_label
				,'qty_non_compliant'=>((($of->status=='OPEN' || $of->status == 'CLOSE')) ? $form->texte('', 'TAssetOFLine['.$k.'][qty_non_compliant]', $TAssetOFLine->qty_non_compliant,  5,5,'','') : $TAssetOFLine->qty_non_compliant)
				,'fk_product_fournisseur_price' => $form->combo('', 'TAssetOFLine['.$k.'][fk_product_fournisseur_price]', $Tab, ($TAssetOFLine->fk_product_fournisseur_price != 0) ? $TAssetOFLine->fk_product_fournisseur_price : $selected, 1, '', 'style="max-width:250px;"')
				,'delete'=> ($form->type_aff=='edit' && $of->status=='DRAFT') ? '<a href="#null" onclick="deleteLine('.$TAssetOFLine->getId().',\'TO_MAKE\');">'.img_picto($langs->trans('Delete'), 'delete.png').'</a>' : ''
				,'fk_entrepot' => !empty($conf->global->ASSET_MANUAL_WAREHOUSE) && ($of->status == 'DRAFT' || $of->status == 'VALID' || $of->status == 'NEEDOFFER' || $of->status == 'ONORDER' || $of->status == 'OPEN') && $form->type_aff == 'edit' ? $formProduct->selectWarehouses($TAssetOFLine->fk_entrepot, 'TAssetOFLine['.$k.'][fk_entrepot]', '', 0, 0, $TAssetOFLine->fk_product) : $TAssetOFLine->getLibelleEntrepot($PDOdb)
			    ,'extrafields'=>(empty($conf->global->OF_SHOW_LINE_ORDER_EXTRAFIELD) ? '' : _get_line_order_extrafields($TAssetOFLine->fk_commandedet))
			);


			mergeObjectAttr($product, $TLine);
			$action = $form->type_aff;
			$parameter=array('of'=>&$of, 'line'=>&$TLine,'type'=>'TO_MAKE');
			$res = $hookmanager->executeHooks('lineObjectOptions', $parameter, $TAssetOFLine, $action);

			if($res>0 && !empty($hookmanager->resArray)) {

				$TLine = $hookmanager->resArray;

			}

			$TRes[] = $TLine;
		}
	}

	return $TRes;
}

function _fiche_ligne_asset(&$PDOdb,&$form,&$of, &$assetOFLine, $type='NEEDED')
{
    global $conf,$langs;
    $langs->load('assetatm@assetatm');
    
    if(empty($conf->global->USE_LOT_IN_OF) || empty($conf->{ ATM_ASSET_NAME }->enabled) ) return '';

    $TAsset = $assetOFLine->getAssetLinked($PDOdb);


    $r='<div>';

    if($of->status=='DRAFT' && $form->type_aff == 'edit' && $type=='NEEDED')
    {
    	$url = dol_buildpath('/of/fiche_of.php?id='.$of->getId().'&idLine='.$assetOFLine->getId().'&action=addAssetLink&idAsset=', 1);
		// Pour le moment au limite au besoin, la création reste en dure, à voir
		$r.=$form->texte('', 'TAssetOFLine['.$assetOFLine->getId().'][new_asset]', '', 10,255,' title="Ajouter un équipement" fk_product="'.$assetOFLine->fk_product.'" rel="add-asset" fk-asset-of-line="'.$assetOFLine->getId().'" ')
			.'<a href="" base-href="'.$url.'">'.img_right($langs->trans('Link')).'</a>'
			.'<br/>';
    }
    foreach($TAsset as &$asset)
    {
        $r .= $asset->getNomUrl(1, 1, 2).((!empty($asset->dluo) && strtotime($asset->dluo) < time())?img_warning($langs->trans('Asset_DLUO_outdated')):'');

        if($of->status=='DRAFT' && $form->type_aff == 'edit' && $type=='NEEDED')
        {
            $r.=' <a href="?id='.$of->getId().'&idLine='.$assetOFLine->getId().'&idAsset='.$asset->getId().'&action=deleteAssetLink">'.img_delete($langs->trans('DeleteLink')).'</a>';
        }
    }

    $r.='</div>';

    return $r;

}

function _fiche(&$PDOdb, &$assetOf, $mode='edit',$fk_product_to_add=0,$fk_nomenclature=0) {
	global $langs,$db,$conf,$user,$hookmanager;
	/***************************************************
	* PAGE
	*
	* Put here all code to build page
	****************************************************/

	if($assetOf->entity != $conf->entity) {
	    accessforbidden($langs->trans('ErrorOFFromAnotherEntity'));


	}

	$parameters = array('id'=>$assetOf->getId());
	$reshook = $hookmanager->executeHooks('doActions',$parameters,$assetOf,$mode);    // Note that $action and $object may have been modified by hook

	//pre($assetOf,true);
	llxHeader('',$langs->trans('OFAsset'),'','');
	print dol_get_fiche_head(ofPrepareHead( $assetOf, 'assetOF') , 'fiche', $langs->trans('OFAsset'), -1);

	?><style type="text/css">
		#assetChildContener .OFMaster {

			background:#fff;
			-webkit-box-shadow: 4px 4px 5px 0px rgba(50, 50, 50, 0.52);
			-moz-box-shadow:    4px 4px 5px 0px rgba(50, 50, 50, 0.52);
			box-shadow:         4px 4px 5px 0px rgba(50, 50, 50, 0.52);

            padding:5px; // Visualisation améliorée
			margin-bottom:20px;
		}

	</style>
		<div class="OFContent" rel="<?php echo $assetOf->getId() ?>">	<?php

	$TPrixFournisseurs = array();

	//$form=new TFormCore($_SERVER['PHP_SELF'],'formeq'.$assetOf->getId(),'POST');

	//Affichage des erreurs
	if(!empty($assetOf->errors)){
		?>
		<br><div class="error">
		<?php
		foreach($assetOf->errors as $error){
			echo $error."<br>";
			setEventMessage($error,'errors');
		}
		$assetOf->errors = array();
		?>
		</div><br>
		<?php


	}

	$form=new TFormCore();
	$form->Set_typeaff($mode);

	$doliform = new Form($db);

	if(!empty($_REQUEST['fk_product'])) echo $form->hidden('fk_product', $_REQUEST['fk_product']);

	$TBS=new TTemplateTBS();
	$liste=new TListviewTBS('asset');

	$TBS->TBS->protect=false;
	$TBS->TBS->noerr=true;

	$PDOdb = new TPDOdb;

	$TNeeded = array();
	$TToMake = array();

	$TNeeded = _fiche_ligne($form, $assetOf, "NEEDED");
	$TToMake = _fiche_ligne($form, $assetOf, "TO_MAKE");

	$TIdCommandeFourn = $assetOf->getElementElement($PDOdb);

	$HtmlCmdFourn = '';

	if(count($TIdCommandeFourn)){
		foreach($TIdCommandeFourn as $idcommandeFourn){
			$cmd = new CommandeFournisseur($db);
			$cmd->fetch($idcommandeFourn);

			$HtmlCmdFourn .= $cmd->getNomUrl(1)." - ".$cmd->getLibStatut(0);
		}
	}
	
	$select_product = '';
	if (empty($_REQUEST['fk_product']))
	{
		ob_start();
		$doliform->select_produits('','fk_product','',$conf->product->limit_size,0,-1,2,'',3,array(),0,1,0,'minwidth300');
		$select_product = ob_get_clean();

		?>
		<script type="text/javascript">
                $(document).on('keypress',function(e) {
                       if ($('input:focus').length == 0) {
                            $('#fk_product').select2('open');
                        }
                });
        </script>
        <?php
        if(!empty($conf->global->OF_ONE_SHOOT_ADD_PRODUCT)){ //conf caché
        ?>

            <script type="text/javascript">
                $(document).ready(function(){

                    let contentBtAdd = '<?php echo $langs->trans('BtAdd'); ?>';
                    $('#fk_product').on("select2:select", function(e) {
                        let select = $("select[name='fk_nomenclature'] option");
                        if(select.length){//check if element exist
                            if(select.length <= 1 ){ //s'il n'y a qu'une seule nomenclature ou moins on ajoute la ligne à la volée
                                $("button:contains('"+contentBtAdd+"')").click();
                            }
                        }else {
                             $("button:contains('"+contentBtAdd+"')").click();
                        }
                    });
                });

            </script>
        <?php
        }
	}
	
	$Tid = array();
	//$Tid[] = $assetOf->rowid;
	if($assetOf->getId()>0) $assetOf->getListeOFEnfants($PDOdb, $Tid);

	$TWorkstation=array();
	foreach($assetOf->TAssetWorkstationOF as $k => &$TAssetWorkstationOF) {
		$ws = &$TAssetWorkstationOF->ws;

		$TWorkstation[]=array(
				'libelle'=>$ws->getNomUrl(1)
				,'fk_user' => visu_checkbox_user($PDOdb, $form, $ws->fk_usergroup, $TAssetWorkstationOF->users, 'TAssetWorkstationOF['.$k.'][fk_user][]', $assetOf->status)
				,'fk_project_task' => visu_project_task($db, $TAssetWorkstationOF->fk_project_task, $form->type_aff, 'TAssetWorkstationOF['.$k.'][progress]')
				,'fk_task' => visu_checkbox_task($PDOdb, $form, $TAssetWorkstationOF->fk_asset_workstation, $TAssetWorkstationOF->tasks,'TAssetWorkstationOF['.$k.'][fk_task][]', $assetOf->status)
				,'nb_hour'=> ($assetOf->status=='DRAFT' && $mode == "edit") ? $form->texte('','TAssetWorkstationOF['.$k.'][nb_hour]', $TAssetWorkstationOF->nb_hour,3,10) : (($conf->global->ASSET_USE_CONVERT_TO_TIME ? convertSecondToTime($TAssetWorkstationOF->nb_hour * 3600) : price($TAssetWorkstationOF->nb_hour) ). (empty($user->rights->of->of->price) ? '' : ' x '. price($TAssetWorkstationOF->thm,0,'',1,-1,-1,$conf->currency) ))
				,'nb_hour_real'=>($assetOf->status=='OPEN' && $mode == "edit") ? $form->texte('','TAssetWorkstationOF['.$k.'][nb_hour_real]', $TAssetWorkstationOF->nb_hour_real,3,10) : (($conf->global->ASSET_USE_CONVERT_TO_TIME ? convertSecondToTime($TAssetWorkstationOF->nb_hour_real * 3600) : price($TAssetWorkstationOF->nb_hour_real)) . (empty($user->rights->of->of->price) ? '' : ' x '. price($TAssetWorkstationOF->thm,0,'',1,-1,-1,$conf->currency) ) )
				,'nb_days_before_beginning'=>($assetOf->status!='CLOSE' && $mode == "edit") ? $form->texte('','TAssetWorkstationOF['.$k.'][nb_days_before_beginning]', $TAssetWorkstationOF->nb_days_before_beginning,3,10) : $TAssetWorkstationOF->nb_days_before_beginning
				,'delete'=> ($mode=='edit' && $assetOf->status=='DRAFT') ? '<a href="javascript:deleteWS('.$assetOf->getId().','.$TAssetWorkstationOF->getId().');">'.img_picto($langs->trans('Delete'), 'delete.png').'</a>' : ''
				,'note_private'=>($assetOf->status=='DRAFT' && $mode == 'edit') ? $form->zonetexte('','TAssetWorkstationOF['.$k.'][note_private]', $TAssetWorkstationOF->note_private,50,1) : $TAssetWorkstationOF->note_private
				,'rang'=>($assetOf->status=='DRAFT' && $mode == "edit") ? $form->texte('','TAssetWorkstationOF['.$k.'][rang]', $TAssetWorkstationOF->rang,3,10)  : $TAssetWorkstationOF->rang
				,'id'=>$ws->getId()
		);

	}

	$client=new Societe($db);
	if($assetOf->fk_soc>0) $client->fetch($assetOf->fk_soc);

	$commande=new Commande($db);
	if($assetOf->fk_commande>0) $commande->fetch($assetOf->fk_commande);

	$select_commande = '';
	$resOrder = $db->query("SELECT rowid, ref,fk_statut FROM ".MAIN_DB_PREFIX."commande WHERE fk_statut IN (0,1,2,3) ORDER BY ref");
	if($resOrder === false ) {
		var_dump($db);exit;
	}
	$TIdCommande=array();
	while($obj = $db->fetch_object($resOrder)) {
		$TIdCommande[$obj->rowid] = $obj->ref.($obj->fk_statut == 0 ? ' ('.$langs->trans('Draft').')':'');
	}
	if(!empty($TIdCommande)) {
		$select_commande = $doliform->selectarray('fk_commande',$TIdCommande,$assetOf->fk_commande, 1);
	}

	$TOFParent = array_merge(array(0=>'')  ,$assetOf->getCanBeParent($PDOdb));

	$hasParent = false;
	if (!empty($assetOf->fk_assetOf_parent))
	{
		$TAssetOFParent = new TAssetOF;
		$TAssetOFParent->load($PDOdb, $assetOf->fk_assetOf_parent);
		$hasParent = true;
	}

	$parameters = array('id'=>$assetOf->getId());
	$reshook = $hookmanager->executeHooks('formObjectOptions',$parameters,$assetOf,$mode);    // Note that $action and $object may have been modified by hook

	if($fk_product_to_add>0) {
		$product_to_add = new Product($db);
		$product_to_add->fetch($fk_product_to_add);

		$link_product_to_add = $product_to_add->getNomUrl(1).' '.$product_to_add->label;
		$quantity_to_create = $form->texte('', 'quantity_to_create', 1, 3, 255);
	}
	else{
		$link_product_to_add = '';
		$quantity_to_create = '';
	}

	$TTransOrdre = array_map(array($langs, 'trans'),  TAssetOf::$TOrdre);

    $TTransStatus = array_map(array($langs, 'trans'), TAssetOf::$TStatus);

    $order_amount = $commande->total_ht; //$o n'existait pas
    if(!empty($conf->global->OF_SHOW_ORDER_LINE_PRICE)) {

        $line_to_make = $assetOf->getLineProductToMake();

        foreach($commande->lines as &$line) {

            if($line->id == $line_to_make->fk_commandedet) {
                $order_amount = $line->total_ht;
                break;
            }
        }

    }
    $TCommandes=array();
    if(!empty($conf->global->OF_MANAGE_ORDER_LINK_BY_LINE)){
        $displayOrders = '';
        $TLine_to_make = $assetOf->getLinesProductToMake();


        foreach($TLine_to_make as $line){
            if(!empty($line->fk_commandedet)){
                $commande = new Commande($db);
                $orderLine = new OrderLine($db);
                $orderLine->fetch($line->fk_commandedet);
                $commande->fetch($orderLine->fk_commande);
                $TCommandes[$orderLine->fk_commande] = $commande;

            }
            elseif(empty($displayOrders))$displayOrders = $commande->getNomUrl(1). ' : '.price($order_amount,0,$langs,1,-1,-1,$conf->currency);

        }
    }
    if(!empty($TCommandes)){
        foreach($TCommandes as $commande) $displayOrders .= '<div>'.$commande->getNomUrl(1). ' : '.price($commande->total_ht,0,$langs,1,-1,-1,$conf->currency).'</div>';
    }
    else $displayOrders = $commande->getNomUrl(1). ' : '.price($order_amount,0,$langs,1,-1,-1,$conf->currency);

	print $TBS->render('tpl/fiche_of.tpl.php'
		,array(
			'TNeeded'=>$TNeeded
			,'TTomake'=>$TToMake
			,'workstation'=>$TWorkstation
		)
		,array(
			'assetOf'=>array(
					'id'=> $assetOf->getId()
					,'numero'=> ($assetOf->getId() > 0) ? '<a href="fiche_of.php?id='.$assetOf->getId().'">'.$assetOf->getNumero($PDOdb).'</a>' : $assetOf->getNumero($PDOdb)
					,'ordre'=>$form->combo('','ordre',$TTransOrdre,$assetOf->ordre)
			        ,'fk_commande'=>!empty($conf->global->OF_MANAGE_ORDER_LINK_BY_LINE) ? (($assetOf->fk_commande==0) ? '' : $displayOrders) : (($mode=='edit') ? $select_commande : (($assetOf->fk_commande==0) ? '' : $displayOrders))
					//,'statut_commande'=> $commande->getLibStatut(0)
					,'commande_fournisseur'=>$HtmlCmdFourn
					,'date_besoin'=>$form->calendrier('','date_besoin',$assetOf->date_besoin,12,12)
					,'date_lancement'=>$form->calendrier('','date_lancement',$assetOf->date_lancement,12,12).( $assetOf->date_lancement > $assetOf->date_besoin ? img_picto($langs->trans('NeededDateCantBeSatisfied'),'warning') : '' )
					,'temps_estime_fabrication'=>price($assetOf->temps_estime_fabrication,0,'',1,-1,2)
					,'temps_reel_fabrication'=>price($assetOf->temps_reel_fabrication,0,'',1,-1,2)

					,'fk_soc'=> ($mode=='edit') ? $doliform->select_company($assetOf->fk_soc,'fk_soc','client=1',1) : (($client->id) ? $client->getNomUrl(1) : '')
					,'fk_project'=>custom_select_projects(-1, $assetOf->fk_project, 'fk_project',$mode)

					,'note'=>$form->zonetexte('', 'note', $assetOf->note, 80,5)

					,'quantity_to_create'=>$quantity_to_create
					,'product_to_create'=>$link_product_to_add

					,'status'=>$form->combo('','status',$TTransStatus,$assetOf->status)
					,'statustxt'=>$TTransStatus[$assetOf->status]
					,'idChild' => (!empty($Tid)) ? '"'.implode('","',$Tid).'"' : ''
					,'url' => dol_buildpath('/of/fiche_of.php', 1)
					,'url_liste' => ($assetOf->getId()) ? dol_buildpath('/of/fiche_of.php?id='.$assetOf->getId(), 1) : dol_buildpath('/of/liste_of.php', 1)
					,'fk_product_to_add'=>$fk_product_to_add
					,'fk_nomenclature'=>$fk_nomenclature
					,'fk_assetOf_parent'=>($assetOf->fk_assetOf_parent ? $assetOf->fk_assetOf_parent : '')
					,'link_assetOf_parent'=>($hasParent ? '<a href="'.dol_buildpath('/of/fiche_of.php?id='.$TAssetOFParent->rowid, 1).'">'.$TAssetOFParent->numero.'</a>' : '')

					,'total_cost'=>price($assetOf->total_cost,0,'',1,-1,2, $conf->currency)
					,'total_estimated_cost'=>price($assetOf->total_estimated_cost,0,'',1,-1,2, $conf->currency)
					,'mo_cost'=>price($assetOf->mo_cost,0,'',1,-1,2, $conf->currency)
					,'mo_estimated_cost'=>price($assetOf->mo_estimated_cost,0,'',1,-1,2, $conf->currency)
					,'compo_cost'=>price($assetOf->compo_cost,0,'',1,-1,2, $conf->currency)
					,'compo_estimated_cost'=>price($assetOf->compo_estimated_cost,0,'',1,-1,2, $conf->currency)
					,'compo_planned_cost'=>price($assetOf->compo_planned_cost,0,'',1,-1,2, $conf->currency)
					,'current_cost_for_to_make'=>price($assetOf->current_cost_for_to_make,0,'',1,-1,2, $conf->currency)
			        ,'date_end'=>$assetOf->get_date('date_end')
			    ,'date_start'=>$assetOf->get_date('date_start')
					,'rank'=>$form->texte('', 'rank', $assetOf->rank,3,3)
			)
			,'view'=>array(
				'mode'=>$mode
				,'status'=>$assetOf->status
				,'allow_delete_of_finish'=>$user->rights->of->of->allow_delete_of_finish
				,'ASSET_USE_MOD_NOMENCLATURE'=>(int) $conf->nomenclature->enabled
				,'OF_MINIMAL_VIEW_CHILD_OF'=>(int)$conf->global->OF_MINIMAL_VIEW_CHILD_OF
				,'select_product'=>$select_product
				,'select_workstation'=>$form->combo('', 'fk_asset_workstation', TWorkstation::getWorstations($PDOdb), -1)
				//,'select_workstation'=>$form->combo('', 'fk_asset_workstation', TAssetWorkstation::getWorstations($PDOdb), -1) <= assetworkstation
				,'actionChild'=>($mode == 'edit')?__get('actionChild','edit'):__get('actionChild','view')
				,'use_lot_in_of'=>(int)(!empty($conf->{ ATM_ASSET_NAME }->enabled) && !empty($conf->global->USE_LOT_IN_OF))
				,'use_project_task'=>(int) $conf->global->ASSET_USE_PROJECT_TASK
				,'defined_user_by_workstation'=>(int) $conf->global->ASSET_DEFINED_USER_BY_WORKSTATION
				,'defined_task_by_workstation'=>(int) $conf->global->ASSET_DEFINED_OPERATION_BY_WORKSTATION
				,'defined_workstation_by_needed'=>(int) $conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED
				,'defined_manual_wharehouse'=>(int) $conf->global->ASSET_MANUAL_WAREHOUSE
				,'hasChildren' => (int) !empty($Tid)
				,'user_id'=>$user->id
				,'workstation_module_activate'=>(int) $conf->workstation->enabled
				,'show_cost'=>(int)$user->rights->of->of->price
				,'langs'=>$langs
				,'editField'=>($form->type_aff == 'view' ? '<a class="notinparentview quickEditButton" href="#" onclick="quickEditField('.$assetOf->getId().',this)" style="float:right">'.img_edit().'</a>' : '')
				,'link_update_qty_used'=> ($assetOf->status=='OPEN' || $assetOf->status == 'CLOSE') ? img_picto($langs->transnoentities('OfTransfertQtyPlannedIntoUsed'), 'rightarrow.png', 'onclick="updateQtyUsed(this)" class="classfortooltip"') : ''
			)
			,'rights'=>array(
				'show_ws_time'=>$user->rights->of->of->show_ws_time
			)
			,'conf'=>$conf
		)
	);

	echo $form->end_form();

	llxFooter('$Date: 2011/07/31 22:21:57 $ - $Revision: 1.19 $');
}

function calc_mini_tu1($FieldName,&$CurrVal,&$CurrPrm,&$TBS)
{
	global $conf;
	
	$CurrVal = $CurrVal * $conf->global->OF_COEF_MINI_TU_1;
}

function measuring_units_weight_string($FieldName,&$CurrVal,&$CurrPrm,&$TBS)
{
	$CurrVal = measuring_units_string($CurrVal, 'weight');
}

function concatPDFOF(&$pdf,$files) {

	foreach($files as $file)
	{
		$pagecount = $pdf->setSourceFile($file);

		for ($i = 1; $i <= $pagecount; $i++) {
			$tplidx = $pdf->ImportPage($i);
			$s = $pdf->getTemplatesize($tplidx);
			$pdf->AddPage($s['h'] > $s['w'] ? 'P' : 'L');
			$pdf->useTemplate($tplidx);
		}

	}

	return $pagecount;
}
