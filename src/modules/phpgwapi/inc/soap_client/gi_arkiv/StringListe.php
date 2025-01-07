<?php

class StringListe
{

    /**
     * @var string[] $liste
     */
    protected $liste = null;

    
    public function __construct()
    {
    
    }

    /**
     * @return string[]
     */
    public function getListe()
    {
      return $this->liste;
    }

    /**
     * @param string[] $liste
     * @return StringListe
     */
    public function setListe(array|null $liste = null)
    {
      $this->liste = $liste;
      return $this;
    }

}
