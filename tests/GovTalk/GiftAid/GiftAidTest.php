<?php

namespace GovTalk\GiftAid;

/**
 * The base class for all GovTalk\GiftAid tests
 */
class GiftAidTest extends TestCase
{
    /**
     * The gateway user ID
     */
    private $gatewayUserID;

    /**
     * The gateway user password
     */
    private $gatewayUserPassword;

    /**
     * The gateway vendor ID
     */
    private $gatewayVendorID;

    /**
     * The product submitting the claim
     */
    private $gatewaySoftware;

    /**
     * The product version
     */
    private $gatewaySoftVersion;

    private array $claims;

    public function setUp(): void
    {
        parent::setUp();

        /**
         * The user name (Sender ID) and password given below are not valid for
         * either the live or any of the test/dev gateways. If you want to run
         * this test suite against actual servers, please contact the relevant
         * agency (HMRC / Companies House / etc.) and apply for valid credentials.
         */
        $this->gatewayUserID = 'XMLGatewayTestUserID';
        $this->gatewayUserPassword = 'XMLGatewayTestPassword';
        $this->gatewayVendorID = 'GatewaySubmitter';
        $this->gatewaySoftware = 'GivingSoft';
        $this->gatewaySoftVersion = '1.2.0';

        /**
         * An authorised official for testing ...
         */
        $this->officer = new AuthorisedOfficial(
            null,
            'Bob',
            'Smith',
            '01234 567890',
            'AB12 3CD'
        );

        /**
         * A claiming organisation
         */
        $this->claimant = new ClaimingOrganisation(
            'A Fundraising Organisation',
            'AB12345',
            'CCEW',
            '123456'
        );

        $this->claims = [
            [
                'donation_date' => '2013-04-07',
                'title' => 'Mrs',
                'first_name' => 'Mary',
                'last_name' => 'Smith',
                'house_no' => '100',
                'postcode' => 'AB23 4CD',
                'overseas' => false,
                'amount' => 500.00,
                'sponsored' => true
            ],
            [
                'donation_date' => '2013-04-15',
                'title' => null,
                'first_name' => 'Jim',
                'last_name' => 'Harris',
                'house_no' => '25 High St Anytown Foreignshire',
                'postcode' => null,
                'overseas' => true,
                'amount' => 10.00
            ],
            [
                'donation_date' => '2013-04-17',
                'title' => null,
                'first_name' => 'Bill',
                'last_name' => 'Hill-Jones',
                'house_no' => '1',
                'postcode' => 'BA23 9CD',
                'overseas' => false,
                'amount' => 2.50
            ],
            [
                'donation_date' => '2013-04-20',
                'title' => null,
                'first_name' => 'Bob',
                'last_name' => 'Hill-Jones',
                'house_no' => '1',
                'postcode' => 'BA23 9CD',
                'overseas' => false,
                'amount' => 12.00
            ],
            [
                'donation_date' => '2013-04-20',
                'amount' => 1000.00,
                'aggregation' => 'Aggregated donation of 200 x £5 payments from members'
            ]
        ];

        /**
         * The following call sets up the service object used to interact with the
         * Government Gateway. Setting parameter 7 to null will force the test to
         * use the httpClient created on the fly within the GovTalk class and may
         * also effectively disable mockability.
         */
        $this->gaService = $this->setUpService();
    }

    public function testServiceCreation()
    {
        $this->gaService->setAgentDetails('11111222223333', 'company', ['ln1','ln2','pc'], ['07123456789']);
        $this->assertInstanceOf(GiftAid::class, $this->gaService);
    }

    public function testCharityId()
    {
        $value = uniqid();
        $this->gaService->setCharityId($value);
        $this->assertSame($value, $this->gaService->getCharityId());
    }

    public function testVendorId()
    {
        $value = uniqid();
        $this->gaService->setVendorId($value);
        $this->assertSame($value, $this->gaService->getVendorId());
    }

