<?php

namespace GovTalk\GiftAid;

class ClaimingOrganisation
{
    private $name = '';
    private $hmrcRef = '';
    private ?string $regulator;
    private $regNo = '';
    private $hasConnectedCharities = false;
    private $connectedCharities = [];
    private $useCommunityBuildings = false;

    /**
     * @param ?string $name
     * @param ?string $hmrcRef  In Test mode – targeting the real Reflector server – this
     *                          should be AB12345 unless advised of another value by HMRC.
     * @param ?string $regulator
     * @param ?string $regNo
     */
    public function __construct(
        ?string $name = null,
        ?string $hmrcRef = null,
        ?string $regulator = null,
        ?string $regNo = null
    ) {
        $this->name = $name;
        $this->hmrcRef = $hmrcRef;
        $this->regulator = $regulator;
        $this->regNo = $regNo;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($value)
    {
        $this->name = $value;
    }

    public function getHmrcRef()
    {
        return $this->hmrcRef;
    }

    public function setHmrcRef($value)
    {
        $this->hmrcRef = $value;
    }

    public function getRegulator()
    {
        return $this->regulator;
    }

    /**
     * @param string|null $regulator    Null for exempt orgs
     */
    public function setRegulator(?string $regulator): void
    {
        $this->regulator = $regulator;
    }

    public function getRegNo()
    {
        return $this->regNo;
    }

    public function setRegNo($value)
    {
        $this->regNo = $value;
    }

    public function getHasConnectedCharities()
    {
        return $this->hasConnectedCharities;
    }

    public function setHasConnectedCharities($value)
    {
        if (is_bool($value)) {
            $this->hasConnectedCharities = $value;
        } else {
            $this->hasConnectedCharities = false;
        }
    }

    public function getConnectedCharities()
    {
        return $this->connectedCharities;
    }

    public function addConnectedCharity(ClaimingOrganisation $connectedCharity)
    {
        $this->connectedCharities[] = $connectedCharity;
    }

    public function clearConnectedCharities()
    {
        $this->connectedCharities = [];
    }

    public function getUseCommunityBuildings()
    {
        return $this->useCommunityBuildings;
    }

    public function setUseCommunityBuildings($value)
    {
        if (is_bool($value)) {
            $this->useCommunityBuildings = $value;
        } else {
            $this->useCommunityBuildings = false;
        }
    }

    public function hasStandardRegulator(): bool
    {
        return in_array($this->regulator, ['CCEW', 'CCNI', 'OSCR'], true);
    }
}
