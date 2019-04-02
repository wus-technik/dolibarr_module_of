<?php

	function ofPrepareHead(&$asset,$type='assetOF') {
		global $user, $conf, $langs;

		$head=array();

		switch ($type) {

			case 'assetOF':
				$head= array(array(dol_buildpath('/of/fiche_of.php?id='.$asset->getId(),1), 'Fiche','fiche'));

				break;

		}

		$h = count($head);
		complete_head_from_modules($conf, $langs, $asset, $head, $h, 'of');

		return $head;

	}
	function ofAdminPrepareHead()
	{
	    global $langs, $conf;
	    $langs->load("of@of");
	    $h = 0;
	    $head = array();
	    $head[$h][0] = dol_buildpath("/of/admin/of_setup.php", 1);
	    $head[$h][1] = $langs->trans("Parameters");
	    $head[$h][2] = 'settings';
	    $h++;
	    $head[$h][0] = dol_buildpath("/of/admin/of_about.php", 1);
	    $head[$h][1] = $langs->trans("About");
	    $head[$h][2] = 'about';
	    $h++;

	    return $head;
	}

	function visu_checkbox_user(&$PDOdb, &$form, $group, $TUsers, $name, $status)
	{
		$include = array();

		$sql = 'SELECT u.lastname, u.firstname, uu.fk_user, u.statut
		  FROM '.MAIN_DB_PREFIX.'usergroup_user uu INNER JOIN '.MAIN_DB_PREFIX.'user u ON (uu.fk_user = u.rowid)
		  WHERE uu.fk_usergroup = '.(int) $group;
		$PDOdb->Execute($sql);

		//Cette input doit être présent que si je suis en brouillon, si l'OF est lancé la présence de cette input va réinitialiser à vide les associations précédentes
		if ($status == 'DRAFT' && $form->type_aff == 'edit') {
		    $res = '<input checked="checked" style="display:none;" type="checkbox" name="'.$name.'" value="0" />';
        }

		while ($obj = $PDOdb->Get_line())
		{
				$label = $obj->lastname.' '.$obj->firstname;
				if($obj->statut == 0) {
					$label='<span style="text-decoration : line-through;">'.$label.'</span>';

					if(!in_array($obj->fk_user, $TUsers)) continue;

				}

				if ($status == 'DRAFT' || (in_array($obj->fk_user, $TUsers))) {
			    $res .= '<p style="margin:4px 0">'
			    		.$form->checkbox1($label, $name, $obj->fk_user, (in_array($obj->fk_user, $TUsers) ? true : false), ($status == 'DRAFT' ? 'style="vertical-align:text-bottom;"' : 'disabled="disabled" style="vertical-align:text-bottom;"'), '', '', 'case_after', array('no'=>'', 'yes'=>img_picto('', 'tick.png'))).'</p>';
            }
		}

		return $res;
	}

	/*Mode opératoire*/
	function visu_checkbox_task(&$PDOdb, &$form, $fk_workstation, $TTasks, $name, $status)
	{
		$include = array();

		$sql = 'SELECT rowid, libelle FROM '.MAIN_DB_PREFIX.'asset_workstation_task WHERE fk_workstation = '.(int) $fk_workstation;
		$PDOdb->Execute($sql);

		//Cette input doit être présent que si je suis en brouillon, si l'OF est lancé la présence de cette input va réinitialiser à vide les associations précédentes
		if ($status == 'DRAFT' && $form->type_aff != 'edit') $res = '<input checked="checked" style="display:none;" type="checkbox" name="'.$name.'" value="0" />';
		while ($obj = $PDOdb->Get_line())
		{
			if ($status == 'DRAFT' && $form->type_aff == 'edit') {
				$res .= $form->checkbox1('', $name, $obj->rowid, (in_array($obj->rowid, $TTasks)), ($status == 'DRAFT' ? 'style="vertical-align:text-bottom;"' : 'disabled="disabled" style="vertical-align:text-bottom;"'));
			}

			if($status == 'DRAFT' || in_array($obj->rowid, $TTasks)) {
				$res.=$obj->libelle;
			}

			if(in_array($obj->rowid, $TTasks)) {
				$res.=img_picto('', 'tick.png');
			}

			$res.='<br />';

		}

		return $res;

	}

	function visu_project_task(&$db, $fk_project_task, $mode, $name)
	{
		global $langs;
		if (!$fk_project_task) return ' - ';

		require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';

		$projectTask = new Task($db);
		$projectTask->fetch($fk_project_task);

		$link = $projectTask->getNomUrl(1,'withproject');
		//		$link = '<a href="'.DOL_URL_ROOT.'/projet/tasks/task.php?id='.$fk_project_task.'">'.img_picto('', 'object_projecttask.png').$projectTask->ref.'</a>';

		if ($projectTask->progress == 0) $imgStatus = img_picto($langs->trans('OFWaiting'), 'statut0.png');
		elseif ($projectTask->progress < 100) $imgStatus = img_picto($langs->trans('OFInProgress'), 'statut3.png');
		else $imgStatus = img_picto($langs->trans('OFFinish'), 'statut4.png');

		if ($mode == 'edit')
		{
			$formother = new FormOther($db);
			return $link.' - '.dol_print_date($projectTask->date_start).' - '.$formother->select_percent($projectTask->progress, $name).' '.$imgStatus;
		}
		else {
			return $link.' - '.dol_print_date($projectTask->date_start).' - '.$projectTask->progress.' % '.$imgStatus;
		}

	}

	/**
	 *  Override
	 * 	Return a combo box with list of units
	 *  For the moment, units labels are defined in measuring_units_string
	 *
	 *  @param	string		$name                Name of HTML field
	 *  @param  string		$measuring_style     Unit to show: weight, size, surface, volume
	 *  @param  string		$default             Force unit
	 * 	@param	int			$adddefault			Add empty unit called "Default"
	 * 	@return	void
	 */
	function custom_load_measuring_units($name='measuring_units', $measuring_style='', $default='0', $adddefault=0)
	{
		global $langs,$conf,$mysoc;
		$langs->load("other");

		$return='';

		$measuring_units=array();
		if ($measuring_style == 'weight') $measuring_units=array(-6=>1,-3=>1,0=>1,3=>1,99=>1);
		else if ($measuring_style == 'size') $measuring_units=array(-3=>1,-2=>1,-1=>1,0=>1,98=>1,99=>1);
        else if ($measuring_style == 'surface') $measuring_units=array(-6=>1,-4=>1,-2=>1,0=>1,98=>1,99=>1);
		else if ($measuring_style == 'volume') $measuring_units=array(-9=>1,-6=>1,-3=>1,0=>1,88=>1,89=>1,97=>1,99=>1,/* 98=>1 */);  // Liter is not used as already available with dm3
		else if ($measuring_style == 'unit') $measuring_units=array(0=>0);

		$return.= '<select class="flat" name="'.$name.'">';
		if ($adddefault) $return.= '<option value="0">'.$langs->trans("Default").'</option>';

		foreach ($measuring_units as $key => $value)
		{
			$return.= '<option value="'.$key.'"';
			if ($key == $default)
			{
				$return.= ' selected="selected"';
			}
			//$return.= '>'.$value.'</option>';
			if ($measuring_style == 'unit') $return.= '>'.$langs->trans('unit_s_').'</option>';
			else $return.= '>'.measuring_units_string($key,$measuring_style).'</option>';
		}
		$return.= '</select>';

		return $return;
	}

	/**
	 *	Override de la fonction classique de la class FormProject
	 *  Show a combo list with projects qualified for a third party
	 *
	 *	@param	int		$socid      	Id third party (-1=all, 0=only projects not linked to a third party, id=projects not linked or linked to third party id)
	 *	@param  int		$selected   	Id project preselected
	 *	@param  string	$htmlname   	Nom de la zone html
	 *	@param	int		$maxlength		Maximum length of label
	 *	@param	int		$option_only	Option only
	 *	@param	int		$show_empty		Add an empty line
	 *	@return string         		    select or options if OK, void if KO
	 */
	function custom_select_projects($socid=-1, $selected='', $htmlname='projectid', $type_aff = 'view', $maxlength=25, $option_only=0, $show_empty=1)
	{
		global $user,$conf,$langs,$db;

		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';

		$out='';

		if ($type_aff == 'view')
		{
			if ($selected > 0)
			{
				$project = new Project($db);
				$project->fetch($selected);

				//return dol_trunc($project->ref,18).' - '.dol_trunc($project->title,$maxlength);
				return $project->getNomUrl(1).' - '.dol_trunc($project->title,$maxlength);
			}
			else
			{
				return $out;
			}
		}

		if(DOL_VERSION>=6) {
			dol_include_once('/core/class/html.formprojet.class.php');
			$formProject=new FormProjets($db);
			return $formProject->select_projects($socid,$selected, $htmlname,32,0,1,0,0,0,0,'',1);
		}

		$hideunselectables = false;
		if (! empty($conf->global->PROJECT_HIDE_UNSELECTABLES)) $hideunselectables = true;

		$projectsListId = false;
		if (empty($user->rights->projet->all->lire))
		{
			$projectstatic=new Project($db);
			$projectsListId = $projectstatic->getProjectsAuthorizedForUser($user,0,1);
		}

		// Search all projects
		$sql = 'SELECT p.rowid, p.ref, p.title, p.fk_soc, p.fk_statut, p.public';
		$sql.= ' FROM '.MAIN_DB_PREFIX .'projet as p';
		$sql.= " WHERE p.entity IN (".getEntity('project',1).")";
		if ($projectsListId !== false) $sql.= " AND p.rowid IN (".$projectsListId.")";
		if ($socid == 0) $sql.= " AND (p.fk_soc=0 OR p.fk_soc IS NULL)";
		if ($socid > 0)  $sql.= " AND (p.fk_soc=".$socid." OR p.fk_soc IS NULL)";
		$sql.= " ORDER BY p.ref ASC";


		$resql=$db->query($sql);
		if ($resql)
		{
			if (empty($option_only)) {
				$out.= '<select class="flat" name="'.$htmlname.'">';
			}
			if (!empty($show_empty)) {
				$out.= '<option value="0">&nbsp;</option>';
			}
			$num = $db->num_rows($resql);
			$i = 0;
			if ($num)
			{
				while ($i < $num)
				{
					$obj = $db->fetch_object($resql);
					// If we ask to filter on a company and user has no permission to see all companies and project is linked to another company, we hide project.
					if ($socid > 0 && (empty($obj->fk_soc) || $obj->fk_soc == $socid) && ! $user->rights->societe->lire)
					{
						// Do nothing
					}
					else
					{
						$labeltoshow=dol_trunc($obj->ref,18);
						//if ($obj->public) $labeltoshow.=' ('.$langs->trans("SharedProject").')';
						//else $labeltoshow.=' ('.$langs->trans("Private").')';
						if (!empty($selected) && $selected == $obj->rowid /*&& $obj->fk_statut > 0*/)
						{
							$out.= '<option value="'.$obj->rowid.'" selected="selected">'.$labeltoshow.' - '.dol_trunc($obj->title,$maxlength).'</option>';
						}
						else
						{
							$disabled=0;
							$labeltoshow.=' '.dol_trunc($obj->title,$maxlength);
							if (! $obj->fk_statut > 0)
							{
								$disabled=1;
								$labeltoshow.=' - '.$langs->trans("Draft");
							}
							if ($socid > 0 && (! empty($obj->fk_soc) && $obj->fk_soc != $socid))
							{
								$disabled=1;
								$labeltoshow.=' - '.$langs->trans("LinkedToAnotherCompany");
							}

							if ($hideunselectables && $disabled)
							{
								$resultat='';
							}
							else
							{
								$resultat='<option value="'.$obj->rowid.'"';
								if ($disabled) $resultat.=' disabled="disabled"';
								//if ($obj->public) $labeltoshow.=' ('.$langs->trans("Public").')';
								//else $labeltoshow.=' ('.$langs->trans("Private").')';
								$resultat.='>';
								$resultat.=$labeltoshow;
								$resultat.='</option>';
							}
							$out.= $resultat;
						}
					}
					$i++;
				}
			}
			if (empty($option_only)) {
				$out.= '</select>';
			}

			if($conf->cliacropose->enabled) { // TODO c'est naze, à refaire en utilisant la vraie autocompletion dispo depuis dolibarr 3.8 pour utiliser l'auto complete projets de doli si active (j'avais rajouté un script ajax/projects.php pour acropose)

				// Autocomplétion
				if(isset($selected)) {

					$p = new Project($db);
					$p->fetch($selected);
					$selected_value = $p->ref;

				}
				if(empty($htmlname))$htmlname='fk_project';
				$out = ajax_autocompleter($selected, $htmlname, DOL_URL_ROOT.'/projet/ajax/projects.php', $urloption, 1);
				$out .= '<input type="text" size="20" name="search_'.$htmlname.'" id="search_'.$htmlname.'" value="'.$selected_value.'"'.$placeholder.' />';

			}

			$db->free($resql);

			return $out;
		}
		else
		{
			dol_print_error($db);
			return '';
		}
	}


