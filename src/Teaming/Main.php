<?php

declare(strict_types=1);

namespace Teaming;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use _64FF00\PureChat\PureChat;

class Main extends PluginBase implements Listener{

    private TeamManager $teamManager;

    private array $deleteConfirm = [];
    private array $teamChat = [];

    public function onEnable() : void{

        @mkdir($this->getDataFolder());

        $this->saveDefaultConfig();

        $this->teamManager = new TeamManager($this);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->getScheduler()->scheduleRepeatingTask(
            new class($this) extends Task{

                private Main $plugin;

                public function __construct(Main $plugin){
                    $this->plugin = $plugin;
                }

                public function onRun() : void{

                    foreach($this->plugin->getServer()->getOnlinePlayers() as $player){

                        $this->plugin->getTeamManager()->updateNameTag($player);
                    }
                }
            },
            20
        );
    }

    public function onDisable() : void{
        $this->teamManager->saveData();
    }

    public function getTeamManager() : TeamManager{
        return $this->teamManager;
    }

    public function msg(string $path) : string{
        return $this->getConfig()->getNested("messages." . $path);
    }

    /*
     DISABLE TEAM CHAT
    */

    public function disableTeamChat(Player $player) : void{

        if(isset($this->teamChat[$player->getName()])){

            unset($this->teamChat[$player->getName()]);
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{

        if(!$sender instanceof Player){
            return true;
        }

        if(!isset($args[0])){
            $this->sendHelp($sender);
            return true;
        }

        switch(strtolower($args[0])){

            case "help":
                $this->sendHelp($sender);
            break;

            case "create":

                if(!isset($args[1])){
                    $sender->sendMessage("§cUsage: /team create <name>");
                    return true;
                }

                $result = $this->teamManager->createTeam($sender, $args[1]);

                if($result !== "success"){
                    $sender->sendMessage($this->msg($result));
                    return true;
                }

                $sender->sendMessage($this->msg("team-created"));

            break;

            case "invite":

                if(!isset($args[1])){
                    $sender->sendMessage("§cUsage: /team invite <player>");
                    return true;
                }

                $target = $this->getServer()->getPlayerByPrefix($args[1]);

                if($target === null){
                    $sender->sendMessage($this->msg("player-not-found"));
                    return true;
                }

                $result = $this->teamManager->invite($sender, $target);

                if($result !== "success"){
                    $sender->sendMessage($this->msg($result));
                    return true;
                }

                $sender->sendMessage(
                    str_replace("{PLAYER}", $target->getName(), $this->msg("invite-sent"))
                );

            break;

            case "accept":

                if(!$this->teamManager->acceptInvite($sender)){
                    $sender->sendMessage($this->msg("no-invite"));
                }

            break;

            case "leave":

                if(!$this->teamManager->hasTeam($sender->getName())){
                    $sender->sendMessage($this->msg("no-team"));
                    return true;
                }

                if($this->teamManager->isOwner($sender->getName())){
                    $sender->sendMessage($this->msg("leader-cannot-leave"));
                    return true;
                }

                $this->teamManager->leaveTeam($sender);

                $sender->sendMessage(
                    str_replace("{PLAYER}", $sender->getName(), $this->msg("left-team"))
                );

            break;

            case "delete":

                if(!$this->teamManager->isOwner($sender->getName())){
                    $sender->sendMessage($this->msg("not-leader"));
                    return true;
                }

                $this->deleteConfirm[$sender->getName()] = true;

                $sender->sendMessage($this->msg("delete-warning"));

            break;

            case "deleteconfirm":

                if(!isset($this->deleteConfirm[$sender->getName()])){

                    $sender->sendMessage(
                        $this->msg("no-pending-delete")
                    );

                    return true;
                }

                $this->teamManager->deleteTeam($sender);

                unset($this->deleteConfirm[$sender->getName()]);

                $sender->sendMessage($this->msg("team-deleted"));

            break;

            case "kick":

                if(!isset($args[1])){
                    $sender->sendMessage("§cUsage: /team kick <player>");
                    return true;
                }

                $target = $this->getServer()->getPlayerByPrefix($args[1]);

                if($target === null){
                    $sender->sendMessage($this->msg("player-not-found"));
                    return true;
                }

                if(!$this->teamManager->sameTeam(
                    $sender->getName(),
                    $target->getName()
                )){
                    $sender->sendMessage($this->msg("player-not-in-team"));
                    return true;
                }

                if($this->teamManager->isOwner($target->getName())){
                    $sender->sendMessage($this->msg("cannot-kick-owner"));
                    return true;
                }

                $this->teamManager->kickPlayer($sender, $target);

            break;

            case "list":

                if(!$this->teamManager->hasTeam($sender->getName())){
                    $sender->sendMessage($this->msg("no-team"));
                    return true;
                }

                $team = $this->teamManager->getTeam($sender->getName());

                $sender->sendMessage("§8===== §b{$team} Members §8=====");

                foreach($this->teamManager->getMembers($sender) as $member){
                    $sender->sendMessage("§b- §f" . $member);
                }

            break;

            case "chat":

                if(!$this->teamManager->hasTeam($sender->getName())){

                    $sender->sendMessage(
                        $this->msg("not-in-team-chat")
                    );

                    return true;
                }

                if(isset($this->teamChat[$sender->getName()])){

                    unset($this->teamChat[$sender->getName()]);

                    $sender->sendMessage(
                        $this->msg("toggled-chat-off")
                    );

                }else{

                    $this->teamChat[$sender->getName()] = true;

                    $sender->sendMessage(
                        $this->msg("toggled-chat-on")
                    );
                }

            break;
        }

        return true;
    }

    public function sendHelp(Player $player) : void{

        $player->sendMessage("§8===== §bTeam Help §8=====");
        $player->sendMessage("§b/team create <name>");
        $player->sendMessage("§b/team invite <player>");
        $player->sendMessage("§b/team accept");
        $player->sendMessage("§b/team leave");
        $player->sendMessage("§b/team delete");
        $player->sendMessage("§b/team deleteconfirm");
        $player->sendMessage("§b/team kick <player>");
        $player->sendMessage("§b/team list");
        $player->sendMessage("§b/team chat");
    }
}
