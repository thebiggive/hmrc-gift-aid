<?php

namespace GovTalk\GiftAid;

use DOMDocument;
use GovTalk\GovTalk;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use XMLWriter;

/**
 * HMRC Gift Aid API client.  Extends the functionality provided by the
 * GovTalk class to build and parse HMRC Gift Aid submissions.
 *
 * @author    Long Luong
 * @copyright 2013, Veda Consulting Limited
 * @licence http://www.gnu.org/licenses/gpl-3.0.txt GNU General Public License
 *
 * @author    Justin Busschau
 * @copyright 2013 - 2014, Justin Busschau
 * Refactored as PSR-2 for inclusion in justinbusschau/php-govtalk package.
 */
class GiftAid extends GovTalk
{
    /* General IRenvelope related variables. */

    /**
     * Endpoints - One for test/dev and one for the live environment
     */
    private $devEndpoint  = 'https://test-transaction-engine.tax.service.gov.uk/submission';
    private $liveEndpoint = 'https://transaction-engine.tax.service.gov.uk/submission';

    /**
     * Vendor ID of software vendor
     */
    private string $vendorId = '';

    private static string $singleClaimMessageClass = 'HMRC-CHAR-CLM';
    private static string $multiClaimMessageClass = 'HMRC-CHAR-CLM-MULTI';

    /**
     * URI for product submitting the claim
     *
     * @var string
     */
    private $productUri = '';

    /**
     * Name of product submitting the claim
     *
     * @var string
     */
    private $productName = '';

    /**
     * Version of product submitting the claim
     *
     * @var string
     */
    private $productVersion = '';

    /**
     * Details of the agent sending the return declaration, if applicable.
     * Leads us to assume we should authenticate as Agent and change the message
     * type to be multi-claim if non-emtpy.
     *
     * @var string|array[]
     */
    private array $agentDetails = [];

    /* System / internal variables. */

    /**
     * Flag indicating if the IRmark should be generated for outgoing XML.
     *
     * @var boolean
     */
    private $generateIRmark = true;

    /* Variables for storing claim details */

    /**
     * Adjustments. Only support for single claims for now.
     */
    private $gaAdjustment = 0.00;
    private $gaAdjReason  = '';

    /**
     * Connected charities
     */
    private $connectedCharities = false;
    private $communityBuildings = false;

    /**
     * @var ClaimingOrganisation[]  Claiming organisation(s), keyed on HMRC ref.
     *
     * Can be replaced with one using { @see GiftAid::setClaimingOrganisation() } or added
     * to with { @see GiftAid::addClaimingOrganisation() } for multi-claims.
     */
    private array $claimingOrganisations = [];

    /**
     * Authorised official
     */
    private $authorisedOfficial = null;

    /**
     * Date of most recent claim
     */
    private $claimToDate = '';

    /**
     * Should we use compression on submitted claim?
     */
    private $compress = true;

    /**
     * Details of the Community buildings used
     */
    private $haveCbcd   = false;
    private $cbcdBldg   = [];
    private $cbcdAddr   = [];
    private $cbcdPoCo   = [];
    private $cbcdYear   = [];
    private $cbcdAmount = [];

    /**
     * Details for claims relating to the Small Donations Scheme
     */
    private $gasdsYear       = [];
    private $gasdsAmount     = [];
    private $gasdsAdjustment = 0.00;
    private $gasdsAdjReason  = '';

    /**
     * PSR-3 logger – defaulting to `NullLogger`.
     */
    private ?LoggerInterface $logger = null;

    /**
     * @var array   2D array to work back from Claim number (1-indexed) and within it GAD number
     *              (also 1-indexed) to provided donation IDs, if any.
     *              Top level key is XML's claim index, second is GAD index.
     *              e.g.:
     *              [
     *                1 => [
     *                  1 => 'charity-one-donation-id-001',
     *                  2 => 'charity-one-donation-id-002',
     *                ],
     *                2 => [
     * *                1 => 'charity-two-donation-id-001',
     *                ],
     *              ];
     */
    private array $donationIdMap = [];