function _getArrayNomenclature(&$PDOdb, $TAssetOFLine=false, $fk_product=false)
{
	global $conf;

	dol_include_once("/of/class/ordre_fabrication_asset.class.php");

	$TRes = array();

	if (!$conf->nomenclature->enabled) return $TRes;

	include_once DOL_DOCUMENT_ROOT.'/custom/nomenclature/class/nomenclature.class.php';

	$fk_product = $TAssetOFLine->fk_product ? $TAssetOFLine->fk_product : $fk_product;

	$TNomen = TNomenclature::get($PDOdb, $fk_product);
	foreach ($TNomen as $TNomenclature)
	{
		$TRes[$TNomenclature->getId()] = !empty($TNomenclature->title) ? $TNomenclature->title : '(sans titre)';
	}

	return $TRes;
}

function _calcQtyOfProductInOf(&$db, &$conf, &$product)
{
	dol_include_once("/of/class/ordre_fabrication_asset.class.php");

	return TAssetOf::qtyFromOF($product->id);

}

function _getProductIdFromNomen(&$TProductId, $details_nomenclature)
{
    foreach($details_nomenclature as $detail){
        if(!empty($detail['childs'])) _getProductIdFromNomen($TProductId, $detail['childs']);
        $TProductId[$detail['fk_product']]=$detail['fk_product'];
    }
}

