<?php
/*
 * Script de convertion de Drupal vers SPIP.
 *
 * Copyright (C) 2013-2014 Olivier Tétard <olivier.tetard@miskin.fr
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

if (!isset($argc) || is_null($argc)) {
	die("Ce script doit être lancé en ligne de commande");
}

if (!defined('_DIR_RESTREINT_ABS')) define('_DIR_RESTREINT_ABS', 'ecrire/');
include_once _DIR_RESTREINT_ABS.'inc_version.php';

/* Import SPIP */
include_spip('action/editer_article');
include_spip('action/editer_auteur');
include_spip('action/editer_url');
include_spip('action/editer_documents');
include_spip('action/editer_mot');
include_spip('action/editer_gis');
include_spip('action/editer_evenement');
include_spip('inc/modifier_article');
include_spip('inc/modifier');
include_spip('inc/config');
include_spip('inc/session');
include_spip('base/abstract_sql');
include_spip('action/iconifier');
include_spip('inc/distant');

require_once(find_in_path('lib_tierces/html2spip-0.6/misc_tools.php'));
require_once(find_in_path('lib_tierces/html2spip-0.6/HTMLEngine.class'));
require_once(find_in_path('lib_tierces/html2spip-0.6/HTML2SPIPEngine.class'));

/* Contournement du mécanisme d'authentification / autorisation. */
$GLOBALS['visiteur_session']['id_auteur'] = 1;
$GLOBALS['visiteur_session']['statut'] = '0minirezo';
$GLOBALS['visiteur_session']['webmestre'] = 'oui';

/* Utilisé pour la convertion du contenu */
$_SERVER['SERVER_NAME'] = 'www.france.attac.org';
define(_HTML2SPIP_PRESERVE_DISTANT, true);

class DruSPIPException extends Exception { }


/* ------------ Début de la boucle principale ------------
 *
 * La logique de est relativement simple, on effectue les
 * pré-vérifications (rubriques cibles et mots-clés cible existants),
 * ensuite, on converti les articles au format SPIP. Enfin, on les
 * intègre dans SPIP.
 */
$options = getopt('vf:hl');

if(isset($options['h'])) {
	affichage_aide();
	exit(0);
}

if(!isset($options['f']) && !isset($options['l'])) {
	affichage_aide();
	exit(1);
}

binlog_creation();

if($options['f']) {
	try {
		pre_verifications();
		$articles = convertir($options['f']);
		spip_importer_articles($articles);
	}
	catch(Exception $e) {
		print "[ERREUR] " . $e->getMessage() . "\n";
	}
}

if(isset($options['l'])) {
	binlog_afficher();
}
/* ------------ Fin de la boucle principale ------------ */



function pre_verifications() {
	global $tables_conversion;
	$erreurs = 0;

	journaliser("Pré-vérifications");

	foreach($tables_conversion['campagnes'] as $campagne) {
		if(!sql_countsel("spip_mots", "id_mot=".sql_quote($campagne))) {
			journaliser("Mot clé manquant : %s", $campagne, 1);
			$erreur++;
		}
	}

	foreach($tables_conversion['attacpedia'] as $attacpedia) {
		if(!sql_countsel("spip_mots", "id_mot=".sql_quote($attacpedia))) {
			journaliser("Mot clé manquant : %s", $attacpedia, 1);
			$erreur++;
		}
	}

	foreach($tables_conversion['types_articles'] as $type_article) {
		if($type_article !== false && !sql_countsel("spip_rubriques", "id_rubrique=".sql_quote($type_article))) {
			journaliser("Rubrique manquante : %s", $type_article, 1);
			$erreur++;
		}
	}

	foreach($tables_conversion['type_evenement'] as $type_evenement) {
		if($type_evenement !== false && !sql_countsel("spip_mots", "id_mot=".sql_quote($type_evenement))) {
			journaliser("Mot clé manquant : %s", $type_evenement, 1);
			$erreur++;
		}
	}


	if($erreur > 0) {
		journaliser("Erreurs dans les pré-verifs !");
		die();
	}
}

/* 
 * Cette fonction se charge de convertir un dump Drupal (au format
 * node_export sérialisé en PHP). Elle retourne un tableau l’ensemble
 * des données à intégrer dans le site SPIP (qui doit être intégré par
 * spip_importer_articles()).
 */