    /**
     * The class is instantiated with the 'SenderID' and password issued to the
     * claiming charity by HMRC. Also we need to know whether messages for
     * this session are to be sent to the test or live environment
     *
     * @param string    $sender_id          The govTalk Sender ID as provided by HMRC
     * @param string    $password           The govTalk password as provided by HMRC
     * @param string    $route_uri          The URI of the owner of the process generating this route entry.
     * @param string    $software_name      The name of the software generating this route entry.
     * @param string    $software_version   The version number of the software generating this route entry.
     * @param bool      $test               TRUE if in test mode, else (default) FALSE
     * @param ?Client   $httpClient         The Guzzle HTTP Client to use for connections to the endpoint -
     *                                      null for default.
     * @param ?string   $customTestEndpoint Use to override the dev endpoint, e.g. for the Local Test
     *                                      Service use http://localhost:5665/LTS/LTSPostServlet
     */
    public function __construct(
        string $sender_id,
        string $password,
        string $route_uri,
        string $software_name,
        string $software_version,
        bool $test = false,
        ?Client $httpClient = null,
        ?string $customTestEndpoint = null
    ) {
        $test = is_bool($test) ? $test : false;

        $endpoint = $this->getEndpoint($test, $customTestEndpoint);

        $this->setProductUri($route_uri);
        $this->setProductName($software_name);
        $this->setProductVersion($software_version);
        $this->setTestFlag($test);

        parent::__construct(
            $endpoint,
            $sender_id,
            $password,
            $httpClient
        );

        $this->setMessageAuthentication('clear');

        if (!$this->logger) {
            $this->logger = new NullLogger();
        }

        $this->setLogger($this->logger);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        parent::setLogger($logger);
    }

    /**
     * Find out which endpoint to use.
     *
     * @param bool      $test TRUE if in test mode, else (default) FALSE
     * @param ?string   $customTestEndpoint Use to override the dev endpoint, e.g. for the Local Test
     *                                      Service use http://localhost:5665/LTS/LTSPostServlet
     * @link https://www.gov.uk/government/publications/local-test-service-and-lts-update-manager
     * @link https://github.com/comicrelief/gail/blob/e80a0793b5dac8b9c8e037e409a398eaf79342d3/Documents/technical_guidance.md
     */
    public function getEndpoint($test = false, ?string $customTestEndpoint = null): string
    {
        $test = is_bool($test) ? $test : false;

        if ($test && $customTestEndpoint) {
            return $customTestEndpoint;
        }

        return $test ? $this->devEndpoint : $this->liveEndpoint;
    }

    /**
     * Some getters and setters for our internal properties.
     */
    public function getCharityId()
    {
        if (!is_null($this->getClaimingOrganisation())) {
            return $this->getClaimingOrganisation()->getHmrcRef();
        } else {
            return false;
        }
    }

    public function setCharityId($value)
    {
        if (is_null($this->getClaimingOrganisation())) {
            $this->setClaimingOrganisation(
                new ClaimingOrganisation()
            );
        }
        $this->getClaimingOrganisation()->setHmrcRef($value);
    }

    public function getVendorId(): string
    {
        return $this->vendorId;
    }

    public function setVendorId(string $value): void
    {
        $this->vendorId = $value;
    }

    public function getProductUri()
    {
        return $this->productUri;
    }

    public function setProductUri($value): void
    {
        if (preg_match('/^\\d{4}$/', $value) !== 1) {
            throw new \UnexpectedValueException('"Product URI" should be a 4-digit HMRC vendor ID');
        }

        $this->productUri = $value;
    }

    public function getProductName()
    {
        return $this->productName;
    }

    public function setProductName($value)
    {
        $this->productName = $value;
    }

    public function getProductVersion()
    {
        return $this->productVersion;
    }

    public function setProductVersion($value)
    {
        $this->productVersion = $value;
    }

    public function clearGaAdjustment()
    {
        $this->gaAdjustment = 0.00;
        $this->gaAdjReason  = '';
    }

    public function setGaAdjustment($amount, $reason)
    {
        $this->gaAdjustment = $amount;
        $this->gaAdjReason  = $reason;
    }

    public function getGaAdjustment()
    {
        return ['amount' => $this->gaAdjustment, 'reason' => $this->gaAdjReason];
    }

    public function getConnectedCharities()
    {
        return $this->connectedCharities;
    }

    public function setConnectedCharities($value)
    {
        if (is_bool($value)) {
            $this->connectedCharities = $value;
        } else {
            $this->connectedCharities = false;
        }
    }

    public function getCommunityBuildings()
    {
        return $this->communityBuildings;
    }

    public function setCommunityBuildings($value)
    {
        if (is_bool($value)) {
            $this->communityBuildings = $value;
        } else {
            $this->communityBuildings = false;
        }
    }

    public function getClaimingOrganisation(?string $hmrcRef = null): ?ClaimingOrganisation
    {
        if ($hmrcRef === null) {
            return reset($this->claimingOrganisations) ?: null;
        }

        return $this->claimingOrganisations[$hmrcRef] ?? null;
    }

    public function setClaimingOrganisation(ClaimingOrganisation $organisation)
    {
        $this->claimingOrganisations = [$organisation->getHmrcRef() => $organisation];
    }

    public function addClaimingOrganisation(ClaimingOrganisation $organisation)
    {
        $this->claimingOrganisations[$organisation->getHmrcRef()] = $organisation;
    }

