<?php

declare(strict_types=1);

namespace Teaming;

use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\world\Position;

class TeamManager{

    private Main $plugin;
    private Config $data;

    private array $teams = [];
    private array $invites = [];

    public function __construct(Main $plugin){

        $this->plugin = $plugin;

        @mkdir($plugin->getDataFolder());

        $this->data = new Config(
            $plugin->getDataFolder() . "teams.yml",
            Config::YAML
        );

        $this->teams = $this->data->getAll();
    }

    public function saveData() : void{
        $this->data->setAll($this->teams);
        $this->data->save();
    }

    public function createTeam(Player $owner, string $team) : string{

        if($this->hasTeam($owner->getName())){
            return "already-team";
        }

        /*
         Allow reuse of deleted names
        */

        if(isset($this->teams[$team]) && !empty($this->teams[$team])){
            return "team-exists";
        }

        $this->teams[$team] = [
            "owner" => $owner->getName(),
            "members" => [$owner->getName()],
            "home" => null
        ];

        $this->saveData();

        $this->updateNameTag($owner);

        return "success";
    }

    public function invite(Player $owner, Player $target) : string{

        $team = $this->getTeam($owner->getName());

        if($team === null){
            return "no-team";
        }

        if(!$this->isOwner($owner->getName())){
            return "not-leader";
        }

        if($this->hasTeam($target->getName())){
            return "already-team";
        }

        if(count($this->teams[$team]["members"]) >= $this->plugin->getConfig()->get("max-team-size")){
            return "team-full";
        }

        $this->invites[$target->getName()] = $team;

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

        if(!isset($this->invites[$player->getName()])){
            return false;
        }

        $team = $this->invites[$player->getName()];

        unset($this->invites[$player->getName()]);

        $this->teams[$team]["members"][] = $player->getName();

        $this->saveData();

        $this->updateNameTag($player);

        foreach($this->teams[$team]["members"] as $member){

            $online = $this->plugin->getServer()->getPlayerExact($member);

            if($online instanceof Player){

                $online->sendMessage(
                    str_replace(
                        "{PLAYER}",
                        $player->getName(),
                        $this->plugin->msg("joined-team")
                    )
                );

                $this->updateNameTag($online);
            }
        }

        return true;
    }

    public function leaveTeam(Player $player) : void{

        $team = $this->getTeam($player->getName());

        if($team === null){
            return;
        }

        $key = array_search(
            $player->getName(),
            $this->teams[$team]["members"],
            true
        );

        if($key !== false){
            unset($this->teams[$team]["members"][$key]);
        }

        $this->teams[$team]["members"] = array_values(
            $this->teams[$team]["members"]
        );

        $this->saveData();

        $this->updateNameTag($player);
    }

    public function deleteTeam(Player $owner) : void{

        $team = $this->getTeam($owner->getName());

        if($team === null){
            return;
        }

        foreach($this->teams[$team]["members"] as $member){

            $online = $this->plugin->getServer()->getPlayerExact($member);

            if($online instanceof Player){
                $this->updateNameTag($online);
            }
        }

        /*
         FULL DELETE
         Allows recreating same name
        */

        unset($this->teams[$team]);

        $this->saveData();
    }

    public function kickPlayer(Player $owner, Player $target) : bool{

        $team = $this->getTeam($owner->getName());

        if($team === null){
            return false;
        }

        if(!$this->isOwner($owner->getName())){
            return false;
        }

        if(!$this->sameTeam($owner->getName(), $target->getName())){
            return false;
        }

        /*
         Cannot kick self
        */

        if(strtolower($owner->getName()) === strtolower($target->getName())){

            $owner->sendMessage(
                $this->plugin->msg("cannot-kick-self")
            );

            return true;
        }

        $key = array_search(
            $target->getName(),
            $this->teams[$team]["members"],
            true
        );

        if($key !== false){
            unset($this->teams[$team]["members"][$key]);
        }

        $this->teams[$team]["members"] = array_values(
            $this->teams[$team]["members"]
        );

        $this->saveData();

        $target->sendMessage(
            $this->plugin->msg("kicked-message")
        );

        $owner->sendMessage(
            str_replace(
                "{PLAYER}",
                $target->getName(),
                $this->plugin->msg("kicked-player")
            )
        );

        $this->updateNameTag($target);

        return true;
    }
}