function convertir($drufile) {

	if(!file_exists($drufile)) {
		throw new DruSPIPException(sprintf("Le fichier %s est introuvable", $drufile));
	}
	
	$donnees_str = file_get_contents($drufile);
	if(!$donnees_str)
		throw new DruSPIPException(sprintf("Erreur lors de le lecture du fichier %s.", $drufile));
	
	/* Les exports Drupal commencent par 'node_export_serialize::'. */
	if(substr(ltrim($donnees_str), 0, 23) == "node_export_serialize::") {
		$donnees = unserialize(htmlspecialchars_decode(str_replace("node_export_serialize::", "", $donnees_str)));
		if(!$donnees)
			throw new DruSPIPException(sprintf("Le format du fichier %s n'est pas correct", $drufile));
	}
	
	/* Liste des type de nœuds Drupal à ignorer (spécifique site source). */
	/* FIXME: à déplacer hors de cette fonction */
	$ignore_types = array('join_transac', 'simplenews', 'feed', 'node_export', 'petition', 'membre_cs', 'webform', 
			      'edition', 'join_subscription', 'join_account', 'join_donation', 'filiere', 'book', 
			      'atelier', 'cl', 'page', 'site', 'cr_atelier_cncl', 'compte_rendu', 'campagne', 
			      'dossier', 'slideshow', 'emission_radio');

	/*
	 * Le tableau de converstion contient la liste des données à
	 * récupérer dans le tableau des données exportées par Drupal. On
	 * retrouve un tableau par type d'objet.
	 *
	 * Pour chaque type d'objet, on retrouve un type une série de
	 * champs, avec les instructions pour récupérer les données depuis
	 * le tableau Drupal. Les données ont la forme suivante :
 	 *  - arg0 : nom du champ Drupal (peut être sous la forme «
	 *           field_lieu/0/value » par exemple
	 *  - arg1 : nom de la fonction de convertion à appeler (facultatif)
	 *  - arg2 : inutilisé (FIXME)
	 *  - arg3 : type de node Drupal pour lequel ce champ doit être
         *           considéré.
	 */
	/* FIXME: ce tableau doit être défini hors de cette fonction */
	$tableau_conversion = array('article' => array('titre' => array('title', null, null, null),
						       'statut' => array('status', 'd2s_statut', null, null),
						       'date' => array('created', 'd2s_timestamp', null, null),
						       'texte' => array('body', 'd2s_sale', null, null),
						       'descriptif' => array('teaser', 'd2s_sale', null, null),
						       ),
				    'auteur' => array('nom' => array('tags/4', 'd2s_auteurs', null, null),
						      ),
				    'url' => array('url' => array('path',  null, null, null)),
				    'logo' => array('chemin' => array('field_logo/0/filepath', null, null, null),
						    'fichier' => array('field_logo/0/filename', null, null, null)),
				    'documents' => array('video' => array('field_video/0/embed', 'd2s_video', null, 'video'),
							 'audio' => array('field_audio_file/0/filepath', 'd2s_audio', null, 'audio'),
							 'audio_desc' => array('field_realisation/0/value', null, null, 'audio'),
							 'audio_name' => array('field_audio_file/0/filename', null, null, 'audio'),
							 'pj' => array('files', 'd2s_pj', null, null),
							 'pdf' => array('field_pdf_file', 'd2s_pdf', null, null),
							 'photos' => array('field_photo', 'd2s_photos', null, "photos"),
							 ),
				    'metadata' => array('nid' => array('nid', null, null, null),
							'type' => array('type', null, null, null),
							'attacpedia' => array('field_thesaurus', 'd2s_attacpedia', null, null),
							'type_article' => array('taxonomy/2', 'd2s_typearticle', null, null),
							'campagne' => array('field_campagne', 'd2s_campagne', null, null),
							"type_evenement" => array("taxonomy/10", "d2s_type_evenement", null, "evenement")
							),
				    'evenement' => array('lieu' => array('field_lieu/0/value', null, null, null),
							 'dates' => array('field_date', "d2s_date", null, "evenement"),
							 ),
				    'gis' => array('lat' => array('field_emplacement/0/openlayers_wkt', 'd2s_gis_lat', null, "evenement"),
						   'lon' => array('field_emplacement/0/openlayers_wkt', 'd2s_gis_lon', null, "evenement"),
						   'zoom' => array('title', "d2s_gis_zoom", null, "evenement"),
						   'titre' => array('title', null, null, "evenement"),
						   )
				    );

	$spip_data = array();		/* Données converties */
	journaliser("Conversion des données Drupal");
	foreach($donnees as $article_drupal) {
		$entry = array();

		if(in_array($article_drupal->type, $ignore_types)) {
			journaliser("Entrée $article_drupal->nid non traitée (type : $article_drupal->type ignoré)", 1);
			continue;
		}

		if(binlog_contient($article_drupal->nid)) {
			journaliser("L'élément %s a déjà été converti", $article_drupal->nid, 1);
			continue;
		}

		journaliser("Traitement entrée $article_drupal->nid | type : $article_drupal->type", 1);

		foreach($tableau_conversion as $type => $rules) {
			$entry[$type] = array();

			foreach($rules as $entree_spip => $drupal_data) {
				if($drupal_data[3] == null || $drupal_data[3] == $article_drupal->type) {
					$array_desc = explode('/', $drupal_data[0]);
					$drupal_attr = array_shift($array_desc);

					$convertisseur = $drupal_data[1];
					$callback_args = $drupal_data[2] != null ? $drupal_data[2] : array();

					$data = $article_drupal->$drupal_attr;

					/* Si on demande accès à une entrée du type «
					 * field_video/0/embed » */
					foreach($array_desc as $desc) {
						$data = $data[$desc];
					}

					if($convertisseur != null && $data)
						$data = call_user_func($convertisseur, $data);

					$entry[$type][$entree_spip] = $data;
				}
			}

		}

		$spip_data[] = $entry;
	}

	return $spip_data;
}

