<?php

declare(strict_types=1);

namespace Teaming;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

class Main extends PluginBase implements Listener{

    private TeamManager $teamManager;

    private array $deleteConfirm = [];
    private array $teamChat = [];

    public function onEnable() : void{
        $this->saveDefaultConfig();

        $this->teamManager = new TeamManager($this);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        if($this->getServer()->getPluginManager()->getPlugin("PureChat") !== null){
            $this->getLogger()->info("PureChat support enabled.");
        }
    }

    public function getTeamManager() : TeamManager{
        return $this->teamManager;
    }

    public function msg(string $path) : string{
        return $this->getConfig()->get("messages")[$path];
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

                if($result === "exists"){
                    $sender->sendMessage($this->msg("team-exists"));
                    return true;
                }

                if($result === "already"){
                    $sender->sendMessage($this->msg("already-team"));
                    return true;
                }

                $sender->sendMessage($this->msg("team-created"));

            break;

            case "invite":

                if(!isset($args[1])){
                    $sender->sendMessage("§cUsage: /team invite <player>");
                    return true;
                }

                $target = $this->getServer()->getPlayerExact($args[1]);

                if($target === null){
                    $sender->sendMessage($this->msg("player-not-found"));
                    return true;
                }

                if(!$this->teamManager->hasTeam($sender->getName())){
                    $sender->sendMessage($this->msg("no-team"));
                    return true;
                }

                $this->teamManager->invite($sender, $target);

                $sender->sendMessage(
                    str_replace("{PLAYER}", $target->getName(), $this->msg("invite-sent"))
                );

                $target->sendMessage(
                    str_replace(
                        ["{TEAM}"],
                        [$this->teamManager->getTeam($sender->getName())],
                        $this->msg("invited")
                    )
                );

            break;

            case "accept":

                if(!$this->teamManager->acceptInvite($sender)){
                    $sender->sendMessage($this->msg("no-invite"));
                    return true;
                }

                $sender->sendMessage(
                    str_replace(
                        "{TEAM}",
                        $this->teamManager->getTeam($sender->getName()),
                        $this->msg("joined-team")
                    )
                );

                $this->teamManager->updateNameTag($sender);

            break;

            case "leave":

                if(!$this->teamManager->hasTeam($sender->getName())){
                    $sender->sendMessage($this->msg("no-team"));
                    return true;
                }

                $this->teamManager->leaveTeam($sender);

                $sender->sendMessage($this->msg("left-team"));

            break;

            case "delete":

                if(!$this->teamManager->isOwner($sender->getName())){
                    $sender->sendMessage("§cYou are not the team owner.");
                    return true;
                }

                $this->deleteConfirm[$sender->getName()] = true;

                $sender->sendMessage($this->msg("delete-warning"));

            break;

            case "deleteconfirm":

                if(!isset($this->deleteConfirm[$sender->getName()])){
                    $sender->sendMessage("§cRun /team delete first.");
                    return true;
                }

                $this->teamManager->deleteTeam($sender);

                unset($this->deleteConfirm[$sender->getName()]);

                $sender->sendMessage($this->msg("team-deleted"));

            break;

            case "chat":

                if(isset($this->teamChat[$sender->getName()])){
                    unset($this->teamChat[$sender->getName()]);
                    $sender->sendMessage($this->msg("toggled-chat-off"));
                }else{
                    $this->teamChat[$sender->getName()] = true;
                    $sender->sendMessage($this->msg("toggled-chat-on"));
                }

            break;
        }

        return true;
    }

    public function sendHelp(Player $player) : void{

        $player->sendMessage("§8===== §bTeam Help §8=====");
        $player->sendMessage("§b/team create <name> §7- Create a team");
        $player->sendMessage("§b/team invite <player> §7- Invite a player");
        $player->sendMessage("§b/team accept §7- Accept a team invite");
        $player->sendMessage("§b/team leave §7- Leave your current team");
        $player->sendMessage("§b/team delete §7- Delete your team");
        $player->sendMessage("§b/team deleteconfirm §7- Confirm deleting team");
        $player->sendMessage("§b/team chat §7- Toggle team chat");
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

    public function onChat(PlayerChatEvent $event) : void{

        $player = $event->getPlayer();

        if(!isset($this->teamChat[$player->getName()])){
            return;
        }

        if(!$this->teamManager->hasTeam($player->getName())){
            return;
        }

        $event->cancel();

        $team = $this->teamManager->getTeam($player->getName());

        $format = $this->getConfig()->getNested("chat.format");

        $message = str_replace(
            ["{TEAM}", "{PLAYER}", "{MESSAGE}"],
            [$team, $player->getName(), $event->getMessage()],
            $format
        );

        foreach($this->getServer()->getOnlinePlayers() as $online){

            if($this->teamManager->sameTeam($player->getName(), $online->getName())){
                $online->sendMessage($message);
            }
        }
    }
}