    public function getAuthorisedOfficial()
    {
        return $this->authorisedOfficial;
    }

    public function setAuthorisedOfficial(AuthorisedOfficial $value)
    {
        $this->authorisedOfficial = $value;
    }

    public function getClaimToDate()
    {
        return $this->claimToDate;
    }

    public function setClaimToDate($value)
    {
        $this->claimToDate = $value;
    }

    public function getCompress()
    {
        return $this->compress;
    }

    public function setCompress($value)
    {
        if (is_bool($value)) {
            $this->compress = $value;
        } else {
            $this->compress = false;
        }
    }

    public function addCbcd($bldg, $address, $postcode, $year, $amount)
    {
        $this->haveCbcd     = true;
        $this->cbcdBldg[]   = $bldg;
        $this->cbcdAddr[]   = $address;
        $this->cbcdPoCo[]   = $postcode;
        $this->cbcdYear[]   = $year;
        $this->cbcdAmount[] = $amount;
    }

    public function resetCbcd()
    {
        $this->haveCbcd   = false;
        $this->cbcdBldg   = [];
        $this->cbcdAddr   = [];
        $this->cbcdPoCo   = [];
        $this->cbcdYear   = [];
        $this->cbcdAmount = [];
    }

    public function addGasds($year, $amount)
    {
        $this->gasdsYear[]   = $year;
        $this->gasdsAmount[] = $amount;
    }

    public function resetGasds()
    {
        $this->gasdsYear   = [];
        $this->gasdsAmount = [];
    }

    public function setGasdsAdjustment($amount, $reason)
    {
        $this->gasdsAdjustment = $amount;
        $this->gasdsAdjReason  = $reason;
    }

    public function getGasdsAdjustment()
    {
        return ['amount' => $this->gasdsAdjustment, 'reason' => $this->gasdsAdjReason];
    }

    /**
     * Sets details about the agent submitting the declaration.
     *
     * The agent company's address should be specified in the following format:
     *   line => Array, each element containing a single line information.
     *   postcode => The agent company's postcode.
     *   country => The agent company's country. Defaults to England.
     *
     * The agent company's primary contact should be specified as follows:
     *   name => Array, format as follows:
     *     title => Contact's title (Mr, Mrs, etc.)
     *     forename => Contact's forename.
     *     surname => Contact's surname.
     *   email => Contact's email address (optional).
     *   telephone => Contact's telephone number (optional).
     *   fax => Contact's fax number (optional).
     *
     * @param string    $agentNo    14-digit numeric HMRC Agent Number.
     * @param string    $company    The agent company's name.
     * @param array     $address    The agent company's address in the format specified above.
     * @param ?array    $contact    The agent company's key contact (optional, may be skipped with a null value).
     * @param ?string   $reference  An identifier for the agent's own reference (optional).
     * @return bool                 Whether company format was as expected & agent data was set.
     */
    public function setAgentDetails(
        string $agentNo,
        string $company,
        array $address,
        ?array $contact = null,
        ?string $reference = null
    ): bool {
        $allowedCharsPattern = '/[A-Za-z0-9 &\'()*,\-.\/]*/';
        if (preg_match($allowedCharsPattern, $company)) {
            $this->agentDetails['number'] = $agentNo;
            $this->agentDetails['company'] = $company;
            $this->agentDetails['address'] = $address;
            if (!isset($this->agentDetails['address']['country'])) {
                $this->agentDetails['address']['country'] = 'United Kingdom';
            }
            if ($contact !== null) {
                $this->agentDetails['contact'] = $contact;
            }
            if (($reference !== null) && preg_match($allowedCharsPattern, $reference)) {
                $this->agentDetails['reference'] = $reference;
            }

            return true;
        }

        return false;
    }

