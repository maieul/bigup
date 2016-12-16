<?php

namespace Spip\Bigup;

/**
 * Mappage entre Bigup et Flow
 *
 * @plugin     Bigup
 * @copyright  2015
 * @author     marcimat
 * @licence    GNU/GPL
 * @package    SPIP\Bigup\Fonctions
 */

include_spip('inc/Bigup/LogTrait');
include_spip('inc/Bigup/Flow');

/**
 * Gère la validité des requêtes et appelle Flow
**/
class Bigup {

	use LogTrait;

	/**
	 * Login ou identifiant de l'auteur qui intéragit
	 * @var string */
	private $auteur = '';

	/**
	 * Nom du formulaire qui utilise flow
	 * @var string */
	private $formulaire = '';

	/**
	 * Hash des arguments du formulaire
	 * @var string */
	private $formulaire_args = '';

	/**
	 * Identifie un formulaire par rapport à un autre identique sur la même page ayant un appel différent.
	 * @var string */
	private $formulaire_identifiant = '';

	/**
	 * Nom du champ dans le formulaire qui utilise flow
	 * @var string */
	private $champ = '';

	/**
	 * Token de la forme `champ:time:cle`
	 * @var string
	**/
	private $token = '';
	
	/**
	 * Expiration du token (en secondes)
	 *
	 * @todo À définir en configuration
	 * @var int
	**/
	private $token_expiration = 3600 * 24;

	/**
	 * Nom d'une action demandée
	 *
	 * Si pas de précision => gestion par Flow
	 * 
	 * @var string
	**/
	private $action = '';

	/**
	 * Identifiant d'un fichier (en cas de suppression demandée)
	 *
	 * Cet identifiant est soit un md5 du chemin du fichier sur le serveur
	 * (envoyé dans la clé 'identifiant' des fichiers déjà présents pour ce formulaire),
	 *
	 * Soit un identifiant (uniqueIdentifier) qui sert au rangement du fichier, calculé
	 * par Flow.js ou Resumable.js à partir du nom et de la taille du fichier.
	 * Cet identifiant là est envoyé si on annule un fichier en cours de téléversement.
	 *
	 * @var string
	**/
	private $identifiant = '';

	/**
	 * Nom du répertoire, dans _DIR_TMP, qui va stocker les fichiers et morceaux de fichiers
	 * @var string */
	private $cache_dir = 'bigupload';

	/**
	 * Chemin du répertoire stockant les morceaux de fichiers
	 * @var string */
	 private $dir_parts = '';

	/**
	 * Chemin du répertoire stockant les fichiers terminés
	 * @var string */
	 private $dir_final = '';


	/**
	 * Constructeur
	 *
	 * @param string $formulaire Nom du formulaire
	 * @param string $formulaire_args Hash du formulaire
	 * @param string $token Jeton d'autorisation
	**/
	public function __construct($formulaire = '', $formulaire_args = '', $token = '') {
		$this->token = $token;
		$this->formulaire = $formulaire;
		$this->formulaire_args = $formulaire_args;
		$this->identifier_auteur();
		$this->identifier_formulaire();
	}

	/**
	 * Retrouve les paramètres pertinents pour gérer le test ou la réception de fichiers.
	**/
	public function recuperer_parametres() {
		// obligatoires
		$this->token           = _request('bigup_token');
		$this->formulaire      = _request('formulaire_action');
		$this->formulaire_args = _request('formulaire_action_args');
		// optionnels
		$this->action          = _request('bigup_action');
		$this->identifiant     = _request('identifiant');
		$this->identifier_formulaire();
	}

	/**
	 * Répondre
	 *
	 * Envoie un statut HTTP de réponse et quitte, en fonction de ce qui était demandé,
	 *
	 * - soit tester un morceau de fichier,
	 * - soit réceptionner un morceau de fichier,
	 * - soit effacer un fichier
	 *
	 * Si les hash ne correspondaient pas, le programme quitte évidemment.
	**/
	public function repondre() {
		if (!$this->verifier_token()) {
			return $this->send(403);
		}

		$this->calculer_chemin_repertoires();

		if ($this->action) {
			$repondre_action = 'repondre_' . $this->action;
			if (method_exists($this, $repondre_action)) {
				return $this->$repondre_action();
			}
			$action_externe = charger_fonction('bigup_' . $repondre_action, 'action', true);
			if ($action_externe = charger_fonction('bigup_' . $repondre_action, 'action', true)) {
				return $action_externe($this);
			}
			// Action inconnue.
			return $this->send(403);
		}

		return $this->repondre_flow();
	}