function _getDetailStock(&$line, &$TProductStock, &$TDetails)
{
    if(empty($line->array_options['options_svpm_date_livraison']))return -3;
    $qtyToDestock = $line->qty;

/*
 * 1st step on verif si stock physique is enough
 */
    if(!empty($TProductStock[$line->fk_product]['stock'])){
        $TDetails[$line->id]['stock_reel'] = $TProductStock[$line->fk_product]['stock'];

        if($qtyToDestock < $TProductStock[$line->fk_product]['stock']){
            $TProductStock[$line->fk_product]['stock']-= $qtyToDestock;
            $qtyToDestock=0;
        }
        else{
            $qtyToDestock -= $TProductStock[$line->fk_product]['stock'];
            $TProductStock[$line->fk_product]['stock']= 0;
        }

    }


/*
 * 2nd step on verif si on peut compenser le manque avec les prochaines cmd fourn
 */
    if($qtyToDestock > 0 && !empty($TProductStock[$line->fk_product]['supplier_order'])){
        foreach($TProductStock[$line->fk_product]['supplier_order'] as $date => $stock_by_order) {
            if($qtyToDestock <= 0) break; // La quantité totale est trouvée

            if(!empty($date) && !empty($line->array_options['options_svpm_date_livraison'])){
                $tms_fourn = strtotime($date);
                if($tms_fourn < $line->array_options['options_svpm_date_livraison']) {
                    foreach($stock_by_order as $fk_order => $stock) {
                        $TDetails[$line->id]['supplier_order'][$fk_order] += $TProductStock[$line->fk_product]['supplier_order'][$date][$fk_order];

                        if($qtyToDestock < $TProductStock[$line->fk_product]['supplier_order'][$date][$fk_order]){
                            $TProductStock[$line->fk_product]['supplier_order'][$date][$fk_order] -= $qtyToDestock;
                            $qtyToDestock=0;
                        }
                        else{
                            $qtyToDestock -= $TProductStock[$line->fk_product]['supplier_order'][$date][$fk_order];
                            $TProductStock[$line->fk_product]['supplier_order'][$date][$fk_order] = 0;
                        }
                    }
                }
            }
        }
    }
/*
 * 3rd step : Si on a toujours pas de quoi fournir le client, on vérifie si on a de quoi créer les produits et que le délai est ok
 */
    if($qtyToDestock > 0 && !empty($line->details_nomenclature)){
        $isNomenOK = 0;
        foreach($line->details_nomenclature as $detail){

            $isNomenOK = _getDetailFromNomenclature($detail, $TProductStock, $TDetails[$line->id], $line->array_options['options_svpm_date_livraison'], $qtyToDestock);
            if($isNomenOK < 0) break;
        }
    }
    if($qtyToDestock<=0 || $isNomenOK > 0)$TDetails[$line->id]['status'] = 1;
}