/*
 * Cette fonction se charge d’importer les articles dans le site SPIP
 * cible. Le tableau en entrée est généré par la fonction de
 * convertion.
 *
 * Cette fonction comprend beaucoup de choses spécifiques en fonction
 * du site à convertir. On retrouve par exemple des traitements
 * spécifiques pour les node de type vidéo par exemple.
 */
function spip_importer_articles($spip_data) {
	global $tables_conversion;

	foreach($spip_data as $entry) {
		$id_rubrique = null;
		$type = $entry['metadata']['type'];

		journaliser(sprintf("Traitement d'un nouvel item : %s - %s", $entry['metadata']['nid'], $type), 2);

		if(binlog_contient($entry['metadata']['nid'])) {
			journaliser("L'élément %s a déjà été converti", $entry['metadata']['nid']);
			continue;
		}


		journaliser("Recherche de la catégorie cible pour l'article");

		/* FIXME: doit être déplacé hors de cette fonction (spécifique site cible). */
		/* CATEGORIE CIBLE */
		/* Remarque : les articles de type 'story' sont dispatchés par la
		 * fonction dispatch_story_type() */
		$cat_cible = array('video' => 14,
				   'photos' => 23,
				   'audio' => 24,
				   'commission' => 36,
				   'livre' => 15,
				   'evenement' => 11,
				   );

		$fct = "dispatch_${type}_type";

		if(function_exists($fct))
			$id_rubrique = call_user_func($fct, $entry);
		elseif($cat_cible[$type])
			$id_rubrique = $cat_cible[$type];

		if($id_rubrique == null) {
			journaliser("Catégorie cible introuvable pour le type de contenu ".$type, 4);
			die();
		}

		journaliser("Envoi de l'article dans la rubrique %s", $id_rubrique, 1);

		/* --- VIDEO --- */
		if($type == "video") {
			if($entry['documents']['video']) {
				/* Si une vidéo est présente dans ce champ, il faut modifier le
				 * texte de l'article et ajouter le lien vers la video à la
				 * fin. */
				$entry['article']['texte'] .= sprintf("\n\n%s", $entry['documents']['video']);
			}
		}


		/* --- AUDIO --- */
		if($type == "audio") {
			journaliser("Gestion des documents audio");
			$audio_path = $entry['documents']['audio'];
			$audio_name = $entry['documents']['audio_name'];

			$id_document = importer_document($audio_path, $audio_name);
			journaliser("Import du document : ".$id_document, 1);

			$entry['article']['texte'] = sprintf("<emb%s>\nRéalisation : %s\n\n%s", $id_document, $entry['documents']['audio_desc'], $entry['article']['texte']);

			document_modifier($id_document, array('credits' => $entry['documents']['audio_desc']));
		}

		/* --- PIECES JOINTES --- */
		if($entry['documents']['pdf']) {
			journaliser("Ajout des pièces jointes au format PDF");

			foreach($entry['documents']['pdf'] as $document) {
				$id_document = importer_document($document['filepath'], $document['filename']);
				journaliser(sprintf("Document PDF %s importé", $id_document), 1);

				$entry['article']['texte'] = sprintf("%s\n\n<lecteurpdf%s>", $entry['article']['texte'], $id_document);
			}
		}


		/* --- ARTICLE --- */
		journaliser("Création du nouvel article");
		$id_article = article_inserer($id_rubrique);
		journaliser("Nouvel article créee : $id_article", 1);
		//print_r($entry['article']);
		article_modifier($id_article, $entry['article']);
		journaliser("Ajout des données dans l'article n° $id_article", 1);


		/* --- AUTEUR => ARTICLE --- */
		journaliser("Création ou récupération des auteurs qui doivent être associées à l'article");
		$id_auteurs = array();
		if($entry['auteur']['nom']) {
			foreach($entry['auteur']['nom'] as $nom_auteur) {
				journaliser("Recherche de l'auteur « $nom_auteur »", 1);

				if($id = sql_getfetsel("id_auteur", "spip_auteurs", 'nom LIKE '.sql_quote("% - $nom_auteur"))) {
					journaliser("« $nom_auteur » trouvé : $id (REGEXP)", 1);
					$id_auteurs[] = $id;
				}
				elseif($id = sql_getfetsel("id_auteur", "spip_auteurs", 'nom='.sql_quote($nom_auteur))) {
					journaliser("« $nom_auteur » trouvé : $id", 1);
					$id_auteurs[] = $id;
				}
				else {
					$id = auteur_inserer();
					auteur_modifier($id, array('nom' => $nom_auteur, 'statut' => '1comite'));
					$id_auteurs[] = $id;
					journaliser("« $nom_auteur » créé : $id", 1);
				}
			}

			foreach($id_auteurs as $id) {
				journaliser("Association de l'auteur %s à l'article", $id, 1);
				auteur_associer($id, array('article' => $id_article));
			}
		}

		if(!in_array(1, $id_auteurs)) {
			journaliser("Retrait de l'auteur 1", 1);
			auteur_dissocier(1, array('article' => $id_article));
		}


		/* --- CAMPAGNE --- */
		if($entry['metadata']['campagne']) {
			journaliser("Ajout des mots-clés de campagne");

			foreach($entry['metadata']['campagne'] as $campagne) {
				journaliser("Association du mot clé %s à l'article %s", $campagne, $id_article, 1);
				mot_associer($campagne, array('article' => $id_article));
			}
		}


		/* --- ATTACPÉDIA --- */
		if($entry['metadata']['attacpedia']) {
			journaliser("Ajout des mots-clés Attacpédia");

			foreach($entry['metadata']['attacpedia'] as $attacpedia) {
				journaliser("Association du mot clé %s à l'article %s", $attacpedia, $id_article, 1);
				mot_associer($attacpedia, array('article' => $id_article));
			}
		}


		/* EVENEMENT */
		if($type == "evenement") {
			journaliser("Création de l'évènement");

			foreach($entry['evenement']['dates'] as $dates_evt) {
				$id_evenement = evenement_inserer($id_article);
				journaliser("Numéro de l'évènement : %s", $id_evenement, 1);

				evenement_modifier($id_evenement, array('titre' => $entry['article']['titre'],
									'lieu' => $entry['evenement']['lieu'],
									'date_debut' => $dates_evt['date_debut'],
									'date_fin' => $dates_evt['date_fin'],
									'statut' => $entry['article']['statut']));

				journaliser("Géolocation GIS", 1);
				$id_gis = gis_inserer();
				journaliser("Création du lieu GIS : %s", $id_gis, 1);
				gis_modifier($id_gis, $entry['gis']);
				lier_gis($id_gis, "evenement", $id_evenement);
				journaliser("Lien de l'évènement %s au lieu %s", $id_evenement, $id_gis, 1);
			}
		}

		/* --- TYPE D'EVENEMENT --- */
		if($type == "evenement") {
			$id_mot_drupal = $entry['metadata']['type_evenement'];
			$mod_evt = $tables_conversion['type_evenement'][$id_mot_drupal];
			mot_associer($mod_evt, array('article' => $id_article));
			journaliser("Association du mot clé %s/%s à l'article %s", $id_mot_drupal, $mod_evt, $id_article, 1);
		}

		/* --- PIECES JOINTES --- */
		if($entry['documents']['pj']) {
			journaliser("Ajout des pièces jointes");

			foreach($entry['documents']['pj'] as $document) {
				$id_document = importer_document($document['filepath'], $document['filename'], $id_article);
				journaliser(sprintf("Document %s importé", $id_document), 1);
			}
		}


		/* --- GALERIES PHOTO --- */
		if($entry['documents']['photos']) {
			journaliser("Ajout des pĥotos jointes");

			foreach($entry['documents']['photos'] as $document) {
				$id_document = importer_document($document['filepath'], $document['filename'], $id_article, "document");
				journaliser(sprintf("Document %s importé", $id_document), 1);
			}
		}

		/* --- URL --- */
		journaliser("Gestion des URL");
		$urls = array();
		$urls[] = "node/".$entry['metadata']['nid'];
		$urls[] = $entry['url']['url'];
		foreach($urls as $url) {
			if($url) {
				if($url_existante = sql_fetsel(array('id_objet', 'type'), 'spip_urls', 'url='.sql_quote($url))) {
					journaliser(sprintf("URL ($url) déjà existante (%s / %s)", $url_existante['type'], $url_existante['id_objet']), 4);
				}
				else {
					$sql_url = array('url' => $url,
							 'type' => 'article',
							 'id_objet' => $id_article,
							 'perma' => 1,
							 'id_parent' => $id_rubrique);
					url_insert($sql_url, false, '-');
					journaliser(sprintf("URL ajoutée : %s/%s", $GLOBALS['meta']['adresse_site'], $url), 1);
				}
			}
		}

		/* --- LOGO --- */
		if($entry['logo']['chemin']) {
			journaliser("Import du logo");
			$remote = sprintf("http://www.france.attac.org/%s", $entry['logo']['chemin']);
			$filename = $entry['logo']['fichier'];
			$fic = copie_locale($remote, 'auto', determine_upload().$filename);
			journaliser("Logo (${remote}) => ${fic}", 1);

			$spip_image_ajouter = charger_fonction('spip_image_ajouter', 'action');
			$spip_image_ajouter('arton'.$id_article, true, $filename);
			spip_unlink($fic);
		}

		$dest_url = sprintf("%s/%s", $GLOBALS['meta']['adresse_site'], $id_article);
		journaliser(sprintf("Article publié : <%s>", $dest_url), 2);

		binlog($entry['metadata']['nid'],
		       $entry['metadata']['type'],
		       $id_article,
		       $entry['article']['titre'],
		       $dest_url);
	}
}