    /**
     * Takes the $donations array as supplied to $this->giftAidSubmit
     * and adds it into the $package XMLWriter document.
     *
     * @param array $donations  A 2D array where top-level keys have no special
     *  meaning and each $donation has structure of:
     *  [
     *   'id'               => (?string) Optional but recommended to help trace
     *                         back any donation-specific errors
     *   'donation_date'    => (string) YYYY-MM-DD
     *   'title'            => (?string)
     *   'first_name'       => (string)
     *   'last_name'        => (string)
     *   'house_no'         => (string)
     *   'postcode'         => (?string) Must be a UK postcode for any UK address
     *   'overseas'         => (bool) Must be true if no postcode provided
     *   'sponsored'        => (bool) Whether this money is for a sponsored event
     *   'aggregation'      => (?string) Description of aggregated donations up to 35
     *                         characters, if applicable
     *   'amount'           => (float) In whole pounds GBP
     *   'org_hmrc_ref'     => (?string) Required for Agent multi-charity claims. Ignored for others.
     * ]
     * @return string
     */
    private function buildClaimXml(array $donations): string
    {
        $package = new XMLWriter();
        $package->openMemory();
        $package->setIndent(true);

        $currentClaimOrgRef = null;
        $claimOpen = false;
        $claimNumber = $gadNumber = 0;
        $earliestDate = strtotime(date('Y-m-d'));

        foreach ($donations as $index => $d) {
            if ($this->isAgentMultiClaim()) {
                if (empty($d['org_hmrc_ref'])) {
                    $this->logger->warning(sprintf(
                        'Skipping donation index %d (%s %s) with no org ref in agent multi mode',
                        $index,
                        $d['first_name'],
                        $d['last_name'],
                    ));
                    continue;
                }
            } else {
                // In single charity mode, always set up Claim header for the only claiming org.
                $d['org_hmrc_ref'] = $this->getClaimingOrganisation()->getHmrcRef();
            }

            if (!$claimOpen || $currentClaimOrgRef !== $d['org_hmrc_ref']) {
                // New or first charity in a claim.
                if ($claimOpen) {
                    $this->writeClaimEndData($package, $earliestDate);
                    $claimOpen = false;
                }

                $earliestDate = strtotime(date('Y-m-d'));

                /** @var ClaimingOrganisation $org */
                $org = $this->getClaimingOrganisation($d['org_hmrc_ref']);
                if (!$org) {
                    $this->logger->warning('Skipping donation with unknown org ref ' . $d['org_hmrc_ref']);
                    continue;
                }

                $this->writeClaimStartData($package, $org);
                $claimOpen = true;
                $claimNumber++;
                $gadNumber = 0;
            }

            if (isset($d['donation_date'])) {
                $dDate        = strtotime($d['donation_date']);
                $earliestDate = ($dDate < $earliestDate) ? $dDate : $earliestDate;
            }
            $package->startElement('GAD');
            $gadNumber++;

            if (isset($d['id'])) {
                $this->donationIdMap[$claimNumber][$gadNumber] = $d['id'];
            }

            if (!isset($d['aggregation']) || empty($d['aggregation'])) {
                $package->startElement('Donor');
                $person = new Individual(
                    $d['title'],
                    $d['first_name'],
                    $d['last_name'],
                    '',
                    $d['house_no'],
                    $d['postcode'],
                    (bool) $d['overseas']
                );

                $title    = $person->getTitle();
                $fore     = $person->getForename();
                $sur      = $person->getSurname();
                $house    = $person->getHouseNum();
                $postcode = $person->getPostcode();
                $overseas = $person->getIsOverseas();

                if (!empty($title)) {
                    $package->writeElement('Ttl', $title);
                }
                $package->writeElement('Fore', $fore);
                $package->writeElement('Sur', $sur);
                $package->writeElement('House', $house);
                if (!empty($postcode)) {
                    $package->writeElement('Postcode', $postcode);
                } else {
                    $package->writeElement('Overseas', $overseas);
                }
                $package->endElement(); # Donor
            } else { // 'aggregation' non-empty
                $package->writeElement('AggDonation', $d['aggregation']);
            }
            if (isset($d['sponsored']) && $d['sponsored'] === true) {
                $package->writeElement('Sponsored', 'yes');
            }
            $package->writeElement('Date', $d['donation_date']);
            $package->writeElement('Total', number_format($d['amount'], 2, '.', ''));
            $package->endElement(); # GAD
        }

        if ($claimOpen) {
            // End of last claim in the loop.
            $this->writeClaimEndData($package, $earliestDate);
        }

        return $package->outputMemory();
    }

