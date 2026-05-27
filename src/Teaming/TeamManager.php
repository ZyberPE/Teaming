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

    public function teamExists(string $team) : bool{
        return isset($this->teams[$team]);
    }

    public function createTeam(Player $player, string $team) : bool{

        if($this->teamExists($team)){
            return false;
        }

        $this->teams[$team] = [
            "leader" => $player->getName(),
            "members" => [
                $player->getName()
            ],
            "home" => null
        ];

        $this->save();

        return true;
    }

    public function deleteTeam(string $team) : void{

        if(isset($this->teams[$team])){

            unset($this->teams[$team]);

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

    public function getMembers(string $team) : array{

        if(!$this->teamExists($team)){
            return [];
        }

        return $this->teams[$team]["members"];
    }

    public function addMember(string $team, string $player) : void{

        if(!$this->teamExists($team)){
            return;
        }

        if(!in_array($player, $this->teams[$team]["members"])){

            $this->teams[$team]["members"][] = $player;

            $this->save();
        }
    }

    public function removeMember(string $team, string $player) : void{

        if(!$this->teamExists($team)){
            return;
        }

        $key = array_search(
            $player,
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

    public function sameTeam(string $player1, string $player2) : bool{

        $team1 = $this->getTeam($player1);
        $team2 = $this->getTeam($player2);

        return $team1 !== null &&
            $team2 !== null &&
            $team1 === $team2;
    }

    public function invitePlayer(
        Player $leader,
        Player $target
    ) : void{

        $team = $this->getTeam($leader->getName());

        if($team === null){
            return;
        }

        $this->invites[strtolower($target->getName())] = $team;
    }

    public function hasInvite(string $player) : bool{
        return isset(
            $this->invites[strtolower($player)]
        );
    }

    public function getInviteTeam(string $player) : ?string{

        return $this->invites[
            strtolower($player)
        ] ?? null;
    }

    public function removeInvite(string $player) : void{

        unset(
            $this->invites[strtolower($player)]
        );
    }

    public function isFull(string $team) : bool{

        $max = $this->plugin
            ->getConfig()
            ->get("max-team-size", 5);

        return count(
            $this->teams[$team]["members"]
        ) >= $max;
    }

    public function setHome(
        string $team,
        Position $pos
    ) : void{

        if(!$this->teamExists($team)){
            return;
        }

        $this->teams[$team]["home"] = [
            "x" => $pos->getX(),
            "y" => $pos->getY(),
            "z" => $pos->getZ(),
            "world" => $pos->getWorld()->getFolderName()
        ];

        $this->save();
    }

    public function getHome(string $team) : ?array{

        if(
            !$this->teamExists($team) ||
            $this->teams[$team]["home"] === null
        ){
            return null;
        }

        return $this->teams[$team]["home"];
    }

    public function updateNameTag(Player $player) : void{

        if(
            !$this->plugin
                ->getConfig()
                ->getNested("nametag.enabled")
        ){
            return;
        }

        $team = $this->getTeam(
            $player->getName()
        ) ?? "NoTeam";

        $health = round(
            $player->getHealth()
        );

$rank = "Player";

$pureChat = $this->getServer()
    ->getPluginManager()
    ->getPlugin("PureChat");

if($pureChat !== null){

    try{

        $rank = $pureChat
            ->getNametag($player);

        $rank = str_replace(
            ["{display_name}", "{msg}"],
            "",
            $rank
        );

    }catch(\Throwable $e){

        $rank = "Player";
    }
}

        if($team !== "NoTeam"){

            $format = $this->plugin
                ->getConfig()
                ->getNested(
                    "nametag.team-format"
                );

        }else{

            $format = $this->plugin
                ->getConfig()
                ->getNested(
                    "nametag.no-team-format"
                );
        }

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

        $player->setNameTagAlwaysVisible(true);

        $player->setNameTag($tag);
    }
}
