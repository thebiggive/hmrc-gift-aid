<?php

namespace GovTalk\GiftAid;

/**
 * The base class for all GovTalk\GiftAid\Individual tests
 */
class IndividualTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->individual = new Individual(
            'Mr',
            'Rex',
            'Muck',
            '077 1234 5678',
            '3',
            'SW1A 1AA'
        );

        $this->foreign = new Individual(
            'Ds',
            'Johannes',
            'Doper',
            '011 452 1256',
            '27',
            '',
            true
        );
    }

    public function testIndividualCreation()
    {
        $this->assertEquals($this->individual->getTitle(), 'Mr');
        $this->assertEquals($this->individual->getSurname(), 'Muck');
        $this->assertEquals($this->individual->getForename(), 'Rex');
        $this->assertEquals($this->individual->getPhone(), '077 1234 5678');
        $this->assertEquals($this->individual->getHouseNum(), '3');
        $this->assertEquals($this->individual->getPostcode(), 'SW1A 1AA');
        $this->assertEquals($this->individual->getIsOverseas(), 'no');
    }

    public function testIndividualCreationWithLongNameAndAddressValues()
    {
        $testIndividual = new Individual(
            title: '',
            name: str_repeat('a', 36),
            surname: str_repeat('b', 36),
            houseNum: str_repeat('c', 41),
            postcode: 'N11XX',
            phone: '07777 777777',
        );

        $this->assertEquals(35, strlen($testIndividual->getForename()));
        $this->assertEquals(35, strlen($testIndividual->getSurname()));
        $this->assertEquals(40, strlen($testIndividual->getHouseNum()));
    }

    public function testForeignIndividualCreation()
    {
        $this->assertEquals($this->foreign->getTitle(), 'Ds');
        $this->assertEquals($this->foreign->getSurname(), 'Doper');
        $this->assertEquals($this->foreign->getForename(), 'Johannes');
        $this->assertEquals($this->foreign->getPhone(), '011 452 1256');
        $this->assertEquals($this->foreign->getHouseNum(), '27');
        $this->assertEquals($this->foreign->getPostcode(), '');
        $this->assertEquals($this->foreign->getIsOverseas(), 'yes');
    }

    public function testUpdateIndividual()
    {
        $this->individual->setTitle('Mrs');
        $this->individual->setSurname('Malady');
        $this->individual->setForename('Regina');
        $this->individual->setPhone('020 8765 4321');
        $this->individual->setHouseNum('2');
        $this->individual->setPostcode('NW1A 1AA');
        $this->individual->setIsOverseas(false);

        $this->assertEquals($this->individual->getTitle(), 'Mrs');
        $this->assertEquals($this->individual->getSurname(), 'Malady');
        $this->assertEquals($this->individual->getForename(), 'Regina');
        $this->assertEquals($this->individual->getPhone(), '020 8765 4321');
        $this->assertEquals($this->individual->getHouseNum(), '2');
        $this->assertEquals($this->individual->getPostcode(), 'NW1A 1AA');
        $this->assertEquals($this->individual->getIsOverseas(), 'no');
    }
}