	/**
	 * Répondre le cas de suppression d'un fichier
	 *
	 * L'identifiant de fichier est le md5 du chemin de stockage.
	**/
	public function repondre_effacer() {
		if (!$this->identifiant) {
			return $this->send(404);
		}
		// si c'est un md5, c'est l'identifiant
		if (strlen($this->identifiant) == 32 and ctype_xdigit($this->identifiant)) {
			if ($this->enlever_fichier_depuis_identifiant($this->identifiant)) {
				return $this->send(201);
			}
		} elseif ($this->enlever_fichier_depuis_repertoire($this->identifiant)) {
			return $this->send(201);
		}
		return $this->send(404);
	}


	/**
	 * Répondre le cas de réception ou test de morceau de fichier
	**/
	public function repondre_flow() {
		include_spip('inc/Bigup/Flow');
		$flow = new Flow();
		$flow->definir_repertoire('parts', $this->dir_parts);
		$flow->definir_repertoire('final', $this->dir_final);
		$res = $flow->run();

		// le fichier est complet
		if (is_string($res)) {
			// remettre le fichier dans $FILES
			# $this->integrer_fichier($this->champ, $res);

			// on demande à nettoyer le répertoire des fichiers dans la foulée
			job_queue_add(
				'bigup_nettoyer_repertoire_upload',
				'Nettoyer répertoires et fichiers de Big Upload',
				array(0),
				'genie/'
			);

			// envoyer quelques infos sur le fichier reçu
			$desc = $this->decrire_fichier($res);
			// ne pas permettre de connaître le chemin complet
			unset($desc['pathname'], $desc['tmp_name']);

			// nettoyer le chemin des répertoires temporaires du coup.
			$this->supprimer_repertoire_fichier(dirname($res), 'parts');

			$this->send(200, $desc);
		}

		if (is_int($res)) {
			$this->send($res);
		}

		$this->send(415);
	}

	/**
	 * Retrouve les fichiers qui ont été téléchargés et sont en attente pour ce formulaire
	 * et prépare le tableau d'environnement
	 *
	 * @return array
	 */
	public function retrouver_fichiers() {
		$this->calculer_chemin_repertoires();
		$liste = $this->trouver_fichiers_complets();
		$liste = $this->organiser_fichiers_complets($liste);
		return $liste;
	}

	/**
	 * Retrouve les fichiers qui ont été téléchargés et sont en attente pour ce formulaire
	 * et les réaffecte à `$_FILES` au passage.
	 *
	 * @return array
	**/
	public function reinserer_fichiers() {
		$this->calculer_chemin_repertoires();
		$liste = $this->trouver_fichiers_complets();
		foreach ($liste as $champ => $fichiers) {
			foreach ($fichiers as $description) {
				$this->integrer_fichier($description);
			}
		}
		return $liste;
	}


