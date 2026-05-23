<?php

declare(strict_types=1);

namespace Teaming;

use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\world\Position;

class TeamManager{

    private Main $plugin;
    private Config $data;

    /** @var array<string, array> */
    private array $teams = [];

    /** @var array<string, string> */
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

    /*
     CREATE TEAM
    */
    public function createTeam(Player $owner, string $team) : string{

        if($this->hasTeam($owner->getName())){
            return "already-team";
        }

        if(isset($this->teams[$team])){
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

    /*
     INVITE
    */
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

    /*
     ACCEPT INVITE
    */
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
                    str_replace("{PLAYER}", $player->getName(), $this->plugin->msg("joined-team"))
                );
                $this->updateNameTag($online);
            }
        }

        return true;
    }

    /*
     LEAVE
    */
    public function leaveTeam(Player $player) : void{

        $team = $this->getTeam($player->getName());

        if($team === null){
            return;
        }

        $key = array_search($player->getName(), $this->teams[$team]["members"], true);

        if($key !== false){
            unset($this->teams[$team]["members"][$key]);
        }

        $this->teams[$team]["members"] = array_values($this->teams[$team]["members"]);

        $this->saveData();
        $this->updateNameTag($player);

        foreach($this->teams[$team]["members"] as $member){

            $online = $this->plugin->getServer()->getPlayerExact($member);

            if($online instanceof Player){
                $online->sendMessage(
                    str_replace("{PLAYER}", $player->getName(), $this->plugin->msg("left-team"))
                );
            }
        }
    }

    /*
     DELETE
    */
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

        unset($this->teams[$team]);

        $this->saveData();
    }

    /*
     KICK
    */
    public function kickPlayer(Player $owner, Player $target) : bool{

        $team = $this->getTeam($owner->getName());

        if($team === null || !$this->isOwner($owner->getName())){
            return false;
        }

        if(!$this->sameTeam($owner->getName(), $target->getName())){
            return false;
        }

        $key = array_search($target->getName(), $this->teams[$team]["members"], true);

        if($key !== false){
            unset($this->teams[$team]["members"][$key]);
        }

        $this->teams[$team]["members"] = array_values($this->teams[$team]["members"]);

        $this->saveData();

        $target->sendMessage($this->plugin->msg("kicked-message"));
        $owner->sendMessage(
            str_replace("{PLAYER}", $target->getName(), $this->plugin->msg("kicked-player"))
        );

        $this->updateNameTag($target);

        return true;
    }

    /*
     HOME
    */
    public function setHome(Player $player) : void{

        $team = $this->getTeam($player->getName());
        if($team === null) return;

        $this->teams[$team]["home"] = [
            "x" => $player->getPosition()->getX(),
            "y" => $player->getPosition()->getY(),
            "z" => $player->getPosition()->getZ(),
            "world" => $player->getWorld()->getFolderName()
        ];

        $this->saveData();
    }

    public function teleportHome(Player $player) : bool{

        $team = $this->getTeam($player->getName());
        if($team === null) return false;

        $home = $this->teams[$team]["home"];

        if($home === null) return false;

        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($home["world"]);

        if($world === null) return false;

        $player->teleport(new Position($home["x"], $home["y"], $home["z"], $world));

        return true;
    }

    /*
     LOOKUPS
    */
    public function hasTeam(string $player) : bool{
        return $this->getTeam($player) !== null;
    }

    public function getTeam(string $player) : ?string{

        foreach($this->teams as $team => $data){

            if(in_array($player, $data["members"], true)){
                return $team;
            }
        }

        return null;
    }

    public function sameTeam(string $p1, string $p2) : bool{

        $t1 = $this->getTeam($p1);
        return $t1 !== null && $t1 === $this->getTeam($p2);
    }

    public function isOwner(string $player) : bool{

        $team = $this->getTeam($player);

        if($team === null) return false;

        return $this->teams[$team]["owner"] === $player;
    }

    public function getMembers(Player $player) : array{

        $team = $this->getTeam($player->getName());

        return $team === null ? [] : $this->teams[$team]["members"];
    }

    /*
     NAMETAGS (USED BY MAIN)
    */
    public function updateNameTag(Player $player) : void{

        $health = (int) round($player->getHealth());

        $team = $this->getTeam($player->getName());

        if($team === null){

            $format = $this->plugin->getConfig()->getNested("nametag.no-team-format");

            $player->setNameTag(str_replace("{PLAYER}", $player->getName(), $format) . "\n§a{$health} HP");
            return;
        }

        $format = $this->plugin->getConfig()->getNested("nametag.team-format");

        $player->setNameTag(
            str_replace(
                ["{TEAM}", "{PLAYER}"],
                [$team, $player->getName()],
                $format
            ) . "\n§a{$health} HP"
        );
    }
}