/* 
 * ===== Fonction de convertion qui sont plus ou moins standards.
 */

/* 
 * Prend une date au format Drupal (timestamp) et retourne une date au
 * format SPIP.
 */
function d2s_timestamp($t) {
	return date('Y-m-d H:i:s', $t);
}

/* 
 * Prend un statut de node Drupal et le converti en statut SPIP.
 * FIXME: à compléter.
 */
function d2s_statut($statut) {
	$table = array('1' => 'publie',
		       '0' => 'prepa');

	return $table[$statut];
}

/* 
 * Prend un texte au format HTML et retourne un beau texte avec la
 * syntaxe SPIP (repose sur la bibliothèque html2spip).
 */
function d2s_sale($texte) {
	$texte = str_replace('france-dev.attac.org', 'france.attac.org', $texte);
	$texte = preg_replace("#/sites/default/files/imagecache/[^/]+/#", "/sites/default/files/", $texte);

	try {
		$parser = new HTML2SPIPEngine('', _DIR_IMG);    // Quels sont les bons parametres ?
		$parser->loggingDisable();
		$output = $parser->translate($texte);

		return $output['default'];
	}
	catch(Exception $e) {
		return $texte;
	}
}



/* 
 * ===== Fonctions spécifiques au site à convertir =====
 *
 * On retrouve ici les tables de convertion (identifiant d’un tag d’un
 * node Drupal associé au mot-clé SPIP à associer par exemple). C’est
 * un peu le bordel là dedans, je suis bien d’accord.
 *
 * Les fonctions et tableaux sont ceux utilisés pour convertir le site
 * d’Attac France. Ils ont été coupés pour des raisons de lisibilité.
 */
