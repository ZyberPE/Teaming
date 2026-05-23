<?php

declare(strict_types=1);

namespace Teaming;

use pocketmine\player\Player;
use pocketmine\utils\Config;
use _64FF00\PureChat\PureChat;

class TeamManager{

    private Main $plugin;
    private Config $data;

    private array $teams = [];
    private array $invites = [];

    public function __construct(Main $plugin){

        $this->plugin = $plugin;

        $this->data = new Config(
            $plugin->getDataFolder() . "teams.yml",
            Config::YAML
        );

        $this->teams = $this->data->getAll();
    }

    public function save() : void{

        $this->data->setAll($this->teams);

        $this->data->save();
    }

    public function hasTeam(string $player) : bool{

        return $this->getTeam($player) !== null;
    }

    public function getTeam(string $player) : ?string{

        foreach($this->teams as $team => $data){

            if(in_array($player, $data["members"])){
                return $team;
            }
        }

        return null;
    }

    public function sameTeam(string $p1, string $p2) : bool{

        return $this->getTeam($p1) !== null &&
               $this->getTeam($p1) === $this->getTeam($p2);
    }

    public function isOwner(string $player) : bool{

        $team = $this->getTeam($player);

        if($team === null){
            return false;
        }

        return $this->teams[$team]["owner"] === $player;
    }

    public function createTeam(Player $owner, string $team) : string{

        if($this->hasTeam($owner->getName())){
            return "already-team";
        }

        if(isset($this->teams[$team])){
            return "team-exists";
        }

        $this->teams[$team] = [
            "owner" => $owner->getName(),
            "members" => [$owner->getName()]
        ];

        $this->save();

        $this->updateNameTag($owner);

        return "success";
    }

    public function invite(Player $owner, Player $target) : string{

        if(!$this->isOwner($owner->getName())){
            return "not-leader";
        }

        $team = $this->getTeam($owner->getName());

        if($team === null){
            return "no-team";
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

        $this->teams[$team]["members"][] = $player->getName();

        unset($this->invites[$player->getName()]);

        $this->save();

        $this->updateNameTag($player);

        return true;
    }

    public function leaveTeam(Player $player) : void{

        $team = $this->getTeam($player->getName());

        if($team === null){
            return;
        }

        $key = array_search(
            $player->getName(),
            $this->teams[$team]["members"]
        );

        unset($this->teams[$team]["members"][$key]);

        $this->teams[$team]["members"] = array_values(
            $this->teams[$team]["members"]
        );

        $this->save();

        $this->plugin->disableTeamChat($player);

        $this->updateNameTag($player);
    }

    public function deleteTeam(Player $owner) : void{

        $team = $this->getTeam($owner->getName());

        if($team === null){
            return;
        }

        foreach($this->teams[$team]["members"] as $member){

            $online = $this->plugin
                ->getServer()
                ->getPlayerExact($member);

            if($online instanceof Player){

                $this->plugin->disableTeamChat($online);

                $this->updateNameTag($online);

                $online->sendMessage(
                    $this->plugin->msg("team-deleted")
                );
            }
        }

        unset($this->teams[$team]);

        $this->save();
    }

    public function kickPlayer(Player $owner, Player $target) : void{

        $team = $this->getTeam($owner->getName());

        if($team === null){
            return;
        }

        if(strtolower($owner->getName()) === strtolower($target->getName())){

            $owner->sendMessage(
                $this->plugin->msg("cannot-kick-self")
            );

            return;
        }

        $key = array_search(
            $target->getName(),
            $this->teams[$team]["members"]
        );

        unset($this->teams[$team]["members"][$key]);

        $this->teams[$team]["members"] = array_values(
            $this->teams[$team]["members"]
        );

        $this->save();

        $this->plugin->disableTeamChat($target);

        $this->updateNameTag($target);
    }

    public function updateNameTag(Player $player) : void{

        if(!$this->plugin->getConfig()->getNested(
            "nametag.enabled"
        )){
            return;
        }

        $team = $this->getTeam(
            $player->getName()
        );

        $health = round(
            $player->getHealth()
        );

        $rank = "";

        $pureChat = $this->plugin
            ->getServer()
            ->getPluginManager()
            ->getPlugin("PureChat");

        if($pureChat instanceof PureChat){

            try{

                $rank = $pureChat->getNametag(
                    $player
                );

            }catch(\Throwable $e){

                $rank = "";
            }
        }

        if($team !== null){

            $format = $this->plugin
                ->getConfig()
                ->getNested(
                    "nametag.team-format"
                );

            $tag = str_replace(
                [
                    "{TEAM}",
                    "{PLAYER}",
                    "{HEALTH}",
                    "{RANK}"
                ],
                [
                    $team,
                    $player->getName(),
                    $health,
                    $rank
                ],
                $format
            );

        }else{

            $format = $this->plugin
                ->getConfig()
                ->getNested(
                    "nametag.no-team-format"
                );

            $tag = str_replace(
                [
                    "{PLAYER}",
                    "{HEALTH}",
                    "{RANK}"
                ],
                [
                    $player->getName(),
                    $health,
                    $rank
                ],
                $format
            );
        }

        $player->setNameTagAlwaysVisible(true);

        $player->setNameTag($tag);
    }
}