function _getDetailFromNomenclature($details_nomenclature, &$TProductStock, &$TDetails, $date_de_livraison, $qtyToDestock){

    $qtyToDestock = $details_nomenclature['qty'] * $qtyToDestock;
    /*
     * 1st step on verif si stock physique is enough
     */
    if(!empty($TProductStock[$details_nomenclature['fk_product']]['stock'])){
        $TDetails['childs'][$details_nomenclature['fk_product']]['stock_reel'] = $qtyToDestock .'/'.$TProductStock[$details_nomenclature['fk_product']]['stock'];

        if($qtyToDestock < $TProductStock[$details_nomenclature['fk_product']]['stock']){
            $TProductStock[$details_nomenclature['fk_product']]['stock']-= $qtyToDestock;
            $qtyToDestock=0;
        }
        else{
            $qtyToDestock -= $TProductStock[$details_nomenclature['fk_product']]['stock'];
            $TProductStock[$details_nomenclature['fk_product']]['stock']= 0;
        }

    }


    /*
     * 2nd step on verif si on peut compenser le manque avec les prochaines cmd fourn
     */
    if($qtyToDestock > 0 && !empty($TProductStock[$details_nomenclature['fk_product']]['supplier_order'])){
        foreach($TProductStock[$details_nomenclature['fk_product']]['supplier_order'] as $date => $stock_by_order) {
            if($qtyToDestock <= 0) break; // La quantité totale est trouvée

            if(!empty($date) && !empty($date_de_livraison)){
                $qtyToDisplay = $qtyToDestock;
                $tms_fourn = strtotime($date);
                if($tms_fourn < $date_de_livraison) {
                    foreach($stock_by_order as $fk_order => $stock) {
                        $TDetails['childs'][$details_nomenclature['fk_product']]['supplier_order'][$fk_order] += $TProductStock[$details_nomenclature['fk_product']]['supplier_order'][$date][$fk_order];

                        if($qtyToDestock < $TProductStock[$details_nomenclature['fk_product']]['supplier_order'][$date][$fk_order]){
                            $TProductStock[$details_nomenclature['fk_product']]['supplier_order'][$date][$fk_order] -= $qtyToDestock;
                            $qtyToDestock=0;
                        }
                        else{
                            $qtyToDestock -= $TProductStock[$details_nomenclature['fk_product']]['supplier_order'][$date][$fk_order];
                            $TProductStock[$details_nomenclature['fk_product']]['supplier_order'][$date][$fk_order] = 0;
                        }
                    }
                    $TDetails['childs'][$details_nomenclature['fk_product']]['supplier_order'][$fk_order] = $qtyToDisplay .'/'.$TDetails['childs'][$details_nomenclature['fk_product']]['supplier_order'][$fk_order];
                }
            }
        }
    }
    /*
     * 3rd step : Si on a toujours pas de quoi fournir le client, on vérifie si on a de quoi créer les produits et que le délai est ok
     */
    if($qtyToDestock > 0 && !empty($details_nomenclature['childs'])){
        $isNomenOK = 0;
        foreach($details_nomenclature['childs'] as $detail){
            $isNomenOK = _getDetailFromNomenclature($detail, $TProductStock, $TDetails['childs'][$details_nomenclature['fk_product']], $date_de_livraison, $qtyToDestock);
            if($isNomenOK < 0) break;
        }
    }
    if($qtyToDestock<=0 || $isNomenOK > 0){
        $TDetails['childs'][$details_nomenclature['fk_product']]['status'] = 1;
        return 1;
    }
    return -1;

}

