<?php

namespace Plugin\EcAuthLogin43\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Config
 *
 * @ORM\Table(name="plg_ecauth_login43_config")
 * @ORM\Entity(repositoryClass="Plugin\EcAuthLogin43\Repository\ConfigRepository")
 */
class Config
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string|null
     *
     * @ORM\Column(name="ecauth_base_url", type="string", length=1024, nullable=true)
     */
    private $ecauth_base_url;

    /**
     * @var string|null
     *
     * @ORM\Column(name="client_id", type="string", length=255, nullable=true)
     */
    private $client_id;

    /**
     * @var string|null
     *
     * @ORM\Column(name="client_secret", type="string", length=255, nullable=true)
     */
    private $client_secret;

    /**
     * @var string|null
     *
     * @ORM\Column(name="rp_id", type="string", length=255, nullable=true)
     */
    private $rp_id;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string|null
     */
    public function getEcauthBaseUrl()
    {
        return $this->ecauth_base_url;
    }

    /**
     * @param string|null $ecauth_base_url
     *
     * @return $this
     */
    public function setEcauthBaseUrl($ecauth_base_url)
    {
        $this->ecauth_base_url = $ecauth_base_url;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getClientId()
    {
        return $this->client_id;
    }

    /**
     * @param string|null $client_id
     *
     * @return $this
     */
    public function setClientId($client_id)
    {
        $this->client_id = $client_id;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getClientSecret()
    {
        return $this->client_secret;
    }

    /**
     * @param string|null $client_secret
     *
     * @return $this
     */
    public function setClientSecret($client_secret)
    {
        $this->client_secret = $client_secret;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getRpId()
    {
        return $this->rp_id;
    }

    /**
     * @param string|null $rp_id
     *
     * @return $this
     */
    public function setRpId($rp_id)
    {
        $this->rp_id = $rp_id;

        return $this;
    }
}
