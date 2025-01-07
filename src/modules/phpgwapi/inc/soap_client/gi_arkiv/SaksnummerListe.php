<?php

class SaksnummerListe
{

    /**
     * @var Saksnummer[] $liste
     */
    protected $liste = null;

    
    public function __construct()
    {
    
    }

    /**
     * @return Saksnummer[]
     */
    public function getListe()
    {
      return $this->liste;
    }

    /**
     * @param Saksnummer[] $liste
     * @return SaksnummerListe
     */
    public function setListe(array|null $liste = null)
    {
      $this->liste = $liste;
      return $this;
    }

}
