<?php

namespace proyectotije\CarBundle\Service;

use Symfony\Component\HttpFoundation\Session\Session;


class AmadeusCarService {

    private $sitio;
    private $moneda;
    private $activeContext;
    private $isStateless;
    private $webservice;

    const PATH_WSDL = '@CarBundle/Resources/doc/wsdl-prod/1ASIWLCAZTE_PRD_20130910_144429.wsdl';
    const BASE_URL = 'www.tije.travel';

    public function __construct($entityManager, $container, $session) {
        $this->em = $entityManager;
        $this->container = $container;
        $this->session = $session;
        $this->webservice = $this->container->get('kernel')->locateResource($this::PATH_WSDL);
//        $this->sitio = 1; //argentina
        $this->activeContext = false;
        $this->isStateless = true; //la session queda libre
    }

    public function setSitio($sitio) {
        $this->sitio = $sitio;

        return $this;
    }

    public function setMoneda($moneda) {
        $this->moneda = $moneda;
        return $this;
    }

    /**
     *  Verifica si existe sessión disponible
     *  @return Objeto session o null
     */
    public function querySessionAvailable() {

        $response = null;
        $sessions = $this->em->getRepository('BackendBundle:CarSession')->findBy(array('sitios' => $this->sitio), array('lastquerydate' => 'DESC'));

        foreach ($sessions as $session) {
            if ($session->getQueryInProgress() == false) {
                if ($session->getActiveContext() == false) {
                    $response = $session;
                    continue;
                }
            }
        }
        return $response;
    }