function _getPictoDetail($TDetailStock, $lineid, &$stock_tooltip, $level = 1) {
    global $langs, $db;
    $nbsp = '';
    for($i=1; $i<$level; $i++) $nbsp .= '&nbsp;&nbsp;&nbsp;&nbsp;';

    if(!empty($TDetailStock[$lineid])){
    foreach($TDetailStock[$lineid] as $type => $detail) {

        if($type == 'stock_reel') $stock_tooltip .= $nbsp . $langs->trans('PhysicalStock') . ' : '  . $detail . '</br>';

        if($type == 'supplier_order') {

            $stock_tooltip .= $nbsp . $langs->trans('SupplierOrder') . ' : </br>';
            foreach($detail as $fk_supplier_order => $stock) {
                $fourncmd = new CommandeFournisseur($db);
                $fourncmd->fetch($fk_supplier_order);
                $stock_tooltip .= $nbsp . $fourncmd->getNomUrl(1) . ' ==> ' . $stock . '</br>';
            }

        }

        if($type == 'childs') {
            $stock_tooltip .= $nbsp . $langs->trans('Nomenclature') . ' : </br>';
            foreach($detail as $fk_product => $TDetails) {
                $prod = new Product($db);
                $prod->fetch($fk_product);
                $stock_tooltip .= $nbsp . $nbsp . $prod->getNomUrl(1) . ' : ';
                _getPictoDetail($detail, $fk_product, $stock_tooltip, $level + 1);
            }
        }
    }
    }
}