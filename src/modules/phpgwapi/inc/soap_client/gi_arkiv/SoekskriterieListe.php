<?php

class SoekskriterieListe
{

    /**
     * @var Soekskriterie[] $liste
     */
    protected $liste = null;

    
    public function __construct()
    {
    
    }

    /**
     * @return Soekskriterie[]
     */
    public function getListe()
    {
      return $this->liste;
    }

    /**
     * @param Soekskriterie[] $liste
     * @return SoekskriterieListe
     */
    public function setListe(array|null $liste = null)
    {
      $this->liste = $liste;
      return $this;
    }

}