    /**
     * Crea y registra una nueva session. Maximo de 5 intentos
     * Retorna
     * @return true
     */
    public function newSessionProcess($activeContext = false) {

        try {

            $objeto = $this->constructorAuthenticate();
            $count = 1;
            $bandera = false;

            do {

                $data = $this->callMethod('Security_Authenticate', $objeto);

                //verifica si se creo la session
                if ($data->processStatus->statusCode == 'P') {

                    //Guarda la session en la tabla car_session
                    $sitio = $this->em->getRepository('BackendBundle:Sitios')->find($this->sitio);

                    $session = new \Backend\BackendBundle\Entity\CarSession();
                    $session->setSessionNumber($data->Session->SessionId);
                    $session->setSequenceNumber(1);
                    $session->setSecurityToken($data->Session->SecurityToken);
                    $session->setLastQueryDate(new \DateTime());
                    $session->setQueryInProgress(false);
                    $session->setActiveContext($activeContext);
                    $session->setSitios($sitio);

                    $this->em->persist($session);
                    $this->em->flush();
                    $bandera = true;
                } else {
                    $count++;
                }
            } while (($count < 5) && ($bandera == false));

            if ($bandera) {
                return $session;
            } else {
                throw new \Exception('No se puede crear la sessión');
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }

    public function queryMethod($service, $query, $isStateless = true) {

        //verifica el tipo de session
        if (!$isStateless) {
            //Reserva - crea una nueva sessión y NO la dejamos disponible
            $data = $this->newSessionProcess(true);

            $session = $this->querySessionAvailable();
        } else {
            //verifica si existe una session disponible
            $session = $this->querySessionAvailable();

            if ($session == null) {
                //No existe session disponible - se crea una nueva session
                $data = $this->newSessionProcess(false);

                $session = $this->querySessionAvailable();
            }
        }

        $dataResponse = $this->protocolConection($service, $query, $session, $isStateless);

        return $dataResponse;
    }

    /**
     * Retorna
     * @return Objeto
     */
    public function protocolConection($service, $request, $session, $isStateless) {

        //Informo que voy a ocupar la session
        $session->setSequenceNumber(($session->getSequenceNumber()) + 1);
        $session->setQueryInProgress(true); //voy a ocupar la session
        $session->setLastQueryDate(new \DateTime('now'));

        $this->em->persist($session);
        $this->em->flush();

        $sessionNumber = (String) ($session->getSessionNumber());
        $sequenceNumber = (String) ($session->getSequenceNumber());
        $securityToken = (String) ($session->getSecurityToken());

        //Ocupo la session
        $dataResponse = $this->callMethod($service, $request, $sessionNumber, $sequenceNumber, $securityToken);

        //libero la session
        if ($isStateless == true) {
            $session->setQueryInProgress(false);
        }

        $this->em->persist($session);
        $this->em->flush();

        return $dataResponse;
    }

    /**
     * Retorna: OBJETO con la respuesta del metodo.
     */
    public function callMethod($method, $xmlRequest, $sessionNumber = null, $sequenceNumber = null, $securityToken = null) {

        $soapStruct = array();
        $soapStruct['SessionId'] = $sessionNumber;
        $soapStruct['sequenceNumber'] = $sequenceNumber;
        $soapStruct['SecurityToken'] = $securityToken;

        $options = array();
        $options['soap_version'] = SOAP_1_1;
        $options['exceptions'] = 1;
        $options['trace'] = 1;
        $options['encoding'] = 'ISO-8859-1';
        $options['features'] = SOAP_SINGLE_ELEMENT_ARRAYS;
        $options['cache_wsdl'] = WSDL_CACHE_NONE;

        // crear valores de autenticación de cabecera
        $soapVar = new \SoapVar($soapStruct, SOAP_ENC_OBJECT, 'Session');

        // generar cabecera
        $soapHeader = new \SoapHeader("http://xml.amadeus.com/", "Session", $soapVar);

        $client = new \SoapClient($this->webservice, $options);

        $client->__setSoapHeaders(array($soapHeader));

        $response = $client->$method($xmlRequest);


        try {
            /*
              //imprimo la query */
            $xmlR = $client->__getLastRequest();
            echo '<h1>Request ' . $method . '</h1>';
            echo '<pre>';
            print_r($xmlR);
            echo '</pre><br /><hr />';
//imprimo la repuesta*/
            $xmlResponse = $client->__getLastResponse();
            echo '<h1>Response ' . $method . '</h1>';
            echo '<p>';
            print_r($xmlResponse);
            echo '</p><br /><hr />';
          //  exit();
            $xmlResponseValid = str_replace('soap:Envelope', 'soapEnvelope', str_replace('soap:Header', 'soapHeader', str_replace('soap:Body', 'soapBody', $xmlResponse)));
            //$xmlResponseValid = str_replace('awss:', '', str_replace('SOAP-ENV:Envelope', 'soapEnvelope', str_replace('SOAP-ENV:Header', 'soapHeader', str_replace('SOAP-ENV:Body', 'soapBody', str_replace('SOAP-ENV:Fault', 'soapFault', $xmlResponse)))));

            $dataSession = simplexml_load_string($xmlResponseValid);

            foreach ($dataSession->soapHeader->Session as $key => $value) {
                $response->$key = $value;
            }
            return $response;
        } catch (\SoapFault $e) {
            echo $e->getMessage();
        }
    }

    public function constructorAuthenticate() {

        $Security_Authenticate = new \stdClass();
        $Security_Authenticate->userIdentifier = new \stdClass();
        $Security_Authenticate->userIdentifier->originIdentification = new \stdClass();
        $Security_Authenticate->userIdentifier->originIdentification->sourceOffice = "PTY1S213L";
        $Security_Authenticate->userIdentifier->originatorTypeCode = "U";
        $Security_Authenticate->userIdentifier->originator = "WSZTELCA";

        $Security_Authenticate->dutyCode = new \stdClass();
        $Security_Authenticate->dutyCode->dutyCodeDetails = new \stdClass();
        $Security_Authenticate->dutyCode->dutyCodeDetails->referenceQualifier = 'DUT';
        $Security_Authenticate->dutyCode->dutyCodeDetails->referenceIdentifier = 'SU';

        $Security_Authenticate->systemDetails = new \stdClass();
        $Security_Authenticate->systemDetails->organizationDetails = new \stdClass();
        $Security_Authenticate->systemDetails->organizationDetails->organizationId = 'LATAM';

        $Security_Authenticate->passwordInfo = new \stdClass();
        $Security_Authenticate->passwordInfo->dataLength = '8';
        $Security_Authenticate->passwordInfo->dataType = 'E';
        $Security_Authenticate->passwordInfo->binaryData = 'Nk83NVdoSVQ=';

        return $Security_Authenticate;
    }

    /*     * ********************************************************************************************************************* */

    //Constructor del Objeto para el Car_Availability Inicial
    public function constructorObjeto($getData) {  //availabilityAction
        
        
        $iataInicio     = $getData['locationName'];
        $iataRegreso    = $getData['locationName2'];
        $fechaInicio    = explode('-', $getData['fechaInicio']);
        $anioInicio     = $fechaInicio[0];
        $mesInicio      = $fechaInicio[1];
        $diaInicio      = $fechaInicio[2];
        $horaInicio     = substr($getData['horaInicio'], 0, 2);
        $minInicio      = substr($getData['horaInicio'], 2, 2);
        $fechaRegreso   = explode('-', $getData['fechaRegreso']);
        $anioRegreso    = $fechaRegreso[0];
        $mesRegreso     = $fechaRegreso[1];
        $diaRegreso     = $fechaRegreso[2];
        $horaRegreso    = substr($getData['horaRegreso'], 0, 2);
        $minRegreso     = substr($getData['horaRegreso'], 2, 2);
       ////codigo Compañia si se selecciono una en particular 
        $companyCode= $getData['companyCode'];
        

        
        $Car_Availability = new \stdClass();

        $Car_Availability->carProviderIndicator = new \stdClass();
        $Car_Availability->carProviderIndicator->statusDetails = new \stdClass();
        $Car_Availability->carProviderIndicator->statusDetails->indicator = 'Y';


        $Car_Availability->multimediaIndicator = new \stdClass();
        $Car_Availability->multimediaIndicator->statusDetails = new \stdClass();
        $Car_Availability->multimediaIndicator->statusDetails->indicator = 'MY';


        $multiMediaContent = new \stdClass();
        $multiMediaContent->picturesType = new \stdClass();
        $multiMediaContent->picturesType->actionRequestCode = 'CPY';
        $multiMediaContent->pictureSize = new \stdClass();
        $multiMediaContent->pictureSize->selectionDetails = new \stdClass();
        $multiMediaContent->pictureSize->selectionDetails->option = '1';

        $multiMediaContent2 = new \stdClass();
        $multiMediaContent2->picturesType = new \stdClass();
        $multiMediaContent2->picturesType->actionRequestCode = 'VEH';
        $multiMediaContent2->pictureSize = new \stdClass();
        $multiMediaContent2->pictureSize->selectionDetails = new \stdClass();
        $multiMediaContent2->pictureSize->selectionDetails->option = '5';

        $Car_Availability->multiMediaContent[] = $multiMediaContent;
        $Car_Availability->multiMediaContent[] = $multiMediaContent2;


        $Car_Availability->pickupDropoffInfo = new \stdClass();
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes = new \stdClass();
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->beginDateTime = new \stdClass();
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->beginDateTime->year    = $anioInicio;
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->beginDateTime->month   = $mesInicio;
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->beginDateTime->day     = $diaInicio;
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->beginDateTime->hour    = $horaInicio;
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->beginDateTime->minutes = $minInicio;
        ;


        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->endDateTime = new \stdClass();
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->endDateTime->year    = $anioRegreso;
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->endDateTime->month   = $mesRegreso;
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->endDateTime->day     = $diaRegreso;
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->endDateTime->hour    = $horaRegreso;
        $Car_Availability->pickupDropoffInfo->pickupDropoffTimes->endDateTime->minutes = $minRegreso;


        if($iataInicio != $iataRegreso)
        {
        
                $pickupDropoffInfo = new \stdClass();
                $pickupDropoffInfo->locationType = new \stdClass();
                $pickupDropoffInfo->locationType->locationType = "PUP";
                $pickupDropoffInfo->iataAirportLocations = new \stdClass();
                $pickupDropoffInfo->iataAirportLocations->locationDescription = new \stdClass();
                $pickupDropoffInfo->iataAirportLocations->locationDescription->code = "1A";
                $pickupDropoffInfo->iataAirportLocations->locationDescription->name = $iataInicio;
                $pickupDropoffInfo2 = new \stdClass();
                $pickupDropoffInfo2->locationType = new \stdClass();
                $pickupDropoffInfo2->locationType->locationType = "DOP";
                $pickupDropoffInfo2->iataAirportLocations = new \stdClass();
                $pickupDropoffInfo2->iataAirportLocations->locationDescription = new \stdClass();
                $pickupDropoffInfo2->iataAirportLocations->locationDescription->code = "1A";
                $pickupDropoffInfo2->iataAirportLocations->locationDescription->name = $iataRegreso;

                $Car_Availability->pickupDropoffInfo->pickupDropoffInfo[] = $pickupDropoffInfo;
                $Car_Availability->pickupDropoffInfo->pickupDropoffInfo[] = $pickupDropoffInfo2;
        }
        else{
                $pickupDropoffInfo = new \stdClass();
                $pickupDropoffInfo->locationType = new \stdClass();
                $pickupDropoffInfo->locationType->locationType = "PUP";
                $pickupDropoffInfo->iataAirportLocations = new \stdClass();
                $pickupDropoffInfo->iataAirportLocations->locationDescription = new \stdClass();
                $pickupDropoffInfo->iataAirportLocations->locationDescription->code = "1A";
                $pickupDropoffInfo->iataAirportLocations->locationDescription->name = $iataInicio;
                $Car_Availability->pickupDropoffInfo->pickupDropoffInfo[] = $pickupDropoffInfo;
             }

//            ldd($companyCode);
        if($companyCode != 'NA')
        {
           

            $companyCode    = explode('-', $getData['companyCode']);
            foreach ($companyCode as $companyCode1 )
                {
                    $id=2;

//        $rows = $this->em->getRepository('BackendBundle:CocheTarifas')->findAll();
//        ldd($rows[0]->getCocheRentadora()->getNombre());
                    
                    $conn = $this->container->get('database_connection');
                    $sql = "SELECT t.codigo as codigo_tarifas, r.codigo as codigo_rentadora, ctt.tipo
                            FROM coche_tarifas t ,coche_rentadora r, coche_tipo_tarifa ctt
                            WHERE  t.coche_rentadora_id =r.id and t.coche_tipo_tarifa_id = ctt.id and r.codigo= '$companyCode1'";
                    $rows = $conn->fetchAll($sql);
                    foreach ($rows as $rows1)
                        {
                            $codigoTarifa       = $rows1['codigo_tarifas'];     
                            $codigoRentadora    = $rows1['codigo_rentadora'];     
                            $codigoTipo               = $rows1['tipo'];     
                            $providerSpecificOptions = new \stdClass();
                            $providerSpecificOptions->companyDetails = new \stdClass();
                            $providerSpecificOptions->companyDetails->companyCode = $codigoRentadora;
                            $providerSpecificOptions->loyaltyNumbersList = new \stdClass();
                            $providerSpecificOptions->loyaltyNumbersList->discountNumbers = new \stdClass();
                            $providerSpecificOptions->loyaltyNumbersList->discountNumbers->customerReferenceInfo = new \stdClass();
                            $providerSpecificOptions->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceQualifier = $codigoTipo;
                            $providerSpecificOptions->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceNumber = $codigoTarifa;
                            $Car_Availability->providerSpecificOptions[] = $providerSpecificOptions;
                        }
                
                }
              
        }
        else
        {
                    //ldd("pasa else");
                    $conn = $this->container->get('database_connection');
                    $sql = "SELECT t.codigo as codigo_tarifas, r.codigo as codigo_rentadora, ctt.tipo
                            FROM coche_tarifas t ,coche_rentadora r, coche_tipo_tarifa ctt
                            WHERE  t.coche_rentadora_id =r.id and t.coche_tipo_tarifa_id = ctt.id and 1=1";
                    $rows = $conn->fetchAll($sql);
                    foreach ($rows as $rows1)
                        {
                            $codigoTarifa       = $rows1['codigo_tarifas'];     
                            $codigoRentadora    = $rows1['codigo_rentadora'];     
                            $codigoTipo               = $rows1['tipo'];     
                            $providerSpecificOptions2 = new \stdClass();
                            $providerSpecificOptions2->companyDetails = new \stdClass();
                            $providerSpecificOptions2->companyDetails->companyCode = $codigoRentadora;
                            $providerSpecificOptions2->loyaltyNumbersList = new \stdClass();
                            $providerSpecificOptions2->loyaltyNumbersList->discountNumbers = new \stdClass();
                            $providerSpecificOptions2->loyaltyNumbersList->discountNumbers->customerReferenceInfo = new \stdClass();
                            $providerSpecificOptions2->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceQualifier = $codigoTipo;
                            $providerSpecificOptions2->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceNumber = $codigoTarifa;
                            $Car_Availability->providerSpecificOptions[] = $providerSpecificOptions2;
                         }    
            
         }
        
             
/*
        $providerSpecificOptions = new \stdClass();
        $providerSpecificOptions->companyDetails = new \stdClass();
        $providerSpecificOptions->companyDetails->companyCode = 'ZR';
        $providerSpecificOptions->loyaltyNumbersList = new \stdClass();
        $providerSpecificOptions->loyaltyNumbersList->discountNumbers = new \stdClass();
        $providerSpecificOptions->loyaltyNumbersList->discountNumbers->customerReferenceInfo = new \stdClass();
        $providerSpecificOptions->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceQualifier = 'RC';
        $providerSpecificOptions->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceNumber = 'L8LGA';
        $providerSpecificOptions->bookingSource = new \stdClass();
        $providerSpecificOptions->bookingSource->originatorDetails = new \stdClass();
        $providerSpecificOptions->bookingSource->originatorDetails->originatorId = '00227207';

        $providerSpecificOptions2 = new \stdClass();
        $providerSpecificOptions2->companyDetails = new \stdClass();
        $providerSpecificOptions2->companyDetails->companyCode = 'ZR';
        $providerSpecificOptions2->loyaltyNumbersList = new \stdClass();
        $providerSpecificOptions2->loyaltyNumbersList->discountNumbers = new \stdClass();
        $providerSpecificOptions2->loyaltyNumbersList->discountNumbers->customerReferenceInfo = new \stdClass();
        $providerSpecificOptions2->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceQualifier = 'RC';
        $providerSpecificOptions2->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceNumber = 'USPAL';
        $providerSpecificOptions2->bookingSource = new \stdClass();
        $providerSpecificOptions2->bookingSource->originatorDetails = new \stdClass();
        $providerSpecificOptions2->bookingSource->originatorDetails->originatorId = '00227207';

        $customerReferenceInfo8 = new \stdClass();
        $customerReferenceInfo8->referenceQualifier = 'CD';
        $customerReferenceInfo8->referenceNumber = '501037';
        $customerReferenceInfo9 = new \stdClass();
        $customerReferenceInfo9->referenceQualifier = 'CD';
        $customerReferenceInfo9->referenceNumber = '217692';
        $customerReferenceInfo10 = new \stdClass();
        $customerReferenceInfo10->referenceQualifier = 'PC';
        $customerReferenceInfo10->referenceNumber = '168593';

        $providerSpecificOptions3 = new \stdClass();
        $providerSpecificOptions3->companyDetails = new \stdClass();
        $providerSpecificOptions3->companyDetails->companyCode = 'ZE';
        $providerSpecificOptions3->loyaltyNumbersList = new \stdClass();
        $providerSpecificOptions3->loyaltyNumbersList->discountNumbers = new \stdClass();
        $providerSpecificOptions3->loyaltyNumbersList->discountNumbers->customerReferenceInfo[] = $customerReferenceInfo8;
        $providerSpecificOptions3->loyaltyNumbersList->discountNumbers->customerReferenceInfo[] = $customerReferenceInfo9;
        $providerSpecificOptions3->loyaltyNumbersList->discountNumbers->customerReferenceInfo[] = $customerReferenceInfo10;
        $providerSpecificOptions3->bookingSource = new \stdClass();
        $providerSpecificOptions3->bookingSource->originatorDetails = new \stdClass();
        $providerSpecificOptions3->bookingSource->originatorDetails->originatorId = '00278110';



        $customerReferenceInfo6 = new \stdClass();
        $customerReferenceInfo6->referenceQualifier = 'RC';
        $customerReferenceInfo6->referenceNumber = 'H8';
        $customerReferenceInfo7 = new \stdClass();
        $customerReferenceInfo7->referenceQualifier = 'CD';
        $customerReferenceInfo7->referenceNumber = 'G154800';


        $providerSpecificOptions4 = new \stdClass();
        $providerSpecificOptions4->companyDetails = new \stdClass();
        $providerSpecificOptions4->companyDetails->companyCode = 'ZI';
        $providerSpecificOptions4->loyaltyNumbersList = new \stdClass();
        $providerSpecificOptions4->loyaltyNumbersList->discountNumbers = new \stdClass();
        $providerSpecificOptions4->loyaltyNumbersList->discountNumbers->customerReferenceInfo[] = $customerReferenceInfo6;
        $providerSpecificOptions4->loyaltyNumbersList->discountNumbers->customerReferenceInfo[] = $customerReferenceInfo7;
        $providerSpecificOptions4->bookingSource = new \stdClass();
        $providerSpecificOptions4->bookingSource->originatorDetails = new \stdClass();
        $providerSpecificOptions4->bookingSource->originatorDetails->originatorId = '0086785C';


        $providerSpecificOptions5 = new \stdClass();
        $providerSpecificOptions5->companyDetails = new \stdClass();
        $providerSpecificOptions5->companyDetails->companyCode = 'AL';
        $providerSpecificOptions5->loyaltyNumbersList = new \stdClass();
        $providerSpecificOptions5->loyaltyNumbersList->discountNumbers = new \stdClass();
        $providerSpecificOptions5->loyaltyNumbersList->discountNumbers->customerReferenceInfo = new \stdClass();
        $providerSpecificOptions5->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceQualifier = 'CD';
        $providerSpecificOptions5->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceNumber = 'R0028MF';
        $providerSpecificOptions5->bookingSource = new \stdClass();
        $providerSpecificOptions5->bookingSource->originatorDetails = new \stdClass();
        $providerSpecificOptions5->bookingSource->originatorDetails->originatorId = 'AL021726';

        $customerReferenceInfo4 = new \stdClass();
        $customerReferenceInfo4->referenceQualifier = 'RC';
        $customerReferenceInfo4->referenceNumber = 'LR';
        $customerReferenceInfo5 = new \stdClass();
        $customerReferenceInfo5->referenceQualifier = 'CD';
        $customerReferenceInfo5->referenceNumber = 'H028200';

        $providerSpecificOptions6 = new \stdClass();
        $providerSpecificOptions6->companyDetails = new \stdClass();
        $providerSpecificOptions6->companyDetails->companyCode = 'ZD';
        $providerSpecificOptions6->loyaltyNumbersList = new \stdClass();
        $providerSpecificOptions6->loyaltyNumbersList->discountNumbers = new \stdClass();
        $providerSpecificOptions6->loyaltyNumbersList->discountNumbers->customerReferenceInfo[] = $customerReferenceInfo4;
        $providerSpecificOptions6->loyaltyNumbersList->discountNumbers->customerReferenceInfo[] = $customerReferenceInfo5;
        $providerSpecificOptions6->bookingSource = new \stdClass();
        $providerSpecificOptions6->bookingSource->originatorDetails = new \stdClass();
        $providerSpecificOptions6->bookingSource->originatorDetails->originatorId = '0086785C';



        $customerReferenceInfo2 = new \stdClass();
        $customerReferenceInfo2->referenceQualifier = 'RC';
        $customerReferenceInfo2->referenceNumber = 'L8GA';
        $customerReferenceInfo3 = new \stdClass();
        $customerReferenceInfo3->referenceQualifier = 'IT';
        $customerReferenceInfo3->referenceNumber = '1001899';

        $providerSpecificOptions7 = new \stdClass();
        $providerSpecificOptions7->companyDetails = new \stdClass();
        $providerSpecificOptions7->companyDetails->companyCode = 'ZT';
        $providerSpecificOptions7->loyaltyNumbersList = new \stdClass();
        $providerSpecificOptions7->loyaltyNumbersList->discountNumbers = new \stdClass();
        $providerSpecificOptions7->loyaltyNumbersList->discountNumbers->customerReferenceInfo[] = $customerReferenceInfo2;
        $providerSpecificOptions7->loyaltyNumbersList->discountNumbers->customerReferenceInfo[] = $customerReferenceInfo3;
        $providerSpecificOptions7->bookingSource = new \stdClass();
        $providerSpecificOptions7->bookingSource->originatorDetails = new \stdClass();
        $providerSpecificOptions7->bookingSource->originatorDetails->originatorId = '00227207';

        $providerSpecificOptions8 = new \stdClass();
        $providerSpecificOptions8->companyDetails = new \stdClass();
        $providerSpecificOptions8->companyDetails->companyCode = 'ZT';
        $providerSpecificOptions8->loyaltyNumbersList = new \stdClass();
        $providerSpecificOptions8->loyaltyNumbersList->discountNumbers = new \stdClass();
        $providerSpecificOptions8->loyaltyNumbersList->discountNumbers->customerReferenceInfo = new \stdClass();
        $providerSpecificOptions8->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceQualifier = 'RC';
        $providerSpecificOptions8->loyaltyNumbersList->discountNumbers->customerReferenceInfo->referenceNumber = 'TPPL';
        $providerSpecificOptions8->bookingSource = new \stdClass();
        $providerSpecificOptions8->bookingSource->originatorDetails = new \stdClass();
        $providerSpecificOptions8->bookingSource->originatorDetails->originatorId = '00227207';


        $customerReferenceInfo1 = new \stdClass();
        $customerReferenceInfo1->referenceQualifier = 'CD';
        $customerReferenceInfo1->referenceNumber = 'NS0343CT';


        $providerSpecificOptions9 = new \stdClass();
        $providerSpecificOptions9->companyDetails = new \stdClass();
        $providerSpecificOptions9->companyDetails->companyCode = 'ZL';
        $providerSpecificOptions9->loyaltyNumbersList = new \stdClass();
        $providerSpecificOptions9->loyaltyNumbersList->discountNumbers = new \stdClass();
        $providerSpecificOptions9->loyaltyNumbersList->discountNumbers->customerReferenceInfo[] = $customerReferenceInfo1;

        $providerSpecificOptions9->bookingSource = new \stdClass();
        $providerSpecificOptions9->bookingSource->originatorDetails = new \stdClass();
        $providerSpecificOptions9->bookingSource->originatorDetails->originatorId = 'NC002635';

        $Car_Availability->providerSpecificOptions[] = $providerSpecificOptions;
        $Car_Availability->providerSpecificOptions[] = $providerSpecificOptions2;
        $Car_Availability->providerSpecificOptions[] = $providerSpecificOptions3;
        $Car_Availability->providerSpecificOptions[] = $providerSpecificOptions4;
        $Car_Availability->providerSpecificOptions[] = $providerSpecificOptions5;
        $Car_Availability->providerSpecificOptions[] = $providerSpecificOptions6;
        $Car_Availability->providerSpecificOptions[] = $providerSpecificOptions7;
        $Car_Availability->providerSpecificOptions[] = $providerSpecificOptions8;
        $Car_Availability->providerSpecificOptions[] = $providerSpecificOptions9;
*/
        $Car_Availability->rateClass = new \stdClass();
        $Car_Availability->rateClass->criteriaSetType = 'COR';

        $Car_Availability->computeMarkups = new \stdClass();
        $Car_Availability->computeMarkups->actionRequestCode = 'N';

        $Car_Availability->clientProfileOptions = new \stdClass();
        $Car_Availability->clientProfileOptions->statusInformation = new \stdClass();
        $Car_Availability->clientProfileOptions->statusInformation->indicator = 'BYP';
        $Car_Availability->clientProfileOptions->statusInformation->type = 'LNB';

        $Car_Availability->sortingRule = new \stdClass();
        $Car_Availability->sortingRule->actionRequestCode = 'CTG';

        return $Car_Availability;
    }

    //PRoceso de Reserva, PNR, CarSel, PNR

    public function contructorPNR($form, $idReserva) {



        $carReserva = $this->em->getRepository('BackendBundle:CarReservas')->findOneBy(array('id' => $idReserva));



        $PNR_AddMultiElements = new \stdClass();
        $PNR_AddMultiElements->reservationInfo = new \stdClass();
        $PNR_AddMultiElements->reservationInfo->reservation = new \stdClass();
        $PNR_AddMultiElements->reservationInfo->reservation->companyId = '1A';
        $PNR_AddMultiElements->pnrActions = new \stdClass();
        $PNR_AddMultiElements->pnrActions->optionCode = '0';
        $PNR_AddMultiElements->travellerInfo = new \stdClass();
        $PNR_AddMultiElements->travellerInfo->elementManagementPassenger = new \stdClass();
        $PNR_AddMultiElements->travellerInfo->elementManagementPassenger->reference = new \stdClass();
        $PNR_AddMultiElements->travellerInfo->elementManagementPassenger->reference->qualifier = 'PR';
        $PNR_AddMultiElements->travellerInfo->elementManagementPassenger->reference->number = '1';
        $PNR_AddMultiElements->travellerInfo->elementManagementPassenger->segmentName = 'NM';
        $PNR_AddMultiElements->travellerInfo->passengerData = new \stdClass();
        $PNR_AddMultiElements->travellerInfo->passengerData->travellerInformation = new \stdClass();
        $PNR_AddMultiElements->travellerInfo->passengerData->travellerInformation->traveller = new \stdClass();
        $PNR_AddMultiElements->travellerInfo->passengerData->travellerInformation->traveller->surname = $form['lastName'];
        $PNR_AddMultiElements->travellerInfo->passengerData->travellerInformation->traveller->quantity = '1';
        $PNR_AddMultiElements->travellerInfo->passengerData->travellerInformation->passenger = new \stdClass();
        $PNR_AddMultiElements->travellerInfo->passengerData->travellerInformation->passenger->firstName = $form['name'];
        $PNR_AddMultiElements->dataElementsMaster = new \stdClass();
        $PNR_AddMultiElements->dataElementsMaster->marker1 = '';

        $dataElementsIndiv = new \stdClass();
        $dataElementsIndiv->elementManagementData = new \stdClass();
        $dataElementsIndiv->elementManagementData->segmentName = 'AP';
        $dataElementsIndiv->freetextData = new \stdClass();
        $dataElementsIndiv->freetextData->freetextDetail = new \stdClass();
        $dataElementsIndiv->freetextData->freetextDetail->subjectQualifier = '3';
        $dataElementsIndiv->freetextData->freetextDetail->type = 'P02';
        $dataElementsIndiv->freetextData->longFreetext = $form['phone'];

        $dataElementsIndiv2 = new \stdClass();
        $dataElementsIndiv2->elementManagementData = new \stdClass();
        $dataElementsIndiv2->elementManagementData->reference = new \stdClass();
        $dataElementsIndiv2->elementManagementData->reference->qualifier = 'OT';
        $dataElementsIndiv2->elementManagementData->reference->number = '1';
        $dataElementsIndiv2->elementManagementData->segmentName = 'TK';
        $dataElementsIndiv2->ticketElement = new \stdClass();
        $dataElementsIndiv2->ticketElement->passengerType = 'PAX';
        $dataElementsIndiv2->ticketElement->ticket = new \stdClass();
        $dataElementsIndiv2->ticketElement->ticket->indicator = 'OK';

        $dataElementsIndiv3 = new \stdClass();
        $dataElementsIndiv3->elementManagementData = new \stdClass();
        $dataElementsIndiv3->elementManagementData->segmentName = 'AP';
        $dataElementsIndiv3->freetextData = new \stdClass();
        $dataElementsIndiv3->freetextData->freetextDetail = new \stdClass();
        $dataElementsIndiv3->freetextData->freetextDetail->subjectQualifier = '3';
        $dataElementsIndiv3->freetextData->freetextDetail->type = '6';
        $dataElementsIndiv3->freetextData->longFreetext = ' Tije - Travel';


        $dataElementsIndiv4 = new \stdClass();
        $dataElementsIndiv4->elementManagementData = new \stdClass();
        $dataElementsIndiv4->elementManagementData->segmentName = 'RF';
        $dataElementsIndiv4->freetextData = new \stdClass();
        $dataElementsIndiv4->freetextData->freetextDetail = new \stdClass();
        $dataElementsIndiv4->freetextData->freetextDetail->subjectQualifier = '3';
        $dataElementsIndiv4->freetextData->freetextDetail->type = 'P22';
        $dataElementsIndiv4->freetextData->longFreetext = 'Tije Travel';


        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv[] = $dataElementsIndiv;
        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv[] = $dataElementsIndiv2;
        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv[] = $dataElementsIndiv3;
        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv[] = $dataElementsIndiv4;


        $newSession = $this->newSessionProcess(true);

        $sessionNumber = (String) ($newSession->getSessionNumber());
        $sequenceNumber = (String) ($newSession->getSequenceNumber() + 1);
        $securityToken = (String) ($newSession->getSecurityToken());


        $responsePNR = $this->callMethod('PNR_AddMultiElements', $PNR_AddMultiElements, $sessionNumber, $sequenceNumber, $securityToken);

        $sessionNumber = (String) ($responsePNR->Session->SessionId);
        $sequenceNumber = (String) ($responsePNR->Session->SequenceNumber + 1);
        $securityToken = (String) ($responsePNR->Session->SecurityToken);

        if (isset($responsePNR->soapBody->PNR_Reply)) {
            if (isset($responsePNR->soapBody->PNR_Reply->travellerInfo->elementManagementPassenger->reference->qualifier))
                $pnrType = $responsePNR->soapBody->PNR_Reply->travellerInfo->elementManagementPassenger->reference->qualifier;
            else
                $pnrType = 'PT';

            if (isset($responsePNR->soapBody->PNR_Reply->travellerInfo->elementManagementPassenger->reference->number))
                $pnrValue = $responsePNR->soapBody->PNR_Reply->travellerInfo->elementManagementPassenger->reference->number;
            else
                $pnrValue = '2';
        }
        else {
            if (isset($responsePNR->travellerInfo->elementManagementPassenger->reference->qualifier))
                $pnrType = $responsePNR->travellerInfo->elementManagementPassenger->reference->qualifier;
            else
                $pnrType = 'PT';

            if (isset($responsePNR->travellerInfo->elementManagementPassenger->reference->number))
                $pnrValue = $responsePNR->travellerInfo->elementManagementPassenger->reference->number;
            else
                $pnrValue = '2';



            $carsell = new \stdClass();


            $carsell->pnrInfo = new \stdClass();
            $carsell->pnrInfo->paxTattooNbr = new \stdClass();
            $carsell->pnrInfo->paxTattooNbr->referenceDetails = new \stdClass();
            $carsell->pnrInfo->paxTattooNbr->referenceDetails->type = $pnrType;
            $carsell->pnrInfo->paxTattooNbr->referenceDetails->value = $pnrValue;
            $carsell->sellData = new \stdClass();
            $carsell->sellData->companyIdentification = new \stdClass();
            $carsell->sellData->companyIdentification->travelSector = 'CAR';
            $carsell->sellData->companyIdentification->companyCode = $form['codigo'];

            $carsell->sellData->locationInfo = new \stdClass();
            $carsell->sellData->locationInfo->locationType = '176';
            $carsell->sellData->locationInfo->locationDescription = new \stdClass();
            $carsell->sellData->locationInfo->locationDescription->code = $form['loccode'];
            $carsell->sellData->locationInfo->locationDescription->name = $form['locationName'];

            $carsell->sellData->pickupDropoffTimes = new \stdClass();
            $carsell->sellData->pickupDropoffTimes->beginDateTime = new \stdClass();
            $carsell->sellData->pickupDropoffTimes->beginDateTime->year = $form['anoi'];
            $carsell->sellData->pickupDropoffTimes->beginDateTime->month = $form['mesi'];
            $carsell->sellData->pickupDropoffTimes->beginDateTime->day = $form['diai'];
            $carsell->sellData->pickupDropoffTimes->beginDateTime->hour = $form['houri'];
            $carsell->sellData->pickupDropoffTimes->beginDateTime->minutes = $form['mini'];



            $carsell->sellData->pickupDropoffTimes->endDateTime = new \stdClass();
            $carsell->sellData->pickupDropoffTimes->endDateTime->year = $form['anof'];
            $carsell->sellData->pickupDropoffTimes->endDateTime->month = $form['mesf'];
            $carsell->sellData->pickupDropoffTimes->endDateTime->day = $form['diaf'];
            $carsell->sellData->pickupDropoffTimes->endDateTime->hour = $form['hourf'];
            $carsell->sellData->pickupDropoffTimes->endDateTime->minutes = $form['minf'];

            $carsell->sellData->vehicleInformation = new \stdClass();
            $carsell->sellData->vehicleInformation->vehTypeOptionQualifier = 'VT';
            $carsell->sellData->vehicleInformation->vehicleRentalNeedType = new \stdClass();
            $carsell->sellData->vehicleInformation->vehicleRentalNeedType->vehicleTypeOwner = 'ACR';
            $carsell->sellData->vehicleInformation->vehicleRentalNeedType->vehicleRentalPrefType = $form['codigoauto'];

            $carsell->sellData->rateCodeInfo = new \stdClass();
            $carsell->sellData->rateCodeInfo->fareCategories = new \stdClass();
            $carsell->sellData->rateCodeInfo->fareCategories->fareType = 'AUADF';

            $carsell->sellData->customerInfo = new \stdClass();
            $carsell->sellData->customerInfo->customerReferences = new \stdClass();
            $carsell->sellData->customerInfo->customerReferences->referenceQualifier = 'CD';
            $carsell->sellData->customerInfo->customerReferences->referenceNumber = $form['rnum'];

            $carsell->sellData->rateInfo = new \stdClass();
            $carsell->sellData->rateInfo->tariffInfo = new \stdClass();
            $carsell->sellData->rateInfo->tariffInfo->amount = $form['monto'];
            $carsell->sellData->rateInfo->tariffInfo->currency = $form['moneda'];
            $carsell->sellData->rateInfo->tariffInfo->rateType = '204';
            //   $carsell->sellData->rateInfo->tariffInfo->ratePlanIndicator = 'DY';

            $carsell->sellData->rateInformation = new \stdClass();
            $carsell->sellData->rateInformation->category = '024';

            $responseCAR = $this->callMethod('Car_Sell', $carsell, $sessionNumber, $sequenceNumber, $securityToken);

            $sessionNumber = (String) ($responseCAR->Session->SessionId);
            $sequenceNumber = (String) ($responseCAR->Session->SequenceNumber + 1);
            $securityToken = (String) ($responseCAR->Session->SecurityToken);

            $PNR_AddMultiElements2 = new \stdClass();
            $PNR_AddMultiElements2->pnrActions = new \stdClass();
            $PNR_AddMultiElements2->pnrActions->optionCode = '11';
            $PNR_AddMultiElements2->dataElementsMaster = new \stdClass();
            $PNR_AddMultiElements2->dataElementsMaster->marker1 = '';
            $PNR_AddMultiElements2->dataElementsMaster->dataElementsIndiv = new \stdClass();
            $PNR_AddMultiElements2->dataElementsMaster->dataElementsIndiv->elementManagementData = new \stdClass();
            $PNR_AddMultiElements2->dataElementsMaster->dataElementsIndiv->elementManagementData->segmentName = 'RF';
            $PNR_AddMultiElements2->dataElementsMaster->dataElementsIndiv->freetextData = new \stdClass();
            $PNR_AddMultiElements2->dataElementsMaster->dataElementsIndiv->freetextData->freetextDetail = new \stdClass();
            $PNR_AddMultiElements2->dataElementsMaster->dataElementsIndiv->freetextData->freetextDetail->subjectQualifier = '3';
            $PNR_AddMultiElements2->dataElementsMaster->dataElementsIndiv->freetextData->freetextDetail->type = 'P22';
            $PNR_AddMultiElements2->dataElementsMaster->dataElementsIndiv->freetextData->longFreetext = 'tije';

            $response = $this->callMethod('PNR_AddMultiElements', $PNR_AddMultiElements2, $sessionNumber, $sequenceNumber, $securityToken);


            if (isset($response->originDestinationDetails)) {
                $codigoAmadeus = (String) $response->originDestinationDetails[0]->itineraryInfo[0]->typicalCarData->cancelOrConfirmNbr[0]->reservation->controlNumber;
                $codigoAmadeus = str_replace('COUNT', '', $codigoAmadeus);
                //obtenemos el ControlNumber
                $controlNumber = (String) $response->pnrHeader[0]->reservationInfo->reservation->controlNumber;
                $estado = 'confirmada';
            } else {
                $codigoAmadeus = 'KO';
                $controlNumber = isset($response->pnrHeader[0]->reservationInfo->reservation->controlNumber) ? (String) $response->pnrHeader[0]->reservationInfo->reservation->controlNumber : '';
                $estado = 'error';
            }


            $requestPNR = json_encode($PNR_AddMultiElements);
            $requestCar = json_encode($carsell);
            $requestPNR2 = json_encode($PNR_AddMultiElements2);

            $responsePNR = json_encode($responsePNR);
            $responseCAR = json_encode($responseCAR);
            $responsePNR2 = json_encode($response);



            $carReserva->setLog('{"requestPNR":' . $requestPNR . ',"responsePNR":' . $responsePNR . ',"requestCar":' . $requestCar . ',"responseCarSell":' . $responseCAR . ',"requestPNR2":' . $requestPNR2 . ',"responsePNR2":' . $responsePNR2 . '}');
            $carReserva->setCodigoAmadeus($codigoAmadeus);
            $carReserva->setControlNumber($controlNumber);

            $carReserva->setEstado($estado);
            $this->em->flush();

            return $codigoAmadeus;
        }
    }

    //Proceso de Cancelación
    public function pnrcancell($controlNumber) {



        $pnrcancell = new \stdClass();
        $pnrcancell->reservationInfo = new \stdClass();
        $pnrcancell->reservationInfo->reservation = new \stdClass();
        $pnrcancell->reservationInfo->reservation->controlNumber = $controlNumber;
        $pnrcancell->pnrActions = new \stdClass();
        $pnrcancell->pnrActions->optionCode = '0';
        $pnrcancell->cancelElements = new \stdClass();
        $pnrcancell->cancelElements->entryType = 'E';
        $pnrcancell->cancelElements->element = new \stdClass();
        $pnrcancell->cancelElements->element->identifier = 'ST';
        $pnrcancell->cancelElements->element->number = '1';

        $PNR_Cancel_Response = $this->queryMethod('PNR_Cancel', $pnrcancell);

        $PNR_AddMultiElements = new \stdClass();
        $PNR_AddMultiElements->pnrActions = new \stdClass();
        $PNR_AddMultiElements->pnrActions->optionCode = '11';
        $PNR_AddMultiElements->dataElementsMaster = new \stdClass();
        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv = new \stdClass();
        $PNR_AddMultiElements->dataElementsMaster->marker1 = "";
        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv->elementManagementData = new \stdClass();
        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv->elementManagementData->segmentName = "RF";
        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv->freetextData = new \stdClass();
        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv->freetextData->freetextDetail = new \stdClass();
        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv->freetextData->freetextDetail->subjectQualifier = "3";
        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv->freetextData->freetextDetail->type = "P22";
        $PNR_AddMultiElements->dataElementsMaster->dataElementsIndiv->freetextData->longFreetext = "Tije Travel";

        $PNR_AddMultiElements_Response = $this->queryMethod('PNR_AddMultiElements', $PNR_AddMultiElements);

        $requestPNR = json_encode($pnrcancell);
        $requestPNR2 = json_encode($PNR_AddMultiElements);

        $responsePNR = json_encode($PNR_Cancel_Response);
        $responsePNR2 = json_encode($PNR_AddMultiElements_Response);

        $response = '{"requestPNR":' . $requestPNR . ',"responsePNR":' . $responsePNR . ',"requestPNR2":' . $requestPNR2 . ',"responsePNR2:' . $responsePNR2 . '}';

        return $response;
    }

    public function listaciudades($controlNumber) {
        
    }

}
