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
     * EcAuth 側の b2b_subject を保持する。複数 Member に同一 subject を紐付けると
     * id_token の sub で Member を引いた際に別人セッションが張られる危険があるため、
     * UNIQUE 制約で物理的に矛盾を起こせないようにしている（null は複数許容）。
     *
     * @ORM\Column(name="ecauth_subject", type="string", length=255, nullable=true, unique=true)
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
