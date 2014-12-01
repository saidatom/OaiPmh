<?php
/**
 * Created by PhpStorm.
 * User: jsmit
 * Date: 28-11-14
 * Time: 15:42
 */

namespace Picturae\OAI;


use Picturae\OAI\Exception\BadArgumentException;
use Picturae\OAI\Exception\BadVerbException;
use Picturae\OAI\Exception\MultipleExceptions;
use Picturae\OAI\Exception\NoMetadataFormatsException;
use Picturae\OAI\Exception\NoRecordsMatchException;
use Picturae\OAI\Exception\NoSetHierarchyException;
use Picturae\OAI\Interfaces\Record;
use Picturae\OAI\Interfaces\RecordList;
use Picturae\OAI\Interfaces\Repository;

class Provider
{
    private static $verbs = [
        "Identify" => array(),
        "ListMetadataFormats" => array('identifier'),
        "ListSets" => array('resumptionToken'),
        "GetRecord" => array('identifier', 'metadataPrefix'),
        "ListIdentifiers" => array('from', 'until', 'metadataPrefix', 'set', 'resumptionToken'),
        "ListRecords" => array('from', 'until', 'metadataPrefix', 'set', 'resumptionToken')
    ];
    private $verb;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var Repository
     */
    private $repository;

    /**
     * @var array
     */
    private $request = [];

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return array
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @param array $request
     */
    public function setRequest(array $request)
    {
        $this->request = $request;
    }

