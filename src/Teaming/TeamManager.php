<?php

declare(strict_types=1);

namespace Teaming;

use pocketmine\player\Player;
use pocketmine\world\Position;

class TeamManager{

    private Main $plugin;

    private array $teams = [];
    private array $playerTeams = [];
    private array $owners = [];
    private array $invites = [];
    private array $homes = [];

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    public function createTeam(Player $player, string $team) : string{

        if(isset($this->teams[$team])){
            return "team-exists";
        }

        if($this->hasTeam($player->getName())){
            return "already-team";
        }

        $this->teams[$team] = [strtolower($player->getName())];

        $this->playerTeams[strtolower($player->getName())] = $team;

        $this->owners[$team] = strtolower($player->getName());

        $this->updateNameTag($player);

        return "success";
    }

    public function invite(Player $owner, Player $target) : string{

        $team = $this->getTeam($owner->getName());

        if($team === null){
            return "no-team";
        }

        if(count($this->teams[$team]) >= $this->plugin->getConfig()->get("max-team-size")){
            return "team-full";
        }

        $this->invites[strtolower($target->getName())] = strtolower($owner->getName());

        $target->sendMessage(
            str_replace(
                ["{TEAM}", "{PLAYER}"],
                [$team, $owner->getName()],
                $this->plugin->msg("invited")
            )
        );

        return "success";
    }

    public function acceptInvite(Player $player) : bool{

        $name = strtolower($player->getName());

        if(!isset($this->invites[$name])){
            return false;
        }

        $owner = $this->invites[$name];

        $team = $this->playerTeams[$owner];

        $this->teams[$team][] = $name;

        $this->playerTeams[$name] = $team;

        unset($this->invites[$name]);

        $this->updateNameTag($player);

        foreach($this->teams[$team] as $member){

            $online = $this->plugin->getServer()->getPlayerExact($member);

            if($online !== null){

                $online->sendMessage(
                    str_replace(
                        "{PLAYER}",
                        $player->getName(),
                        $this->plugin->msg("joined-team")
                    )
                );
            }
        }

        return true;
    }

    public function leaveTeam(Player $player) : void{

        $name = strtolower($player->getName());

        $team = $this->playerTeams[$name];

        unset($this->playerTeams[$name]);

        $this->teams[$team] = array_filter(
            $this->teams[$team],
            fn(string $member) => $member !== $name
        );

        foreach($this->teams[$team] as $member){

            $online = $this->plugin->getServer()->getPlayerExact($member);

            if($online !== null){

                $online->sendMessage(
                    str_replace(
                        "{PLAYER}",
                        $player->getName(),
                        $this->plugin->msg("left-team")
                    )
                );
            }
        }

        $player->setNameTag($player->getName());
    }

    public function deleteTeam(Player $player) : void{

        $team = $this->getTeam($player->getName());

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
        unset($this->homes[$team]);
    }

    public function kickPlayer(Player $leader, Player $target) : bool{

        if(!$this->isOwner($leader->getName())){
            return false;
        }

        if(!$this->sameTeam($leader->getName(), $target->getName())){
            return false;
        }

        $name = strtolower($target->getName());

        $team = $this->playerTeams[$name];

        unset($this->playerTeams[$name]);

        $this->teams[$team] = array_filter(
            $this->teams[$team],
            fn(string $member) => $member !== $name
        );

        foreach($this->teams[$team] as $member){

            $online = $this->plugin->getServer()->getPlayerExact($member);

            if($online !== null){

                $online->sendMessage(
                    str_replace(
                        "{PLAYER}",
                        $target->getName(),
                        $this->plugin->msg("kicked-player")
                    )
                );
            }
        }

        $target->sendMessage($this->plugin->msg("kicked-message"));

        $target->setNameTag($target->getName());

        return true;
    }

    public function getMembers(Player $player) : array{

        $team = $this->getTeam($player->getName());

        return $this->teams[$team] ?? [];
    }

    public function setHome(Player $player) : void{
        $this->homes[$this->getTeam($player->getName())] = $player->getPosition();
    }

    public function teleportHome(Player $player) : bool{

        $team = $this->getTeam($player->getName());

        if(!isset($this->homes[$team])){
            return false;
        }

        $player->teleport($this->homes[$team]);

        return true;
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

        return $team !== null && $this->owners[$team] === strtolower($player);
    }

    public function updateNameTag(Player $player) : void{

        $health = (int) round($player->getHealth());

        $team = $this->getTeam($player->getName());

        if($team === null){

            $player->setNameTag(
                "§a{$health} HP\n§f" . $player->getName()
            );

            return;
        }

        $format = $this->plugin->getConfig()->getNested("nametag.format");

        $tag = str_replace(
            ["{TEAM}", "{PLAYER}"],
            [$team, $player->getName()],
            $format
        );

        $player->setNameTag(
            $tag . "\n§a{$health} HP"
        );
    }
}
