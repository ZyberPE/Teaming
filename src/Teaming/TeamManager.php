<?php

declare(strict_types=1);

namespace Teaming;

use pocketmine\player\Player;
use pocketmine\utils\Config;

class TeamManager{

    private Main $plugin;

    private Config $data;

    private array $teams = [];

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

    public function createTeam(Player $player, string $team) : bool{

        if(isset($this->teams[$team])){
            return false;
        }

        $this->teams[$team] = [
            "leader" => $player->getName(),
            "members" => [$player->getName()],
            "home" => null
        ];

        $this->save();

        return true;
    }

    public function deleteTeam(Player $player) : void{

        $team = $this->getTeam($player->getName());

        if($team !== null){

            unset($this->teams[$team]);

            $this->save();
        }
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

        if($key !== false){

            unset($this->teams[$team]["members"][$key]);

            $this->teams[$team]["members"] = array_values(
                $this->teams[$team]["members"]
            );

            $this->save();
        }
    }

    public function getTeam(string $player) : ?string{

        foreach($this->teams as $team => $data){

            if(in_array($player, $data["members"])){
                return $team;
            }
        }

        return null;
    }

    public function hasTeam(string $player) : bool{
        return $this->getTeam($player) !== null;
    }

    public function isLeader(string $player) : bool{

        $team = $this->getTeam($player);

        if($team === null){
            return false;
        }

        return $this->teams[$team]["leader"] === $player;
    }

    public function sameTeam(string $p1, string $p2) : bool{

        $team1 = $this->getTeam($p1);
        $team2 = $this->getTeam($p2);

        return $team1 !== null && $team1 === $team2;
    }

    public function updateNameTag(Player $player) : void{

        if(!$this->plugin->getConfig()->getNested("nametag.enabled")){
            return;
        }

        $team = $this->getTeam($player->getName()) ?? "NoTeam";

        $health = round($player->getHealth());

        $rank = "";

        $pureChat = $this->plugin
            ->getServer()
            ->getPluginManager()
            ->getPlugin("PureChat");

        if($pureChat !== null){

            try{

                $group = $pureChat
                    ->getUserDataMgr()
                    ->getGroup($player);

                if($group !== null){
                    $rank = "§r" . $group->getName();
                }

            }catch(\Throwable $e){
                $rank = "";
            }
        }

        if($team !== "NoTeam"){

            $format = $this->plugin
                ->getConfig()
                ->getNested("nametag.team-format");

        }else{

            $format = $this->plugin
                ->getConfig()
                ->getNested("nametag.no-team-format");
        }

        $tag = str_replace(
            ["{TEAM}", "{PLAYER}", "{HEALTH}", "{RANK}"],
            [$team, $player->getName(), $health, $rank],
            $format
        );

        $player->setNameTagAlwaysVisible(true);

        $player->setNameTag($tag);
    }
}
