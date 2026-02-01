<?php

namespace Plugin\EcAuthLogin43\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Eccube\Repository\AbstractRepository;
use Plugin\EcAuthLogin43\Entity\Config;

class ConfigRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Config::class);
    }

    /**
     * @param int $id
     *
     * @return Config|null
     */
    public function get($id = 1)
    {
        return $this->find($id);
    }
}