    public function testProductUri()
    {
        $value = uniqid();
        $this->gaService->setProductUri($value);
        $this->assertSame($value, $this->gaService->getProductUri());
    }

    public function testProductName()
    {
        $value = uniqid();
        $this->gaService->setProductName($value);
        $this->assertSame($value, $this->gaService->getProductName());
    }

    public function testProductVersion()
    {
        $value = uniqid();
        $this->gaService->setProductVersion($value);
        $this->assertSame($value, $this->gaService->getProductVersion());
    }

    public function testConnectedCharities()
    {
        $this->gaService->setConnectedCharities(false);
        $this->assertFalse($this->gaService->getConnectedCharities());

        $this->gaService->setConnectedCharities(true);
        $this->assertTrue($this->gaService->getConnectedCharities());

        // non-bool values are treated as false
        $this->gaService->setConnectedCharities('1');
        $this->assertFalse($this->gaService->getConnectedCharities());
    }

    public function testCommunityBuildings()
    {
        $this->gaService->setCommunityBuildings(false);
        $this->assertFalse($this->gaService->getCommunityBuildings());

        $this->gaService->setCommunityBuildings(true);
        $this->assertTrue($this->gaService->getCommunityBuildings());

        // non-bool values are treated as false
        $this->gaService->setCommunityBuildings('1');
        $this->assertFalse($this->gaService->getCommunityBuildings());
    }

    public function testCbcd()
    {
        $this->gaService->addCbcd('bldg', 'address', 'postcode', '2014', 12.34);
        $this->gaService->resetCbcd();

        // Existing test had no assertions. For now, assume the point is just to
        // ensure the private methods used upstream run without crashes? Preferably,
        // this would probably eventually be replaced with a test that covers the
        // whole XML message build, instead of this very narrow piece in isolation.
        $this->addToAssertionCount(1);
    }

    public function testClaimToDate()
    {
        $value = uniqid();
        $this->gaService->setClaimToDate($value);
        $this->assertSame($value, $this->gaService->getClaimToDate());
    }

    public function testAuthorisedOfficial()
    {
        $this->gaService->setAuthorisedOfficial($this->officer);
        $this->assertSame($this->officer, $this->gaService->getAuthorisedOfficial());
    }

    public function testClaimingOrganisation()
    {
        $this->gaService->setClaimingOrganisation($this->claimant);
        $this->assertSame($this->claimant, $this->gaService->getClaimingOrganisation());
    }

    public function testEndpoint()
    {
        $testEndpoint = $this->gaService->getEndpoint(true);
        $liveEndpoint = $this->gaService->getEndpoint(false);

        $this->assertNotSame($liveEndpoint, $testEndpoint);
    }

    public function testAdjustments()
    {
        $clear = ['amount' => 0.00, 'reason' => ''];
        $adjust = ['amount' => 16.47, 'reason' => 'Refunds issued on previous donations.'];

        $this->gaService->setGaAdjustment(
            $adjust['amount'],
            $adjust['reason']
        );
        $this->assertSame($adjust, $this->gaService->getGaAdjustment());

        $this->gaService->clearGaAdjustment();
        $this->assertSame($clear, $this->gaService->getGaAdjustment());
    }

    public function testGasds()
    {
        $clear = ['amount' => 0.00, 'reason' => ''];
        $adjust = ['amount' => 16.47, 'reason' => 'Refunds issued on previous GASDS donations.'];

        $this->gaService->setGasdsAdjustment(
            $adjust['amount'],
            $adjust['reason']
        );
        $this->assertSame($adjust, $this->gaService->getGasdsAdjustment());

        $this->gaService->setGasdsAdjustment(
            $clear['amount'],
            $clear['reason']
        );
        $this->assertSame($clear, $this->gaService->getGasdsAdjustment());

        $this->gaService->addGasds('2014', 15.26);
        $this->gaService->resetGasds();
    }