$tables_conversion = array();
$tables_conversion['campagnes'] = array(4593 => 83, // Forum Social Mondial de Tunis
					/* […] */
					63 => 90, // Faire entendre les exigences citoyennes sur les retraites
					);

$tables_conversion['attacpedia'] = array(93 => 91, // Altermondialisme
					 // […]
					 146 => 65, // Taxe Tobin
					 );

$tables_conversion["type_evenement"] = array(793 => 107, // Conférence
					     /* […] */
                                             794 => 118, // Évènement national
                                             );

$tables_conversion['types_articles'] = array(59 => 37, // 4 pages
					     /* […] */
					     1040 => 44, // Compte-rendu
					     0 => false, // Sans champ « type d'article »
					     );

$tables_conversion['mobilisations'] = array(4593 => 46, // Forum Social Mondial de Tunis
					    /* […] */
					    63 => 47, // Faire entendre les exigences citoyennes sur les retraites
					    );

/* 
 * Récupère la liste des auteurs d’un article Drupal (ici, utilise des
 * tags pour les auteurs).
 */
function d2s_auteurs($auteurs_obj) {
	$auteurs = array();
	foreach($auteurs_obj as $auteur_obj)
		$auteurs[] = $auteur_obj->name;

	return $auteurs;
}

/* 
 * Récupère les pièces jointes d’un node Drupal.
 */
function d2s_pj($pj_objs) {
	$pj = array();

	foreach($pj_objs as $pj_obj) {
		$pj[] = array('filepath' => "http://www.france.attac.org/".$pj_obj->filepath,
			      'filename' => $pj_obj->filename);
	}

	return $pj;
}

/* 
 * Récupère les fichiers PDF pour les node utilisant le module PDFjs
 * de Drupal.
 */
function d2s_pdf($pj_objs) {
	$pj = array();

	foreach($pj_objs as $pj_obj) {
		if(!empty($pj_obj))
			$pj[] = array('filepath' => "http://www.france.attac.org/".$pj_obj["filepath"],
				      'filename' => $pj_obj["filename"]);
	}

	return $pj;
}