    /**
     * Submit a GA Claim - this is the crux of the biscuit.
     *
     * @param array $donor_data
     * @return array|bool   Processed response array, or false if initial validation failed.
     */
    public function giftAidSubmit($donor_data)
    {
        $dReturnPeriod = $this->getClaimToDate();
        if (empty($dReturnPeriod)) {
            $this->logger->error('Cannot proceed without claimToDate');
            return false;
        }

        if ($this->getAuthorisedOfficial() === null) {
            $this->logger->error('Cannot proceed without authorisedOfficial');
            return false;
        }

        $cOrganisation      = 'IR';
        $sDefaultCurrency   = 'GBP'; // currently HMRC only allows GBP
        $sIRmark            = 'IRmark+Token';
        $sSender            = $this->isAgentMultiClaim() ? 'Agent' : 'Individual';

        // Set the message envelope
        $this->setMessageClass($this->getMessageClass());
        $this->setMessageQualifier('request');
        $this->setMessageFunction('submit');
        $this->setMessageCorrelationId(null);
        $this->setMessageTransformation('XML');
        $this->addTargetOrganisation($cOrganisation);

        $this->addMessageKey($this->getCharIdKey(), $this->getCharIdValue());

        $this->setChannelRoute(
            $this->getProductUri(),
            $this->getProductName(),
            $this->getProductVersion()
        );

        // Build message body...
        $package = new XMLWriter();
        $package->openMemory();
        $package->setIndent(true);

        $package->startElement('IRenvelope');
        $package->writeAttribute('xmlns', 'http://www.govtalk.gov.uk/taxation/charities/r68/2');

        $package->startElement('IRheader');
        $package->startElement('Keys');
        $package->startElement('Key');
        $package->writeAttribute('Type', $this->getCharIdKey());
        $package->text($this->getCharIdValue());
        $package->endElement(); # Key
        $package->endElement(); # Keys
        $package->writeElement('PeriodEnd', $dReturnPeriod);

        if ($this->isAgentMultiClaim()) {
            $package->startElement('Agent');

            $package->writeElement('Company', $this->agentDetails['company']);

            $package->startElement('Address');
            if (!empty($this->agentDetails['address']['line'])) {
                foreach ($this->agentDetails['address']['line'] as $line) {
                    $package->writeElement('Line', $line);
                }
            }
            if (!empty($this->agentDetails['address']['postcode'])) {
                $package->writeElement('PostCode', $this->agentDetails['address']['postcode']);
            }
            // Has a fallback default, so should always be set.
            $package->writeElement('Country', $this->agentDetails['address']['country']);
            $package->endElement(); // Address

            if (isset($this->agentDetails['contact'])) {
                $package->startElement('Contact');

                $package->startElement('Name');
                if (!empty($this->agentDetails['contact']['name']['title'])) {
                    $package->writeElement('Ttl', $this->agentDetails['contact']['name']['title']);
                }
                $package->writeElement('Fore', $this->agentDetails['contact']['name']['forename']);
                $package->writeElement('Sur', $this->agentDetails['contact']['name']['surname']);
                $package->endElement(); // Name

                if (!empty($this->agentDetails['contact']['email'])) {
                    $package->writeElement('Email', $this->agentDetails['contact']['email']);
                }

                if (!empty($this->agentDetails['contact']['telephone'])) {
                    $package->startElement('Telephone');
                    $package->writeElement('Number', $this->agentDetails['contact']['telephone']);
                    $package->endElement(); // Telephone
                }

                if (!empty($this->agentDetails['contact']['fax'])) {
                    $package->startElement('Fax');
                    $package->writeElement('Number', $this->agentDetails['contact']['fax']);
                    $package->endElement(); // Fax
                }

                $package->endElement(); // Contact
            }

            $package->endElement(); // Agent
        }

        $package->writeElement('DefaultCurrency', $sDefaultCurrency);
        $package->startElement('IRmark');
        $package->writeAttribute('Type', 'generic');
        $package->text($sIRmark);
        $package->endElement(); #IRmark
        $package->writeElement('Sender', $sSender);
        $package->endElement(); #IRheader

        $package->startElement('R68');

        if ($this->isAgentMultiClaim()) {
            $package->startElement('CollAgent');
            $package->writeElement('AgentNo', $this->agentDetails['number']);
            $claimNo = $this->agentDetails['reference'] ?? uniqid();
            $package->writeElement('ClaimNo', $claimNo);
            $package->endElement(); // CollAgent
        } else {
            $package->startElement('AuthOfficial');
            $package->startElement('OffName');
            $title = $this->getAuthorisedOfficial()->getTitle();
            if (!empty($title)) {
                $package->writeElement('Ttl', $title);
            }
            $package->writeElement('Fore', $this->getAuthorisedOfficial()->getForename());
            $package->writeElement('Sur', $this->getAuthorisedOfficial()->getSurname());
            $package->endElement(); #OffName
            $package->startElement('OffID');
            $package->writeElement('Postcode', $this->getAuthorisedOfficial()->getPostcode());
            $package->endElement(); #OffID
            $package->writeElement('Phone', $this->getAuthorisedOfficial()->getPhone());
            $package->endElement(); #AuthOfficial
        }

        $package->writeElement('Declaration', 'yes');

        $claimDataXml = $this->buildClaimXml($donor_data);
        if ($this->compress) {
            $package->startElement('CompressedPart');
            $package->writeAttribute('Type', 'gzip');
            $package->text(base64_encode(gzencode($claimDataXml, 9, FORCE_GZIP)));
            $package->endElement(); # CompressedPart
        } else {
            $package->writeRaw($claimDataXml);
        }

        $package->endElement(); #R68
        $package->endElement(); #IRenvelope

        // Send the message and deal with the response...
        $this->setMessageBody($package);

        if ($this->sendMessage() && ($this->responseHasErrors() === false)) {
            $returnable                  = $this->getResponseEndpoint();
            $returnable['correlationid'] = $this->getResponseCorrelationId();
        } else {
            $returnable = ['errors' => $this->getResponseErrors()];
            $returnable['donation_ids_with_errors'] = $this->getDistinctErroringDonations(
                $returnable['errors']['business']
            );
        }
        $returnable['claim_data_xml']     = $claimDataXml;
        $returnable['submission_request'] = $this->fullRequestString;

        return $returnable;
    }

