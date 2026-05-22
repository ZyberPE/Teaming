<?php

declare(strict_types=1);

namespace Teaming;

use pocketmine\player\Player;

class TeamManager{

    private Main $plugin;

    private array $teams = [];
    private array $playerTeams = [];
    private array $owners = [];
    private array $invites = [];

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    public function createTeam(Player $player, string $team) : string{

        if(isset($this->playerTeams[strtolower($player->getName())])){
            return "already";
        }

        if(isset($this->teams[$team])){
            return "exists";
        }

        $this->teams[$team] = [strtolower($player->getName())];

        $this->playerTeams[strtolower($player->getName())] = $team;

        $this->owners[$team] = strtolower($player->getName());

        $this->updateNameTag($player);

        return "success";
    }

    public function invite(Player $owner, Player $target) : void{
        $this->invites[strtolower($target->getName())] = strtolower($owner->getName());
    }

    public function acceptInvite(Player $player) : bool{

        $name = strtolower($player->getName());

        if(!isset($this->invites[$name])){
            return false;
        }

        $owner = $this->invites[$name];

        if(!isset($this->playerTeams[$owner])){
            return false;
        }

        $team = $this->playerTeams[$owner];

        $this->teams[$team][] = $name;

        $this->playerTeams[$name] = $team;

        unset($this->invites[$name]);

        return true;
    }

    public function leaveTeam(Player $player) : void{

        $name = strtolower($player->getName());

        if(!isset($this->playerTeams[$name])){
            return;
        }

        $team = $this->playerTeams[$name];

        unset($this->playerTeams[$name]);

        $this->teams[$team] = array_filter(
            $this->teams[$team],
            fn(string $member) => $member !== $name
        );

        $player->setNameTag($player->getName());
    }

    public function deleteTeam(Player $player) : void{

        $team = $this->getTeam($player->getName());

        if($team === null){
            return;
        }

        foreach($this->playerTeams as $member => $memberTeam){

            if($memberTeam === $team){

                unset($this->playerTeams[$member]);

                $online = $this->plugin->getServer()->getPlayerExact($member);

                if($online !== null){
                    $online->setNameTag($online->getName());
                }
            }
        }

        unset($this->teams[$team]);
        unset($this->owners[$team]);
    }

    public function hasTeam(string $player) : bool{
        return isset($this->playerTeams[strtolower($player)]);
    }

    public function getTeam(string $player) : ?string{
        return $this->playerTeams[strtolower($player)] ?? null;
    }

    public function sameTeam(string $player1, string $player2) : bool{

        $team1 = $this->getTeam($player1);
        $team2 = $this->getTeam($player2);

        return $team1 !== null && $team1 === $team2;
    }

    public function isOwner(string $player) : bool{

        $team = $this->getTeam($player);

        if($team === null){
            return false;
        }

        return $this->owners[$team] === strtolower($player);
    }

    public function updateNameTag(Player $player) : void{

        if(!$this->plugin->getConfig()->getNested("nametag.enabled")){
            return;
        }

        $team = $this->getTeam($player->getName());

        if($team === null){
            return;
        }

        $format = $this->plugin->getConfig()->getNested("nametag.format");

        $tag = str_replace(
            ["{TEAM}", "{PLAYER}"],
            [$team, $player->getName()],
            $format
        );

        $player->setNameTag($tag);
    }
}