/* 
 * Récupères les photos jointes à un article.
 */
function d2s_photos($photos_drupal) {
	$photos = array();

	foreach($photos_drupal as $photo) {
		$photos[] = array('filepath' => "http://www.france.attac.org/".$photo["filepath"],
				  'filename' => $photo["filename"]);
	}

	return $photos;
}

/* 
 * Pour les nœuds de type vidéo, récupère l’emplacement de la vidéo,
 * l’importe dans SPIP et retourne le numéro de document
 * correspondant. Repose sur un bout de code du plugin SPIP Vidéos.
 */
function d2s_video($str) {
	if(preg_match("/iframe/", $str)) {
		if(preg_match("#<iframe src=\"http://player.vimeo.com/video/([0-9]+)#", $str, $result))
			$str = "http://vimeo.com/".$result[1];
		elseif(preg_match("#<a href=\"(http://www.dailymotion.com/video/[^\"]+)\"#", $str, $result))
			$str = $result[1];
		elseif(preg_match("#(http:)?//www.youtube.com/embed/([^&\"]+)#", $str, $result)) {
			$str = "http://www.youtube.com/watch?v=".$result[2];
		}
		else {
			echo "Impossible de récupérer la vidéo !";
		}
	}

	try {
		$doc = importer_video($str);
		journaliser("Vidéo %s importée", $doc, 1);

		if(!$doc)
			journaliser("Erreur lors de l'importation de la vidéo suivante : $str\n", 99);
		else
			return "<video$doc>";
	}
	catch(Exception $e) {
		journaliser("Erreur : ".$e->getMessage(), 99);
	}
}

function d2s_audio($str) {
	return "http://www.france.attac.org/".$str;
}

function d2s_audio_desc($str) {
	return $str;
}

function d2s_typearticle($str) {
	return array_shift($str);
}

function d2s_type_evenement($str) {
	return array_shift($str);
}

function d2s_campagne($str) {
	global $tables_conversion;

	$campagnes = array();
	foreach($str as $camp) {
		$campagnes[] = $tables_conversion['campagnes'][$camp["nid"]];
	}

	return $campagnes;
}

function d2s_attacpedia($str) {
	global $tables_conversion;

	$attacpedia = array();
	foreach($str as $drupal_attacpedia) {
		$attacpedia[] = $tables_conversion['attacpedia'][$drupal_attacpedia['value']];
	}

	return $attacpedia;
}

/* 
 * Pour les évènements.
 */
function d2s_date($str) {
	$dates = array();
	foreach($str as $date) {
		$set = array();
		$set['date_debut'] = date("Y-m-d H:i:s", strtotime($date['value'] . "+ 1 hour"));
		$set['date_fin'] = date("Y-m-d H:i:s", strtotime($date['value2'] . "+ 1 hour"));
		$dates[] = $set;
	}

	return $dates;
}

function _d2s_gis($str, $type) {
	preg_match("/GEOMETRYCOLLECTION\(POINT\(([0-9\.]+) ([0-9\.]+)\)\)/", $str, $matches);

	if(count($matches) != 3)
		return false;

	return $type == "lon" ? $matches[1] : $matches[2];
}

function d2s_gis_lat($str) {
	return _d2s_gis($str, "lat");
}

function d2s_gis_lon($str) {
	return _d2s_gis($str, "lon");
}

function d2s_gis_zoom($str) {
	return "5";
}



/*
 * ===== Fonctions et définitions spécifiques ====
 *
 * Ces fonctions sont ici spécifiques au site Drupal à convertir et au
 * site SPIP cible (par exemple les méthodes de convertion des
 * articles, des mots-clés, etc.
 *
 */

/*
 * Le dispatch de base se fait en fonction du type d'article.
 */
function dispatch_story_type($entry) {
	global $tables_conversion;

	isset($entry['metadata']['type_article']) ? $type = $entry['metadata']['type_article'] : $type = 0;

	$cat = $tables_conversion['types_articles'][$type];
	if(!isset($cat)) {
		journaliser("Type d'article %s demandé introuvable", $entry['metadata']['type_article'], 99);
		die();
	}

	$campagne = $entry['metadata']['campagnes'];
	$attacpedia = $entry['metadata']['attacpedia'];

	if($cat === false) {
		$campagnes = $entry['metadata']['campagnes'];

		/*
		 * Si l'article Drupal n'a qu'une seule catégorie, alors, il faut
		 * l'envoyer dans la catégorie correspondant à la campagne dans la
		 * rubrique « mobilisations ». Autrement, il faut l'envoyer dans
		 * la catégorie 54 (à atrier).
		 */
		if(count($campagnes) == 1) {
			$cat = $campagnes[0];
			journaliser("Envoi de l'article dans la rubrique %s", $cat, 1);
		}
		else {
			$cat = 54;
			journaliser("Envoi de l'article dans la rubrique à trier (54)", 1);
		}
	}

	return $cat;
}