    private function toUtcDateTime(\DateTime $time)
    {
        $UTC = new \DateTimeZone("UTC");
        $time->setTimezone($UTC);
        return $time->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return Response
     */
    public function execute()
    {
        $this->response = new Response();
        $this->response->addElement("responseDate", $this->toUtcDateTime(new \DateTime()));
        $this->response->addElement("request", "/someurl");

        try {
            $this->checkVerb();
            $verbOutput = $this->doVerb();
            $this->response->getDocument()->documentElement->appendChild($verbOutput);
        } catch (MultipleExceptions $errors) {
            foreach ($errors as $error) {
                $this->response->addError($error);
            }
        } catch (Exception $error) {
            $this->response->addError($error);
        }

        return $this->response;
    }

    private function doVerb()
    {
        switch ($this->verb) {
            case "Identify":
                return $this->identify();
                break;
            case "ListMetadataFormats":
                return $this->listMetadataFormats();
                break;
            case "ListSets":
                return $this->listSets();
                break;
            case "ListRecords":
                return $this->listRecords();
                break;
            default:
                //@todo;
        }
    }

    private function identify()
    {
        $identity = $this->repository->identify();
        $identityNode = $this->response->createElement('Identify');
        $identityNode->appendChild($this->response->createElement('repositoryName', $identity->getRepositoryName()));
        $identityNode->appendChild($this->response->createElement('baseURL', $identity->getBaseUrl()));
        $identityNode->appendChild($this->response->createElement('protocolVersion', '2.0'));
        foreach ($identity->getAdminEmails() as $email) {
            $identityNode->appendChild($this->response->createElement('adminEmail', $email));
        }
        $identityNode->appendChild(
            $this->response->createElement('earliestDatestamp', $this->toUtcDateTime($identity->getEarliestDatestamp()))
        );
        $identityNode->appendChild($this->response->createElement('deletedRecord', $identity->getDeletedRecord()));
        $identityNode->appendChild($this->response->createElement('granularity', $identity->getGranularity()));
        if ($identity->getCompression()) {
            $identityNode->appendChild($this->response->createElement('compression', $identity->getCompression()));
        }
        if ($identity->getDescription()) {
            $identityNode->appendChild($this->response->createElement('description', $identity->getDescription()));
        }

        return $identityNode;
    }

    private function listMetadataFormats()
    {
        $listNode = $this->response->createElement('ListMetadataFormats');

        $identifier = isset($this->request['identifier']) ? $this->request['identifier'] : null;
        $formats = $this->repository->listMetadataFormats($identifier);

        if (!count($formats)) {
            throw new NoMetadataFormatsException();
        }

        foreach ($formats as $format) {
            $formatNode = $this->response->createElement('metadataFormat');
            $formatNode->appendChild($this->response->createElement("metadataPrefix", $format->getPrefix()));
            $formatNode->appendChild($this->response->createElement("schema", $format->getSchema()));
            $formatNode->appendChild($this->response->createElement("metadataNamespace", $format->getNamespace()));
            $listNode->appendChild($formatNode);
        }
        return $listNode;
    }

    private function checkVerb()
    {
        if (!isset($this->request['verb'])) {
            throw new BadVerbException("Verb is missing");
        }

        $this->verb = $this->request['verb'];
        if (is_array($this->verb)) {
            throw new BadVerbException("Only 1 verb allowed, multiple given");
        }
        if (!array_key_exists($this->verb, self::$verbs)) {
            throw new BadVerbException("$this->verb is not a valid verb");
        }

        $requestParams = $this->request;
        unset($requestParams['verb']);

        $errors = [];
        foreach (array_diff(array_keys($requestParams), self::$verbs[$this->verb]) as $key => $value) {
            $errors[] = new BadArgumentException(
                "Argument {$key} is not allowed for verb $this->verb. " .
                "Allowed arguments are: " . implode(", ", self::$verbs[$this->verb])
            );
        }
        if (count($errors)) {
            throw (new MultipleExceptions())->setExceptions($errors);
        }
    }

    private function listSets()
    {
        $listNode = $this->response->createElement('ListSets');

        if (isset($this->request['resumptionToken'])) {
            $sets = $this->repository->listSetsByToken($this->request['resumptionToken']);
        } else {
            $sets = $this->repository->listSets();
            if (!count($sets->getItems())) {
                throw new NoSetHierarchyException();
            }
        }

        foreach($sets->getItems() as $set) {
            $setNode = $this->response->createElement('set');
            $setNode->appendChild($this->response->createElement('setSpec', $set->getSpec()));
            $setNode->appendChild($this->response->createElement('setName', $set->getName()));
            if ($set->getDescription()) {
                $setNode->appendChild($this->response->createElement('setDescription', $set->getDescription()));
            }
            $listNode->appendChild($setNode);
        }

        $this->addResumptionToken($sets, $listNode);

        return $listNode;
    }

    private function listRecords()
    {
        $listNode = $this->response->createElement('ListRecords');
        if (isset($this->request['resumptionToken'])) {
            $records = $this->repository->listRecordsByToken($this->request['resumptionToken']);
        } else {
            $from = null;
            $until = null;
            $set = $this->request['set']? $this->request['set']: null;

            $this->doChecks(
                [
                    function (){
                        if (!isset($this->request['metadataPrefix'])) {
                            throw new BadArgumentException("Missing required argument metadataPrefix");
                        }
                    },
                    function () use (&$from) {
                        if (isset($this->request['from'])) {
                            $from = $this->parseRequestDate($this->request['from']);
                        }
                    },
                    function () use (&$until) {
                        if (isset($this->request['until'])) {
                            $until = $this->parseRequestDate($this->request['until']);
                        }
                    },
                ]
            );

            $records = $this->repository->listRecords($this->request['metadataPrefix'], $from, $until, $set);

            if (!count($records->getItems())) {
                //maybe this is because someone tries to fetch from a set and we don't support that
                if ($set && !count($this->repository->listSets()->getItems())) {
                    throw new NoSetHierarchyException();
                }
                throw new NoRecordsMatchException();
            }
        }

        foreach ($records->getItems() as $record) {
            $recordNode = $this->response->createElement('record');
            $recordNode->appendChild($this->getRecordHeaderNode($record));
            $recordNode->appendChild($this->response->createElement('metadata', $record->getMetadata()));

            $about = $record->getAbout();
            if ($about) {
                $recordNode->appendChild($this->response->createElement('metadata', $record->getMetadata()));
            }

            $listNode->appendChild($recordNode);
        }

        $this->addResumptionToken($records, $listNode);

        return $listNode;
    }

    private function getRecordHeaderNode(Record $record){
        $headerNode = $this->response->createElement('header');
        $header = $record->getHeader();
        $headerNode->appendChild($this->response->createElement('identifier', $header->getIdentifier()));
        $headerNode->appendChild($this->response->createElement('datestamp', $header->getDatestamp()));
        foreach ($header->getSetSpecs() as $setSpec) {
            $headerNode->appendChild($this->response->createElement('setSpec', $setSpec));
        }
        if ($header->isDeleted()) {
            $headerNode->setAttribute("status", "deleted");
        }
        return $headerNode;
    }

    /**
     * @param $checks
     */
    private function doChecks($checks){
        $errors = [];
        foreach ($checks as $check) {
            try {
                $check();
            } catch (Exception $e) {
                $errors[] = $e;
            }
        }
        if (count($errors)) {
            throw (new MultipleExceptions)->setExceptions($errors);
        }
    }

    private function parseRequestDate($date)
    {
        $timezone = new \DateTimeZone("UTC");
        $date = date_create_from_format('Y-m-d\TH:i:s\Z', $date, $timezone);
        if (!$date) {
            $date = date_create_from_format('Y-m-d', $date, $timezone);
        }
        if (!$date) {
            throw new BadArgumentException();
        }

        return $date;
    }

    /**
     * @param RecordList $recordList
     * @param DomElement $listNode
     */
    private function addResumptionToken($recordList, $listNode)
    {
        if ($recordList->getResumptionToken()) {
            $resumptionTokenNode = $this->response->createElement('resumptionToken', $recordList->getResumptionToken());
            $listNode->appendChild($resumptionTokenNode);
        }
    }
}