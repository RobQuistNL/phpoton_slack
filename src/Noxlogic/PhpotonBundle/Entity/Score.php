<?php

namespace Noxlogic\PhpotonBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Score
 *
 * @ORM\Table()
 * @ORM\Entity(repositoryClass="Noxlogic\PhpotonBundle\Entity\ScoreRepository")
 */
class Score
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=20);
     */
    private $user;

    /**
     * @ORM\Column(type="datetime");
     */
    private $created_dt;

    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set user
     *
     * @param string $user
     * @return Score
     */
    public function setUser($user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user
     *
     * @return string 
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set created_dt
     *
     * @param \DateTime $createdDt
     * @return Score
     */
    public function setCreatedDt($createdDt)
    {
        $this->created_dt = $createdDt;

        return $this;
    }

    /**
     * Get created_dt
     *
     * @return \DateTime 
     */
    public function getCreatedDt()
    {
        return $this->created_dt;
    }
}
