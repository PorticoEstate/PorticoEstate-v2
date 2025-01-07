<?php

class ArkivdelListe
{

    /**
     * @var Arkivdel[] $liste
     */
    protected $liste = null;

    
    public function __construct()
    {
    
    }

    /**
     * @return Arkivdel[]
     */
    public function getListe()
    {
      return $this->liste;
    }

    /**
     * @param Arkivdel[] $liste
     * @return ArkivdelListe
     */
    public function setListe(array|null $liste = null)
    {
      $this->liste = $liste;
      return $this;
    }

}