    public function testCompress()
    {
        $this->gaService->setCompress(false);
        $this->assertFalse($this->gaService->getCompress());

        $this->gaService->setCompress(true);
        $this->assertTrue($this->gaService->getCompress());

        // non-bool values are treated as false
        $this->gaService->setCompress('1');
        $this->assertFalse($this->gaService->getCompress());
    }

    public function testClaimSubmissionAuthFailure()
    {
        $this->setMockHttpResponse('SubmitAuthFailureResponse.xml');
        $this->gaService = $this->setUpService(); // Use client w/ mock queue.

        $this->gaService->setAuthorisedOfficial($this->officer);
        $this->gaService->setClaimingOrganisation($this->claimant);
        $this->gaService->setClaimToDate('2000-01-01');
        $response = $this->gaService->giftAidSubmit($this->claims);

        $this->assertArrayHasKey('errors', $response);
        $this->assertArrayHasKey('fatal', $response['errors']);
        $this->assertSame('1046', $response['errors']['fatal'][0]['number']);
        $this->assertSame(
            'Authentication Failure. The supplied user credentials failed validation for the requested service.',
            $response['errors']['fatal'][0]['text']
        );
    }

    public function testClaimSubmissionAck()
    {
        $this->setMockHttpResponse('SubmitAckResponse.xml');
        $this->gaService = $this->setUpService(); // Use client w/ mock queue.

        $this->gaService->setAuthorisedOfficial($this->officer);
        $this->gaService->setClaimingOrganisation($this->claimant);
        $this->gaService->setClaimToDate('2000-01-01');
        $response = $this->gaService->giftAidSubmit($this->claims);

        $this->assertArrayNotHasKey('errors', $response);
        //$this->assertSame('acknowledgement', $this->gaService->getResponseQualifier());
        $this->assertArrayHasKey('correlationid', $response);
        $this->assertArrayHasKey('endpoint', $response);
        $this->assertArrayHasKey('interval', $response);
        $this->assertSame('A19FA1A31BCB42D887EA323292AACD88', $response['correlationid']);
    }

    public function testMultiClaimSubmissionAck(): void
    {
        $this->setMockHttpResponse('SubmitMultiAckResponse.xml');
        $this->gaService = $this->setUpService(); // Use client w/ mock queue.

        $this->gaService->setAuthorisedOfficial($this->officer);
        $this->gaService->setClaimingOrganisation($this->claimant);
        $this->gaService->setClaimToDate('2000-01-01');

        $this->gaService = $this->addValidTestAgent($this->gaService);

        $this->gaService->addClaimingOrganisation($this->claimant);
        $claim = $this->claims;
        foreach ($claim as $index => $donation) {
            $claim[$index]['org_hmrc_ref'] = $this->claimant->getHmrcRef();
        }

        $response = $this->gaService->giftAidSubmit($claim);

        $this->assertArrayNotHasKey('errors', $response);
        $this->assertArrayHasKey('correlationid', $response);
        $this->assertArrayHasKey('endpoint', $response);
        $this->assertArrayHasKey('interval', $response);
        $this->assertSame('9072983591062099772', $response['correlationid']);
    }

