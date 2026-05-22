<?php

declare(strict_types=1);

namespace Teaming;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;

class Main extends PluginBase implements Listener{

    private TeamManager $teamManager;

    private array $deleteConfirm = [];
    private array $teamChat = [];

    public function onEnable() : void{

        $this->saveDefaultConfig();

        $this->teamManager = new TeamManager($this);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function getTeamManager() : TeamManager{
        return $this->teamManager;
    }

    public function msg(string $path) : string{
        return $this->getConfig()->getNested("messages." . $path);
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
                    return true;
                }

                $sender->sendMessage(
                    str_replace("{TEAM}",
                    $this->teamManager->getTeam($sender->getName()),
                    $this->msg("joined-team"))
                );

            break;

            case "leave":

                if($this->teamManager->isOwner($sender->getName())){
                    $sender->sendMessage($this->msg("leader-cannot-leave"));
                    return true;
                }

                $this->teamManager->leaveTeam($sender);

                $sender->sendMessage($this->msg("left-team"));

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
                    return true;
                }

                $this->teamManager->deleteTeam($sender);

                unset($this->deleteConfirm[$sender->getName()]);

                $sender->sendMessage($this->msg("team-deleted"));

            break;

            case "kick":

                if(!isset($args[1])){
                    return true;
                }

                $target = $this->getServer()->getPlayerByPrefix($args[1]);

                if($target === null){
                    $sender->sendMessage($this->msg("player-not-found"));
                    return true;
                }

                if(!$this->teamManager->kickPlayer($sender, $target)){
                    $sender->sendMessage($this->msg("not-leader"));
                    return true;
                }

                $sender->sendMessage(
                    str_replace("{PLAYER}", $target->getName(), $this->msg("kicked-player"))
                );

                $target->sendMessage($this->msg("kicked-message"));

            break;

            case "list":

                $members = $this->teamManager->getMembers($sender);

                $sender->sendMessage($this->msg("team-list-header"));

                foreach($members as $member){

                    $sender->sendMessage(
                        str_replace("{PLAYER}", $member, $this->msg("team-list-format"))
                    );
                }

            break;

            case "sethome":

                if(!$this->teamManager->isOwner($sender->getName())){
                    $sender->sendMessage($this->msg("not-leader"));
                    return true;
                }

                $this->teamManager->setHome($sender);

                $sender->sendMessage($this->msg("home-set"));

            break;

            case "home":

                if(!$this->teamManager->teleportHome($sender)){
                    $sender->sendMessage($this->msg("no-home"));
                    return true;
                }

                $sender->sendMessage($this->msg("home-teleport"));

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
        $player->sendMessage("§b/team sethome");
        $player->sendMessage("§b/team home");
    }

    public function onDamage(EntityDamageByEntityEvent $event) : void{

        $damager = $event->getDamager();
        $entity = $event->getEntity();

        if(!$damager instanceof Player || !$entity instanceof Player){
            return;
        }

        if($this->teamManager->sameTeam($damager->getName(), $entity->getName())){
            $event->cancel();
            $damager->sendMessage($this->msg("friendly-fire"));
        }
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $this->teamManager->updateNameTag($event->getPlayer());
    }

    public function onHealthChange(EntityDamageEvent|EntityRegainHealthEvent $event) : void{

        $entity = $event->getEntity();

        if($entity instanceof Player){

            $this->getScheduler()->scheduleDelayedTask(
                new class($this, $entity) extends Task{

                    private Main $plugin;
                    private Player $player;

                    public function __construct(Main $plugin, Player $player){
                        $this->plugin = $plugin;
                        $this->player = $player;
                    }

                    public function onRun() : void{
                        if($this->player->isOnline()){
                            $this->plugin->getTeamManager()->updateNameTag($this->player);
                        }
                    }
                },
                1
            );
        }
    }
}
