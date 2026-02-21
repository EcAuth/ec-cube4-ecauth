<?php

namespace Plugin\EcAuthLogin43\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Annotation\EntityExtension;

/**
 * @EntityExtension("Eccube\Entity\Member")
 */
trait MemberTrait
{
    /**
     * @var string|null
     *
     * @ORM\Column(name="ecauth_subject", type="string", length=255, nullable=true)
     */
    private $ecauth_subject;

    /**
     * @return string|null
     */
    public function getEcauthSubject()
    {
        return $this->ecauth_subject;
    }

    /**
     * @param string|null $ecauth_subject
     *
     * @return $this
     */
    public function setEcauthSubject($ecauth_subject)
    {
        $this->ecauth_subject = $ecauth_subject;

        return $this;
    }
}
