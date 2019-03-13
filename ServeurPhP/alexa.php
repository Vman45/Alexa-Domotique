<?php
	// Lecture du fichier de données de ma station météo
	$fp = fopen ("/home/pi/station/alexa/temp.txt", "r");
	$contenu_du_fichier = fgets ($fp, 255);
	fclose ($fp);
	
	// Lecture du fichier avec votre ID
	$fp = fopen ("data/para.txt", "r");
	$validAppId = fgets ($fp, 255);
	fclose ($fp);	
	
	// Récupération du contenu de la requête envoyée par Alexa
	$json = file_get_contents('php://input');
	//Décode JSON
	$requete = json_decode($json);
	
	// Decode the JSON
	$alexaRequest = json_decode($json);
	// Configuration des Objets connectés
	$jsondevice = file_get_contents ("/var/www/html/data/device.txt");
	$device = json_decode($jsondevice);
	// Commande des objets
	$jsoncde = file_get_contents ("/var/www/html/data/commande.txt");
	$cdedevice = json_decode($jsoncde);
	
	// Vérifions si Amazon est à l'origine de la requête
	
	$validIP = array("72.21.217.","54.240.197.");
	$isAllowedHost = false;
	
	
	foreach($validIP as $ip){
		if (stristr($_SERVER['REMOTE_ADDR'], $ip)){
			$isAllowedHost = true;
			break;
		}
	}
	
	// Si Amazon n'est pas expéditeur
	if ($isAllowedHost == false) ThrowRequestError(400, "Forbidden, your Host is not allowed to make this request!");
	
	unset($isAllowedHost);
	
	// Vérification de l'ID de l'application
	if ($alexaRequest->session->application->applicationId === $validAppId) {
		
		// Vérification de la chaine de signature SSL par expression régulière
		if (preg_match("/https:\/\/s3.amazonaws.com(\:443)?\/echo.api\/*/i", $_SERVER['HTTP_SIGNATURECERTCHAINURL']) == false){
			ThrowRequestError(400, "Forbidden, unkown SSL Chain Origin!");
		}
		// Vérification du certificatPEM
		// Récupération en cache
		$local_pem_hash_file = sys_get_temp_dir() . '/' . hash("sha256", $_SERVER['HTTP_SIGNATURECERTCHAINURL']) . ".pem";
		if (!file_exists($local_pem_hash_file)){
			file_put_contents($local_pem_hash_file, file_get_contents($_SERVER['HTTP_SIGNATURECERTCHAINURL']));
		}
		$local_pem = file_get_contents($local_pem_hash_file);
		if (openssl_verify($json, base64_decode($_SERVER['HTTP_SIGNATURE']) , $local_pem) !== 1){
			ThrowRequestError(400, "Forbidden, failed to verify SSL Signature!");
		}
		// Vérifications complémentaires
		$cert = openssl_x509_parse($local_pem);
		if (empty($cert)) ThrowRequestError(400, "Certificate parsing failed!");
		// SANs Check
		if (stristr($cert['extensions']['subjectAltName'], 'echo-api.amazon.com') != true) ThrowRequestError(400, "Forbidden! Certificate SANs Check failed!");
		
		// Vérification de l'expiration
		if ($cert['validTo_time_t'] < time()){
			ThrowRequestError(400, "Forbidden! Certificate no longer Valid!");
			// Deleting locally cached file to fetch a new at next req
			if (file_exists($local_pem_hash_file)) unlink($local_pem_hash_file);
		}
		// Nettoyage
		unset($local_pem_hash_file, $cert, $local_pem);
		//la requête a été envoyée depuis moins d'une minute.	
		if (time() - strtotime($requete->request->timestamp) > 60) ThrowRequestError(400, "Request Timeout! Request timestamp is to old.");
		
		// On récupère le type de requête
		$RequestType = $requete->request->type;
		
		if ($RequestType == "LaunchRequest"){
			$ReturnValue =  array (
			'version' => '1.0',
			'sessionAttributes' => 
			array (
			'countActionList' => 
			array (
			'read' => true,
			'category' => true,
			),
			),
			'response' => 
			array (
			'shouldEndSession' => false,
			'outputSpeech' => 
			array (
			'type' => 'SSML',
			'ssml' => '<speak>'."Bienvenue dans ma maison connectée".'</speak>',
			),
			),
			);		
		}
		elseif ($RequestType == "SessionEndedRequest"){
			
			$ReturnValue =  array(
			'type' => 'SessionEndedRequest',
			'requestId' => $requete->request->requestId,
			'timestamp' => date("c"),
			'reason' => 'USER_INITIATED ',
			'error'=>
			array(
			'type'=>'INTERNAL_ERROR',
			'messsage'=>'erreur',
			),
			
			);
			// Ici le code pour SessionEndedRequest
		}
		elseif ($RequestType == "IntentRequest"){
			
			if ($requete->request->intent->name == "AMAZON.HelpIntent"){
				$ReturnValue =  array (
				'version' => '1.0',
				'sessionAttributes' => 
				array (
				'countActionList' => 
				array (
				'read' => true,
				'category' => true,
				),
				),
				'response' => 
				array (
				'shouldEndSession' => false,
				'outputSpeech' => 
				array (
				'type' => 'SSML',
				'ssml' => '<speak>'."Voici les commandes".'<break time="1s"/>  donne moi les températures'.'</speak>',
				),
				),
				);		
			}
			
			if ($requete->request->intent->name == "AMAZON.StopIntent"){
				// Message de fin en fonction de l'heure
				$heure = date('G'); // donne l'heure
				if ($heure < 8) { // message entre minuit et 7h59
					$message= 'Je vous souhaite une bonne nuit.';
					} else {
					if ($heure < 18) { // message  entre 8h00 et 17h59
						$message= 'Je vous souhaite une bonne journée.';
						} else { // message entre 18h00 et 23h59
						$message= 'Je vous souhaite une bonne soirée';
					}
				}
				$ReturnValue =  array (
				'version' => '1.0',
				'sessionAttributes' => 
				array (
				'countActionList' => 
				array (
				'read' => true,
				'category' => true,
				),
				),
				'response' => 
				array (
				'shouldEndSession' => true,
				'outputSpeech' => 
				array (
				'type' => 'SSML',
				'ssml' => "<speak>Fin de l'utilisation de la skill domotique. ".$message.'</speak>',
				),
				),
				);		
			}
			
			
			if ($requete->request->intent->name == "IntActions"){
				$end=false;
				// Parcours des valeurs des intents 
				$Slot=array("UCommande","Uquoi","UZone","UValeur","Ucouleur");
				foreach ($Slot as &$value) {
					$SlotValeur.=($requete->request->intent->slots->$value->value);
				}
				unset($value);
				
				
				if ($SlotValeur<>"") {		// Intence vide ?
					
					if ($requete->request->intent->slots->UQuoi->value<>"") {
						// lecture des valeur de la requête
						$Quoi=$requete->request->intent->slots->UQuoi->value;
						$Zone=$requete->request->intent->slots->UZone->value;
						$Commande=$requete->request->intent->slots->UCommande->value;
						$Couleur=$requete->request->intent->slots->UCouleur->value;
						$Liaison=$requete->request->intent->slots->ULiaison->value;
						$Valeur=$requete->request->intent->slots->UValeur->value;
						$Vmessage="je n'ai pas bien compris votre demande, que voulez-vous?";
						$end=false;
						$message="";
						if ($Commande==null and $Valeur<>null) $Commande="puissance"; // Valeur sans commande 
						foreach($device->device as $i => $Data)  
						{  
							$pos=strpos($Zone,$device->device[$i]->zone); // Vérification si contient le texte de la zone
							foreach($device->device[$i]->nom as $j => $Dnom)  // Gestion des synonymes
							{ 
								$Nom=$device->device[$i]->nom[$j];
								$pos1=strpos($Liaison,$device->device[$i]->nom[$j]); // Vérification si contient le texte de l'objet								
								if ((($Nom==$Quoi)or($pos1!==false) )and ($pos!==false)){
									$Vmessage=$Quoi." ".$Zone." ".$device->device[$i]->type." ".$device->device[$i]->host." ".$Commande; // Pour debug
									$ip = gethostbyname($device->device[$i]->host);
									$type=$device->device[$i]->type;
									$end=false;
									$message="";
									foreach($cdedevice as $key => $arrays){
										if ($key==$type){
											foreach($arrays as $array){
												foreach($array as $key => $value){
													$pos=strpos($Commande,$key);  // Vérification si contient le texte de la commande
													// Mise à Echelle commande apres mise à l'échelle dans fichier JSON
													if ($key=="PCoeff") $Coeff=(double)$value;
													if (($key=="PmaxIn")and ($Valeur<>null)) {$PmaxIn=(int)$value;
														if ($Valeur>$PmaxIn) $Valeur=$PmaxIn;
													$Valeur=(intval($Valeur*$Coeff));}
													
													if (($pos!==false)or ($key==$Couleur)or($key==$Liaison)){
														$Vmessage='commande validée pour '.$Liaison." ".$Quoi." ".$Zone." ".$Valeur;
														$url ="http://" .$ip.$value.$Valeur;
														$contents = file_get_contents($url);
													}
												}
											}
										}				
									}
								}
							}
						}  
					}
					
					
					$ReturnValue = [
					"response" => [
					"shouldEndSession" => $end,
					"outputSpeech" => [
					"type" => 'SSML',
					"ssml" => '<speak>'.$Vmessage.'<break time="2s"/>'.$message.'</speak>',
					]
					]
					];
					}else {
					// pas de slotvalue
					$ReturnValue = [
				"response" => [
				"shouldEndSession" => false,
				"outputSpeech" => [
				"type" => 'SSML',
				"ssml" => "<speak>Je n'ai pas compris votre demande</speak>",
				]
				]
				];
				
				}
				}
				}else{
				
				
				ThrowRequestError();
				}
				// Réponse 
				Reponse($ReturnValue);				
				}else {
				ThrowRequestError(400, "Forbidden, unkown Application ID!");
				}
				
				function Reponse($ReturnValue){
				// Setup a JSON response header
				header('Content-Type: application/json;charset=UTF-8');
				header("Content-length: " . strlen($ReturnValue));
				// Return the output
				echo json_encode($ReturnValue);
				
				}
				function ThrowRequestError($code = 400, $msg = 'Bad Request'){
				GLOBAL $SETUP;
				http_response_code(400);
				echo "Error " . $code . "\n" . $msg;
				exit();
				}
				?>																																																