    public function testMultiClaimSubmissionWithFirstDonationNamesMissing(): void
    {
        $this->setMockHttpResponse('SubmitMultiMissingNamesResponse.xml');
        $this->gaService = $this->setUpService(); // Use client w/ mock queue.

        // Provide identifier to trace donation errors, and give the 0th donation one by clearing
        // its required name fields.
        $this->claims[0]['id'] = 'some-uuid-1234';
        $this->claims[0]['first_name'] = '';
        $this->claims[0]['last_name'] = '';

        $this->gaService->setAuthorisedOfficial($this->officer);
        $this->gaService->setClaimingOrganisation($this->claimant);
        $this->gaService->setClaimToDate('2000-01-01');

        $this->gaService = $this->addValidTestAgent($this->gaService);

        $this->gaService->addClaimingOrganisation($this->claimant);
        $claim = $this->claims;
        foreach ($claim as $index => $donation) {
            $claim[$index]['org_hmrc_ref'] = $this->claimant->getHmrcRef();
        }

        $response = $this->gaService->giftAidSubmit($claim);

        $this->assertArrayHasKey('errors', $response);
        $this->assertArrayNotHasKey('correlationid', $response);
        $this->assertArrayNotHasKey('endpoint', $response);
        $this->assertArrayNotHasKey('interval', $response);

        $this->assertCount(0, $response['errors']['fatal']);
        $this->assertCount(0, $response['errors']['recoverable']);
        $this->assertCount(0, $response['errors']['warning']);
        $this->assertCount(4, $response['errors']['business']);

        $this->assertEquals(
            "Invalid content found at element 'Sur'",
            $response['errors']['business'][1]['text'],
        );

        $this->assertEquals('some-uuid-1234', $response['errors']['business'][1]['donation_id']);
        $this->assertEquals('some-uuid-1234', $response['errors']['business'][2]['donation_id']);
        $this->assertEquals('some-uuid-1234', $response['errors']['business'][3]['donation_id']);
        $this->assertEquals('some-uuid-1234', $response['errors']['business'][4]['donation_id']);

        $this->assertEquals(['some-uuid-1234'], $response['donation_ids_with_errors']);
    }

    public function testDeclarationResponsePoll()
    {
        $this->setMockHttpResponse('DeclarationResponsePoll.xml');
        $this->gaService = $this->setUpService(); // Use client w/ mock queue.

        $response = $this->gaService->declarationResponsePoll(
            'A19FA1A31BCB42D887EA323292AACD88',
            'https://secure.dev.gateway.gov.uk/poll'
        );

        $this->assertArrayNotHasKey('errors', $response);
        //$this->assertSame('response', $this->gaService->getResponseQualifier());
        $this->assertArrayHasKey('correlationid', $response);
        $this->assertSame('A19FA1A31BCB42D887EA323292AACD88', $response['correlationid']);
    }

    public function testRequestClaimData()
    {
        $this->setMockHttpResponse('RequestClaimDataResponse.xml');
        $this->gaService = $this->setUpService(); // Use client w/ mock queue.

        $this->gaService->setAuthorisedOfficial($this->officer);
        $this->gaService->setClaimingOrganisation($this->claimant);
        $response = $this->gaService->requestClaimData();

        $this->assertArrayNotHasKey('errors', $response);
    }

    public function testDeleteRequest()
    {
        $this->setMockHttpResponse('DeleteResponse.xml');
        $this->gaService = $this->setUpService(); // Use client w/ mock queue.

        $this->gaService->setAuthorisedOfficial($this->officer);
        $this->gaService->setClaimingOrganisation($this->claimant);
        $response = $this->gaService->sendDeleteRequest(
            'BE6622CBCA354E77A5A10BC24C29A0A7',
            'HMRC-CHAR-CLM'
        );

        $this->assertTrue($response);
    }

    private function setUpService(): GiftAid
    {
        return new GiftAid(
            $this->gatewayUserID,
            $this->gatewayUserPassword,
            $this->gatewayVendorID,
            $this->gatewaySoftware,
            $this->gatewaySoftVersion,
            true,
            $this->getHttpClient()
        );
    }

    private function addValidTestAgent(GiftAid $giftAidService): GiftAid
    {
        $agentContact = [
            'name' => [
                'title' => 'Mx',
                'forename' => 'Billie',
                'surname' => 'Bravo',
            ],
            'email' => 'billie@example.org',
        ];

        $giftAidService->setAgentDetails(
            '11111222223333',
            'AgentCo',
            [
                'line' => [
                    'AgentAddr 1',
                    'AgentAddr 2',
                ],
            ],
            $agentContact
        );

        return $giftAidService;
    }
}