    /**
     * Submit a request for GA Claim Data
     */
    public function requestClaimData()
    {
        $this->setMessageClass($this->getMessageClass());
        $this->setMessageQualifier('request');
        $this->setMessageFunction('list');
        $this->setMessageCorrelationId('');
        $this->setMessageTransformation('XML');

        $this->addTargetOrganisation('IR');

        $this->addMessageKey($this->getCharIdKey(), $this->getClaimingOrganisation()->getHmrcRef());

        $this->setChannelRoute(
            $this->getProductUri(),
            $this->getProductName(),
            $this->getProductVersion()
        );

        $this->setMessageBody('');

        if ($this->sendMessage() && ($this->responseHasErrors() === false)) {
            $returnable = $this->getResponseEndpoint();
            foreach ($this->fullResponseObject->Body->StatusReport->StatusRecord as $node) {
                $array = [];
                foreach ($node->children() as $child) {
                    $array[$child->getName()] = (string) $child;
                }
                $returnable['statusRecords'][] = $array;
            }
        } else {
            $returnable = ['errors' => $this->getResponseErrors()];
        }
        $returnable['submission_request'] = $this->fullRequestString;

        return $returnable;
    }

    /**
     * Polls the Gateway for a submission response / error following a VAT
     * declaration request. By default the correlation ID from the last response
     * is used for the polling, but this can be over-ridden by supplying a
     * correlation ID. The correlation ID can be skipped by passing a null value.
     *
     * If the resource is still pending this method will return the same array
     * as declarationRequest() -- 'endpoint', 'interval' and 'correlationid' --
     * if not then it'll return lots of useful information relating to the return
     * and payment of any VAT due in the following array format:
     *
     *  message => an array of messages ('Thank you for your submission', etc.).
     *  accept_time => the time the submission was accepted by the HMRC server.
     *  period => an array of information relating to the period of the return:
     *    id => the period ID.
     *    start => the start date of the period.
     *    end => the end date of the period.
     *  payment => an array of information relating to the payment of the return:
     *    narrative => a string representation of the payment (generated by HMRC)
     *    netvat => the net value due following this return.
     *    payment => an array of information relating to the method of payment:
     *      method => the method to be used to pay any money due, options are:
     *        - nilpayment: no payment is due.
     *        - repayment: a repayment from HMRC is due.
     *        - directdebit: payment will be taken by previous direct debit.
     *        - payment: payment should be made by alternative means.
     *      additional => additional information relating to this payment.
     *
     * @param string $correlationId The correlation ID of the resource to poll. Can be skipped with a null value.
     * @param string $pollUrl       The URL of the Gateway to poll.
     *
     * @return mixed An array of details relating to the return and the original request, or false on failure.
     */
    public function declarationResponsePoll($correlationId = null, $pollUrl = null)
    {
        if ($correlationId === null) {
            $correlationId = $this->getResponseCorrelationId();
        }

        if ($this->setMessageCorrelationId($correlationId)) {
            if ($pollUrl !== null) {
                $this->setGovTalkServer($pollUrl);
            }
            $this->setMessageClass($this->getMessageClass());
            $this->setMessageQualifier('poll');
            $this->setMessageFunction('submit');
            $this->setMessageTransformation('XML');
            $this->resetMessageKeys();
            $this->setMessageBody('');
            if ($this->sendMessage() && ($this->responseHasErrors() === false)) {
                $messageQualifier = (string) $this->fullResponseObject->Header->MessageDetails->Qualifier;
                if ($messageQualifier === 'response') {
                    return [
                        'correlationid'       => $correlationId,
                        'submission_request'  => $this->fullRequestString,
                        'submission_response' => $this->fullResponseString
                    ];
                } elseif ($messageQualifier === 'acknowledgement') {
                    $returnable                       = $this->getResponseEndpoint();
                    $returnable['correlationid']      = $this->getResponseCorrelationId();
                    $returnable['submission_request'] = $this->fullRequestString;

                    return $returnable;
                } else {
                    return false;
                }
            } else {
                if ($this->responseHasErrors()) {
                    return [
                        'errors'             => $this->getResponseErrors(),
                        'fullResponseString' => $this->fullResponseString
                    ];
                }

                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * Adds a valid IRmark to the given package.
     *
     * This function over-rides the packageDigest() function provided in the main
     * php-govtalk class.
     *
     * @param string $package The package to add the IRmark to.
     *
     * @return string The new package after addition of the IRmark.
     */
    protected function packageDigest($package)
    {
        $packageSimpleXML  = simplexml_load_string($package);
        $packageNamespaces = $packageSimpleXML->getNamespaces();

        $body = $packageSimpleXML->xpath('GovTalkMessage/Body');

        preg_match('#<Body>(.*)<\/Body>#su', $packageSimpleXML->asXML(), $matches);
        $packageBody = $matches[1];

        $irMark  = base64_encode($this->generateIRMark($packageBody, $packageNamespaces));
        $package = str_replace('IRmark+Token', $irMark, $package);

        return $package;
    }

    protected function isAgentMultiClaim(): bool
    {
        return !empty($this->agentDetails);
    }

    /**
     * @link https://assets.publishing.service.gov.uk/government/uploads/system/uploads/attachment_data/file/735545/Charities-OnlineValidsV1.3.pdf
     */
    protected function getMessageClass(): string
    {
        return $this->isAgentMultiClaim() ? static::$multiClaimMessageClass : static::$singleClaimMessageClass;
    }

    /**
     * @link https://assets.publishing.service.gov.uk/government/uploads/system/uploads/attachment_data/file/735545/Charities-OnlineValidsV1.3.pdf
     */
    protected function getCharIdKey(): string
    {
        return $this->isAgentMultiClaim() ? 'AGENTCHARID' : 'CHARID';
    }

    protected function getCharIdValue(): string
    {
        return $this->isAgentMultiClaim()
            ? $this->agentDetails['number']
            : $this->getClaimingOrganisation()->getHmrcRef();
    }

    protected function writeClaimStartData(XMLWriter $package, ClaimingOrganisation $org): void
    {
        $package->startElement('Claim');
        $package->writeElement('OrgName', $org->getName());
        $package->writeElement('HMRCref', $org->getHmrcRef());

        // LTS response code 7032: "Regulator details must not be present if
        // Collecting Agent details are present"
        if (!$this->isAgentMultiClaim()) {
            $package->startElement('Regulator');

            if ($org->getRegulator() === null) {
                $package->writeElement('NoReg', 'yes');
            } elseif ($org->hasStandardRegulator()) {
                $package->writeElement('RegName', $org->getRegulator());
            } else {
                $package->writeElement('OtherReg', $org->getRegulator());
            }
            $package->writeElement('RegNo', $org->getRegNo());
            $package->endElement(); # Regulator
        }

        $package->startElement('Repayment');
    }

    protected function writeClaimEndData(XMLWriter $package, $earliestDate): void
    {
        $package->writeElement('EarliestGAdate', date('Y-m-d', $earliestDate));

        if (!empty($this->gaAdjustment)) {
            $package->writeElement('Adjustment', number_format($this->gaAdjustment, 2, '.', ''));
        }
        $package->endElement(); # Repayment

        // LTS response code 7044: "A submission from a Collecting Agent must not include
        // details of Gift Aid Small Donations Schemes"
        if (!$this->isAgentMultiClaim()) {
            $package->startElement('GASDS');
            $package->writeElement(
                'ConnectedCharities',
                $this->getClaimingOrganisation()->getHasConnectedCharities() ? 'yes' : 'no'
            );
            foreach ($this->getClaimingOrganisation()->getConnectedCharities() as $cc) {
                $package->startElement('Charity');
                $package->writeElement('Name', $cc->getName());
                $package->writeElement('HMRCref', $cc->getHmrcRef());
                $package->endElement(); # Charity
            }
            if ($this->haveGasds()) {
                foreach ($this->gasdsYear as $key => $val) {
                    $package->startElement('GASDSClaim');
                    $package->writeElement('Year', $this->gasdsYear[$key]);
                    $package->writeElement('Amount', number_format($this->gasdsAmount[$key], 2, '.', ''));
                    $package->endElement(); # GASDSClaim
                }
            }

            $package->writeElement('CommBldgs', ($this->haveCbcd == true) ? 'yes' : 'no');
            foreach ($this->cbcdAddr as $key => $val) {
                $package->startElement('Building');
                $package->writeElement('BldgName', $this->cbcdBldg[$key]);
                $package->writeElement('Address', $this->cbcdAddr[$key]);
                $package->writeElement('Postcode', $this->cbcdPoCo[$key]);
                $package->startElement('BldgClaim');
                $package->writeElement('Year', $this->cbcdYear[$key]);
                $package->writeElement('Amount', number_format($this->cbcdAmount[$key], 2, '.', ''));
                $package->endElement(); # BldgClaim
                $package->endElement(); # Building
            }

            if (!empty($this->gasdsAdjustment)) {
                $package->writeElement('Adj', number_format($this->gasdsAdjustment, 2, '.', ''));
            }

            $package->endElement(); # GASDS
        }

        $otherInfo = [];
        if (!empty($this->gasdsAdjustment)) {
            $otherInfo[] = $this->gasdsAdjReason;
        }
        if (!empty($this->gaAdjustment)) {
            $otherInfo[] = $this->gaAdjReason;
        }
        if (count($otherInfo) > 0) {
            $package->writeElement('OtherInfo', implode(' AND ', $otherInfo));
        }

        $package->endElement(); # Claim
    }

    /**
     * Generates an IRmark hash from the given XML string for use in the IRmark
     * node inside the message body.  The string passed must contain one IRmark
     * element containing the string IRmark (ie. <IRmark>IRmark+Token</IRmark>) or the
     * function will fail.
     *
     * @param $xmlString string The XML to generate the IRmark hash from.
     *
     * @return string The IRmark hash.
     */
    private function generateIRMark($xmlString, $namespaces = null)
    {
        if (is_string($xmlString)) {
            $xmlString = preg_replace(
                '/<(vat:)?IRmark Type="generic">[A-Za-z0-9\/\+=]*<\/(vat:)?IRmark>/',
                '',
                $xmlString,
                - 1,
                $matchCount
            );
            if ($matchCount == 1) {
                $xmlDom = new DOMDocument;

                if ($namespaces !== null && is_array($namespaces)) {
                    $namespaceString = [];
                    foreach ($namespaces as $key => $value) {
                        if ($key !== '') {
                            $namespaceString[] = 'xmlns:' . $key . '="' . $value . '"';
                        } else {
                            $namespaceString[] = 'xmlns="' . $value . '"';
                        }
                    }
                    $bodyCompiled = '<Body ' . implode(' ', $namespaceString) . '>' . $xmlString . '</Body>';
                } else {
                    $bodyCompiled = '<Body>' . $xmlString . '</Body>';
                }
                $xmlDom->loadXML($bodyCompiled);

                return sha1($xmlDom->documentElement->C14N(), true);
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function getResponseErrors()
    {
        $govTalkErrors = parent::getResponseErrors();

        foreach ($govTalkErrors['business'] as $b_index => $b_err) {
            // Looks like this is removed because it is the most generic error code and is expected to always be
            // accompanied by more specific ones later in the response. Text found alongside this code is: "Your
            // submission failed due to business validation errors. Please see below for details."
            if ($b_err['number'] === '3001') {
                unset($govTalkErrors['business'][$b_index]);
            }
        }

        $has_gt_errors = false;
        foreach ($govTalkErrors as $type) {
            if (count($type) > 0) {
                $has_gt_errors = true;
            }
        }

        if (!$has_gt_errors) {
            // lay out the GA errors
            foreach ($this->fullResponseObject->Body->ErrorResponse->Error as $gaError) {
                $donationId = null;
                $pattern = '!^/hd:GovTalkMessage\[1]/hd:Body\[1]/r68:IRenvelope\[1]/r68:R68\[1]/' .
                    'r68:Claim\[(\d+)]/r68:Repayment\[1]/r68:GAD\[(\d+)].+$!';
                if (isset($gaError->Location) && preg_match($pattern, $gaError->Location, $matches) === 1) {
                    if (isset($this->donationIdMap[$matches[1]][$matches[2]])) {
                        $donationId = $this->donationIdMap[$matches[1]][$matches[2]];
                    }
                }

                $govTalkErrors['business'][] = [
                    'number'   => (string) $gaError->Number,
                    'text'     => (string) $gaError->Text,
                    'location' => (string) $gaError->Location,
                    'donation_id' => $donationId,
                ];
            }
        }

        return $govTalkErrors;
    }

    /**
     * @param array[] $businessErrors each possibly including a 'donation_id' key.
     * @return string[] Donation IDs
     */
    private function getDistinctErroringDonations(array $businessErrors): array
    {
        if (empty($this->donationIdMap)) {
            return []; // Input should always be donation-ID-free when there's no map values.
        }

        $donationIds = [];
        foreach ($businessErrors as $businessError) {
            if (!empty($businessError['donation_id']) && !in_array($businessError['donation_id'], $donationIds, true)) {
                $donationIds[] = $businessError['donation_id'];
            }
        }

        return $donationIds;
    }

    protected function haveGasds(): bool
    {
        return count($this->gasdsYear) > 0;
    }
}