/*
 * ==== Fonctions technique (journalisation, binlog, etc.) =====
 *
 * Ce log permet de garder une trace de ce qui est importé (date
 * d’import, type d’article Drupal, numéro d’article, etc.).
 */

/*
 * Afficher un message sur la sortie standard. Elle prend comme
 * premier argument le texte à afficher, ensuite les éventuels
 * arguments de la chaine formatée (format sprintf()) et ensuite un
 * éventuel niveau d’importance.
 *
 * Exemples d’appels :
 *   - journaliser("hello, world");
 *   - journaliser("hello, world", 2);
 *   - journaliser("hello, %s", "world");
 *   - journaliser("hello, %s", "world", 4);
 */
function journaliser() {
	$args = func_get_args();
	$str = array_shift($args);
	$numargs = count($args);

	if(!$str)
		return;

	if(isset($args[$numargs-1]) && is_int($args[$numargs-1]))
		$level = array_pop($args);
	else
		$level = 0;

	if(count($args) > 0) {
		array_unshift($args, $str);
		$str = call_user_func_array("sprintf", $args);
	}

	$levels = array(0 => '[+]', 1 => "    [-]", 2 => "\n==> ", 4 => "    [!]", 99 => "");
	$level_str = $levels[$level];
	echo "$level_str $str\n";
}

/*
 * Affichage de l’aide.
 */
function affichage_aide() {
	$app = $_SERVER['argv'][0];
	echo "Usage: $app <option>\n";
	echo "--help, -h              Affiche cette aide.\n";
	echo "--file, -f [fichier]    Fichier à importer\n";
	echo "-l                      Affiche le contenu du binlog\n";
}

/*
 * Création du binlog, dans le fichier ``tmp/import_binlog.php``. Si
 * celui-ci existe déjà, on désérialise ce fichier.
 */
function binlog_creation() {
	global $binlog;
	$binlog_f = "tmp/import_binlog.php";

	journaliser("Création du fichier binlog : %s", $binlog_f);

	if(file_exists($binlog_f))
		$binlog = unserialize(file_get_contents($binlog_f));
	else
		$binlog = array();
}

/*
 * Ajout d’une entrée dans le fichier binlog.
 */
function binlog($nid, $type, $id_article, $titre, $url) {
	global $binlog;

	$binlog[] = array('date' => date('Y-m-d H:i:s'),
			  'type' => $type,
			  'nid' => $nid,
			  'orig_url' => "http://www.france.attac.org/node/".$nid,
			  'id_article' => $id_article,
			  'url' => $url,
			  'titre' => $titre);

	$binlog_f = "tmp/import_binlog.php";
	file_put_contents($binlog_f, serialize($binlog));
}

/*
 * Permet de vérifier si le fichier binlog contient un node Drupal.
 */
function binlog_contient($nid) {
	global $binlog;

	foreach($binlog as $log_entry) {
		if($log_entry['nid'] == $nid)
			return true;
	}

	return false;
}

/*
 * Fonction permettant d’afficher le fichier binlog sur la sortie
 * standard (résultat de la commande « ./drupal2spip -l »).
 */
function binlog_afficher() {
	global $binlog;

	print_r($binlog);
}


/*
 * ===== Fonctions annexes =====
 */

/*
 * Fonction permettant d’importer un document dans la base
 * SPIP. Utilisé pour importer les pièces jointes.
 */
function importer_document($path, $name, $id_article=null, $mode=null) {
	$action_ajouter_documents = charger_fonction('ajouter_documents', 'action');

	if($id_article != null)
		$objet = "article";
	else
		$objet = false;

	$id_document = $action_ajouter_documents(0,
						 array(array('tmp_name' => $path, 'name' => $name, 'titrer' => false, 'distant' => true)),
						 $objet,
						 $id_article,
						 $mode);

	return $id_document[0];
}

/**
 * Fonction volée dans le plugin Vidéo de SPIP (développé par XDjuj,
 * et distribué sous licence GNU GPLv3).
 *
 * Cette fonction est utilisée pour convertir des vidéos YouTube,
 * Dailymotion ou Viméo en des documents SPIP, qui seront intégrables
 * avec le modèle <videoXXX> (si le plugin Vidéo est bien installé).
 *
 * Code du plugin :
 *   http://zone.spip.org/trac/spip-zone/browser/_plugins_/videos
 * Code de la fonction :
 *   http://zone.spip.org/trac/spip-zone/browser/_plugins_/videos/trunk/formulaires/insertion_video.php
 **/