	/**
	 * Enlève un fichier complet dont l'identifiant est indiqué
	 *
	 * @param string $identifiant
	 *     Un identifiant du fichier
	 * @return bool True si le fichier est trouvé (et donc enlevé)
	**/
	public function enlever_fichier_depuis_identifiant($identifiant) {
		$this->calculer_chemin_repertoires();
		$liste = $this->trouver_fichiers_complets();
		$this->debug("Demande de suppression du fichier $identifiant");

		foreach ($liste as $champ => $fichiers) {
			foreach ($fichiers as $k => $description) {
				if ($description['identifiant'] == $identifiant) {
					// en théorie, le chemin 'parts' a déjà été nettoyé
					$this->supprimer_repertoire_fichier(dirname($description['pathname']), 'final');
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Enlève un fichier (probablement partiel) dont le nom est indiqué
	 *
	 * @param string $repertoire
	 *     Un repertoire de stockage du fichier.
	 *     Il correspond au `uniqueIdentifier` transmis par le JS
	 * @return bool True si le fichier est trouvé (et donc enlevé)
	 **/
	public function enlever_fichier_depuis_repertoire($repertoire) {
		$this->calculer_chemin_repertoires();
		$this->debug("Demande de suppression du fichier dans $repertoire");
		$this->supprimer_repertoire_fichier($this->dir_final . DIRECTORY_SEPARATOR . $repertoire, 'tout');
		return true;
	}

	/**
	 * Efface tous les fichiers envoyés pour ce formulaire par un auteur.
	 */
	public function effacer_fichiers() {
		$this->calculer_chemin_repertoires();
		$this->debug("Suppression des fichiers restants");
		$this->supprimer_repertoire_fichier($this->dir_final, 'tout');
		return true;
	}

	/**
	 * Supprimer le répertoire indiqué et les répertoires parents éventuellement.
	 *
	 *
	 * @param string $chemin
	 *     Chemin du répertoire stockant un fichier bigup
	 * @param string $quoi
	 *     Quelle partie supprimer : 'final', 'parts' ou 'tout' (les 2)
	 * @return bool
	 */
	function supprimer_repertoire_fichier($chemin, $quoi = 'tout') {

		// on vérifie que ce chemin concerne bigup uniquement
		if (strpos($chemin, $this->dir_final) === 0) {
			$path = substr($chemin, strlen($this->dir_final));
		} elseif (strpos($chemin, $this->dir_parts) === 0) {
			$path = substr($chemin, strlen($this->dir_final));
		} else {
			return false;
		}

		include_spip('inc/flock');

		// Suppression du contenu du fichier final
		if (in_array($quoi, ['tout', 'final'])) {
			supprimer_repertoire($this->dir_final . $path);
			$this->supprimer_repertoires_vides($this->dir_final);
		}

		// Suppression du contenu des morcaux du fichier
		if (in_array($quoi, ['tout', 'parts'])) {
			supprimer_repertoire($this->dir_parts . $path);
			$this->supprimer_repertoires_vides($this->dir_parts);
		}

		return true;
	}

	/**
	 * Supprimer les répertoires intermédiaires jusqu'au chemin indiqué si leurs contenus sont vides.
	 *
	 * S'il n'y avait qu'un seul fichier dans tmp/bigupload, tout sera nettoyé, jusqu'au répertoire bigupload.
	 *
	 * @param string $chemin Chemin du fichier dont on veut nettoyer le répertoire de stockage de ce fichier
	 * @return bool
	 */
	function supprimer_repertoires_vides($chemin) {
		// on se restreint au répertoire cache de bigup tout de même.
		if (strpos($chemin, _DIR_TMP . $this->cache_dir) !== 0) {
			return false;
		}

		$chemin = substr($chemin, strlen(_DIR_TMP));
		while ($chemin and ($chemin !== '.')) {
			if (!is_dir(_DIR_TMP . $chemin)) {
				$chemin = dirname($chemin);
				continue;
			}

			$fichiers = scandir(_DIR_TMP . $chemin);
			if ($fichiers === false) {
				$chemin = dirname($chemin);
				continue;
			}

			$fichiers = array_diff($fichiers, ['..', '.', '.ok']);
			if (!$fichiers) {
				supprimer_repertoire(_DIR_TMP . $chemin);
				$chemin = dirname($chemin);
				continue;
			}

			return true;
		}
		return true;
	}

	/**
	 * Groupe en tableau les fichiers trouvés
	 *
	 * Si un champ est nommé tel que `un[sous][dossier][]` la fonction
	 * mettra la description du fichier dans un tableau php équivalent.
	 *
	 * @param array $liste Liste [ champ => [ description ]]
	 * @return array Tableau [ racine => [ cle1 => [ cle2 => ... => [ description ]]]]
	 **/
	public function organiser_fichiers_complets($liste) {
		$tries = [];
		foreach ($liste as $champ => $fichiers) {
			foreach ($fichiers as $description) {
				// recréer le tableau lorsque $champ = "a[b][c][]".
				$arborescence = explode('[', str_replace(']', '', $champ));
				$me = &$tries;
				$dernier = array_pop($arborescence);
				foreach ($arborescence as $a) {
					if (!array_key_exists($a, $me)) {
						$me[$a] = array();
					}
					$me = &$me[$a];
				}
				if (strlen($dernier)) {
					$me[$dernier] = $description;
				} else {
					$me[] = $description;
				}
			}
		}
		return $tries;
	}


	/**
	 * Retourne la liste des fichiers complets, classés par champ
	 *
	 * @return array Liste [ champ => [ chemin ]]
	**/
	public function trouver_fichiers_complets() {
		// la théorie veut ce rangement :
		// $dir/{champ}/{identifiant_fichier}/{nom du fichier.extension}
		$directory = $this->dir_final;

		// pas de répertoire… pas de fichier… simple comme bonjour :)
		if (!is_dir($directory)) {
			return [];
		}

		$liste = [];

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory)
		);

		foreach ($files as $filename) {
			if ($filename->isDir()) continue; // . ..
			if ($filename->getFilename()[0] == '.') continue; // .ok

			$chemin = $filename->getPathname();
			$champ = $this->retrouver_champ_depuis_chemin($chemin);

			if (empty($liste[$champ])) {
				$liste[$champ] = [];
			}
			$liste[$champ][] = $this->decrire_fichier($chemin);
			$this->debug("Fichier retrouvé : $chemin");
		}

		return $liste;
	}

	/**
	 * Retrouve un nom de champ depuis un chemin de cache de fichier
	 *
	 * @param string $chemin
	 *     Chemin de stockage du fichier dans le cache de bigupload
	 * @return string
	 *     Nom du champ (valeur de l'attribut name de l'input d'origine)
	 */
	function retrouver_champ_depuis_chemin($chemin) {
		return basename(dirname(dirname($chemin)));
	}

	/**
	 * Vérifier le token utilisé
	 *
	 * Le token doit arriver, de la forme `champ:time:clé`
	 * De même que formulaire_action et formulaire_action_args
	 *
	 * Le temps ne doit pas être trop vieux d'une part,
	 * et la clé de sécurité doit évidemment être valide.
	 * 
	 * @return bool
	**/
	public function verifier_token() {
		if (!$this->token) {
			$this->debug("Aucun token");
			return false;
		}

		$_token = explode(':', $this->token);

		if (count($_token) != 3) {
			$this->debug("Token mal formé");
			return false;
		}

		list($champ, $time, $cle) = $_token;
		$time = intval($time);
		$now = time();


		if (($now - $time) > $this->token_expiration) {
			$this->log("Token expiré");
			return false;
		}

		if (!$this->formulaire) {
			$this->log("Vérifier token : nom du formulaire absent");
			return false;
		}

		if (!$this->formulaire_args) {
			$this->log("Vérifier token : hash du formulaire absent");
			return false;
		}

		include_spip('inc/securiser_action');
		if (!verifier_action_auteur("bigup/$this->formulaire/$this->formulaire_args/$champ/$time", $cle)) {
			$this->error("Token invalide");
			return false;
		}

		$this->champ = $champ;

		$this->debug("Token OK : formulaire $this->formulaire, champ $champ, identifiant $this->formulaire_identifiant");

		return true;
	}


	/**
	 * Calcule les chemins des répertoires de travail
	 * qui stockent les morceaux de fichiers et les fichiers complétés
	**/
	public function calculer_chemin_repertoires() {
		$this->dir_parts = $this->calculer_chemin_repertoire('parts');
		$this->dir_final = $this->calculer_chemin_repertoire('final');
	}

	/**
	 * Calcule un chemin de répertoire de travail d'un type donné
	 * @return string
	**/
	public function calculer_chemin_repertoire($type) {
		return
			_DIR_TMP . $this->cache_dir
			. DIRECTORY_SEPARATOR . $type
			. DIRECTORY_SEPARATOR . $this->auteur
			. DIRECTORY_SEPARATOR . $this->formulaire
			. DIRECTORY_SEPARATOR . $this->formulaire_identifiant
			. DIRECTORY_SEPARATOR . $this->champ;
	}

	/**
	 * Identifier l'auteur qui accède
	 *
	 * Retrouve un identifiant unique, même pour les auteurs anonymes.
	 * Si on connait l'auteur, on essaie de mettre un nom humain
	 * pour une meilleure visibilité du répertoire.
	 * 
	 * Retourne un identifiant d'auteur :
	 * - {id_auteur}.{login} sinon
	 * - {id_auteur} sinon
	 * - 0.{session_id}
	 *
	 * @return string
	**/
	public function identifier_auteur() {
		// un nom d'identifiant humain si possible
		include_spip('inc/session');
		$identifiant = session_get('id_auteur');
		// visiteur anonyme ? on prend un identifiant de session PHP.
		if (!$identifiant) {
			if (session_status() == PHP_SESSION_NONE) {
				session_start();
			}
			$identifiant .= "." . session_id();
		} elseif ($login = session_get('login')) {
			$identifiant .= "." . $login;
		}
		return $this->auteur = $identifiant;
	}

	/**
	 * Calcule un identifiant de formulaire en fonction de ses arguments
	 *
	 * @return string l'identifiant
	**/
	public function identifier_formulaire() {
		return $this->formulaire_identifiant = substr(md5($this->formulaire_args), 0, 6);
	}

	/**
	 * Intégrer le fichier indiqué dans `$FILES`
	 *
	 * Tout dépend de l'attribut name qui a été posté.
	 * Cette info doit se trouver dans le tableau reçu
	 * (ou calculé si on envoie le chemin du fichier en cache)
	 * dans la clé 'champ'.
	 *
	 * - name='a' : FILES[a][name] = 'x'
	 * - name='a[]' : FILES[a][name][0] = 'x'
	 * - name='a[b]' : FILES[a][name][b] = 'x'
	 * - name='a[b][]' : FILES[a][name][b][0] = 'x'
	 *
	 * @param string|array $description
	 *     array : Description déjà calculée
	 *     string : chemin du fichier
	 * @return array
	 *     Description du fichier
	**/
	public function integrer_fichier($description) {
		if (!is_array($description)) {
			$description = $this->decrire_fichier($description); 
		}
		// la valeur complete du name.
		$champ = $description['champ'];
		$arborescence = explode('[', str_replace(']', '', $champ));
		$racine = array_shift($arborescence);

		if (!count($arborescence)) {
			// le plus simple…
			$_FILES[$racine] = $description;
		} else {
			if (!array_key_exists($racine, $_FILES)) {
				$_FILES[$racine] = [];
			}
			foreach ($description as $cle => $valeur) {
				if (!array_key_exists($cle, $_FILES[$racine])) {
					$_FILES[$racine][$cle] = [];
				}
				$me = &$_FILES[$racine][$cle];
				$dernier = array_pop($arborescence);
				foreach ($arborescence as $a) {
					if (!array_key_exists($a, $me)) {
						$me[$a] = [];
					}
					$me = &$me[$a];
				}
				if (strlen($dernier)) {
					$me[$dernier] = $valeur;
				} else {
					$me[] = $valeur;
				}
			}
		}

		return $description;
	}

	/**
	 * Décrire un fichier (comme dans `$_FILES`)
	 *
	 * @uses retrouver_champ_depuis_chemin()
	 * @param string $chemin
	 *     Chemin du fichier dans le cache de bigup.
	 * @return array
	**/
	public function decrire_fichier($chemin) {
		$filename = basename($chemin);
		$extension = pathinfo($chemin, PATHINFO_EXTENSION);
		$champ = $this->retrouver_champ_depuis_chemin($chemin);
		include_spip('action/ajouter_documents');
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$desc = [
			// présent dans $_FILES
			'name' => $filename,
			'tmp_name' => $chemin,
			'size' => filesize($chemin),
			'type' => finfo_file($finfo, $chemin),
			'error' => 0, // hum
			// informations supplémentaires (pas dans $_FILES habituellement)
			'pathname' => $chemin,
			'identifiant' => md5($chemin),
			'extension' => corriger_extension(strtolower($extension)),
			'champ' => $champ,
		];
		return $desc;
	}


	/**
	 * Envoie le code header indiqué… et arrête tout.
	 *
	 * @param int $code
	 * @param array|null $data Données à faire envoyer en json
	 * @return void
	**/
	public function send($code, $data = null) {
		$this->debug("> send $code");
		http_response_code($code);
		if ($data) {
			header("Content-Type: application/json; charset=" . $GLOBALS['meta']['charset']);
			echo json_encode($data);
		}
		exit;
	}


}
