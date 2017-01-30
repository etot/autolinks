<?php
/**
 ** @author Grégoire Etot
 ** @date 2015
*/

// livre_id et review_id sont les id minimum à passer aux fonctions get
$min_livre_id = $this->params()->fromRoute('livre_id', 0);
$min_review_id = $this->params()->fromRoute('review_id', 0);
$bNoUpdateBdd = $this->params()->fromRoute('test', 0);

// + ajouter un paramètre "test" permettant de ne pas jouer la requête en fin de fonction
$aExceptions = array(); // some book_ids to avoid ?
$iTotalCount = $iModifiedReviewsCount = 0;

// constitution du tableau $aPattern => $aReplacement
$aBooksResult = $this->getLivreTable()->getAllTitlesWithReviews($aExceptions, $min_livre_id);
$aPattern = $aSimplePattern = $aReplacement = array();
foreach($aBooksResult as $oBook)
{
	// $aPattern est le tableau contenant les titres (dans deux versions : entiers et arrêtés au premier : , .)
	$sSimplifiedTitle = trim(mb_substr($oBook->livre_sTitle, 0, strcspn($oBook->livre_sTitle, ':,.')));
	
	// on dédoublonne car le titre peut être égal à sa version "simplifiée"
	$aKeywords = array_unique(array(trim($oBook->livre_sTitle), $sSimplifiedTitle));
	
	foreach($aKeywords as $sKeyword)
	{
		if(
			(mb_strlen($sKeyword, "UTF-8") < 7) 
			||
			(strpos($sKeyword, ' ') == false) // ajout 020516 (à valider) : pas de mot simple, espace obligatoire
		) 
		{
			continue;
		}
		
		$aSimplePattern[$oBook->livre_id] = $sKeyword ;

		$aPattern[$oBook->livre_id] = "~(?:<(a|script|style|title)\s.*</(?1)>) # matche les liens, script, styles
			|(?:<!--.*-->)                          # matche les commentaires
			|(?:<[^>]+>)                            # matche les balises (et leur intérieur) restantes
			|(\b".str_replace(" ", "[[:space:]]", $sKeyword) . "\b)                         # matche le mot clef sauf s'il est contenu dans un autre mot
			|(?:.)                                  # matche le reste caractère par caractère
			~sUx";
		
		// $aReplacement est le tableau de tableaux contenant les URLs possibles pour un mot clé
		$sLivreUrl = $this->url()->fromRoute('detailslivre', 
			array(
				'livre_sId' => $oBook->livre_sId,
				'category_sId' => $oBook->category_sId,
				'parent_category_sId' => $oBook->parent_category_sId,
			)
		);
		
		if(!isset($aReplacement[$sKeyword]))
		{
			$aReplacement[$sKeyword] = array(); 
		}
		$aReplacement[$sKeyword][] = '<a href="' . $sLivreUrl . '">' . $sKeyword . '</a>';
	}
}
unset($aBooksResult);

// trier $aSimplePattern par longueur de clé décroissante
uasort($aSimplePattern, function($a,$b){return (mb_strlen($a, "UTF-8") < mb_strlen($b, "UTF-8"));});

$aReviewsModel = $this->getServiceLocator()->get('Livreshistoire\Model\ReviewTable');
$aReviews = $aReviewsModel->getAll($min_review_id);

foreach($aReviews as $oReview)
{
	$sReviewText = $oReview->review_sText;
	
	if(substr_count($sReviewText, '<a ') > 3)
	{
		continue;
		// plus de trois liens dans l'avis original => pas de modif de l'avis
	}
	
	// enlever de $aPattern le titre du livre concerné par l'avis en cours
	// => pas de lien vers la page courante
	$aSimplePatternModified = $aSimplePattern;
	
	// enlever tous les mots clés égaux à celui du livre en cours 
	// exemple : pas de lien vers un autre livre "napoléon" si le livre en cours est "napoléon"
	foreach($aSimplePattern as $livre_id => $sPattern)
	{
		if(isset($aSimplePattern[$oReview->livre_id]) && ($sPattern == $aSimplePattern[$oReview->livre_id]))
		{
			unset($aSimplePatternModified[$livre_id]);
		}
	}
	unset($aSimplePatternModified[$oReview->livre_id]);
	
	
	$aTempPatternArray = array();
	foreach($aSimplePatternModified as $iBookId => $sSimplePattern)
	{
		if(stripos($sReviewText, $sSimplePattern))
		{
			$aTempPatternArray[] = $aPattern[$iBookId];
		}
	}
	
	if(!empty($aTempPatternArray)) // seulement si on a trouvé la chaîne : expression régulière complexe (optimisation)
	{
		$iReplacements = 0;
		$aReplacements = array();
		preg_match_all('~(?:<(a|script|style|title)\s.*>)(.*)(</(?1)>)~sUx', $sReviewText, $aMatches);

		$aReplacements = array_flip($aMatches[2]); // on obtient : array("mot clé 1" => 1, "mot clé 2" => 2)
		// remplir $aReplacements avec les liens existants dans l'avis original
		// pour ne pas faire de deuxième lien sur le même mot clé
		
		$sNewReview = preg_replace_callback($aTempPatternArray, function ($matches) use ($aReplacement, &$iReplacements, &$aReplacements) {
		if(isset($matches[2]))
		{
			// isset($aReplacements[$matches[2]] => déjà un lien sur ce mot-clé
			if(($iReplacements >= 3) || isset($aReplacements[$matches[2]]))
			{
				return $matches[2]; // pas plus de 2 liens par avis
			}
			
			$aReplacements[$matches[2]] = 1;
			$iReplacements++;
			return $aReplacement[$matches[2]][array_rand($aReplacement[$matches[2]])];
		}
		return $matches[0];
		}, $sReviewText);
		// retourne une URL au hasard parmi celles correspondant à ce mot clé
		// $iReplacements contient le nombre de remplacement effectués
						
		if($iReplacements)
		{
			$iModifiedReviewsCount++;
			if(!$bNoUpdateBdd)
			{
				$aReviewsModel->modifyReview($oReview->review_id, $sNewReview);
			}
			var_dump('review_id : ' . $oReview->review_id);
			var_dump('nombre de remplacements effectués : ' . $iReplacements);
			$iTotalCount += $iReplacements;
			echo $sNewReview . "<br /><br /><br /><br />";
			if($iModifiedReviewsCount == 10)
			{
				die();
			}				
		}			
	}
			
}

var_dump("Nb total d'avis modifiés : " . $iTotalCount);die();