function importer_video($url) {
	include_spip('inc/acces');

	// Retirer les trucs qui emmerdent : tous les arguments d'ancre / les espaces foireux les http:// et les www. éventuels
	$url = preg_replace('%(#.*$|https?://|www.)%', '', trim($url));

	if(preg_match('/dailymotion/',$url)){
		$type = 'dist_daily';
		$fichier = preg_replace('#dailymotion\.com/video/#','',$url);
	}
	else if(preg_match('/vimeo/',$url)){
		$type = 'dist_vimeo';
		$fichier = preg_replace('#vimeo\.com/#','',$url);
		sleep(1);
	}
	else if(preg_match('/(youtube|youtu\.be)/',$url)){
		$type = 'dist_youtu';
		$fichier = preg_replace('#(youtu\.be/|youtube\.com/watch\?v=|&.*$|\?hd=1)#','',$url);
	}


	$titre = ""; $descriptif = ""; $id_vignette = "";

	// On tente de récupérer titre et description à l'aide de Videopian
	if(!preg_match('/culture/',$url) && (version_compare(PHP_VERSION, '5.2') >= 0)) {

		include_spip('lib_tierces/Videopian'); // http://www.upian.com/upiansource/videopian/
		$Videopian = new Videopian();
		if($Videopian) {
			$infosVideo = $Videopian->get($url);
			$titre = $infosVideo->title;
			$descriptif = $infosVideo->description;
			$nbVignette = abs(count($infosVideo->thumbnails)-1);  // prendre la plus grande vignette
			$logoDocument = $infosVideo->thumbnails[$nbVignette]->url;
			$logoDocument_width = $infosVideo->thumbnails[$nbVignette]->width;
			$logoDocument_height = $infosVideo->thumbnails[$nbVignette]->height;
		} else {
			//echo 'Exception reçue : ',  $e->getMessage(), "\n";
			spip_log("L'ajout automatique du titre et de la description a echoué","Plugin Vidéo(s)");
		}
	}


	// On va pour l'instant utiliser le champ extension pour stocker le type de source
	$champs = array(
			'titre'=>$titre,
			'extension'=>$type,
			'date' => date("Y-m-d H:i:s",time()),
			'descriptif' => $descriptif,
			'fichier'=>$fichier,
			'distant'=>'oui'
			);

	/** Gérer le cas de la présence des champs de Médiathèque (parce que Mediatheque c'est le BIEN mais c'est pas toujours activé) **/
	$trouver_table=charger_fonction('trouver_table','base');
	$desc = $trouver_table('spip_documents');
	if(array_key_exists('taille',$desc['field'])) if($infosVideo) $champs['taille'] = $infosVideo->duration;
	if(array_key_exists('credits',$desc['field'])) if($infosVideo) $champs['credits'] = $infosVideo->author;
	if(array_key_exists('statut',$desc['field'])) $champs['statut'] = 'publie';
	if(array_key_exists('media',$desc['field'])) $champs['media'] = 'video';

	/* Cas de la présence d'une vignette à attacher */
	if($logoDocument){
		include_spip('inc/distant');
		if($fichier = preg_replace("#IMG/#", '', copie_locale($logoDocument))){ // set_spip_doc ne fonctionne pas... Je ne sais pas pourquoi
			$champsVignette['fichier'] = $fichier;
			$champsVignette['mode'] = 'vignette';
			// champs extra à intégrer ds SPIP 3
			if(array_key_exists('statut',$desc['field'])) $champsVignette['statut'] = 'publie';
			if(array_key_exists('media',$desc['field']))  $champsVignette['media'] = 'image';


			// Recuperer les tailles
			$champsVignette['taille'] = @intval(filesize($fichier));
			$size_image = @getimagesize($fichier);
			$champsVignette['largeur'] = intval($size_image[0]);
			$champsVignette['hauteur'] = intval($size_image[1]);
			// $infos['type_image'] = decoder_type_image($size_image[2]);
			if ($champsVignette['largeur']==0) {              // en cas d'echec, recuperer les infos videopian
				$champsVignette['largeur'] = $logoDocument_width;
				$champsVignette['hauteur'] = $logoDocument_height;
			}

			// Ajouter
			$id_vignette = sql_insertq('spip_documents',$champsVignette);
			if($id_vignette) $champs['id_vignette'] = $id_vignette;
		}
		else{ spip_log("Echec de l'insertion du logo $logoDocument pour la video $document","Plugin Vidéo(s)"); }
	}

	$document = sql_insertq('spip_documents',$champs);
	if($document){
		$document_lien = sql_insertq(
					     'spip_documents_liens',
					     array(
						   'id_document'=>$document,
						   'id_objet'=>$id_objet,
						   'objet'=>$objet,
						   'vu'=>'non'
						   )
					     );
	}

	return $document;
}
