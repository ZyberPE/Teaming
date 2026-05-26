<?php

declare(strict_types=1);

namespace Teaming;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;

class Main extends PluginBase implements Listener{

    private TeamManager $teamManager;

    private array $teamChat = [];

    private array $deleteConfirm = [];

    protected function onEnable() : void{

        @mkdir($this->getDataFolder());

        $this->saveDefaultConfig();

        $this->teamManager = new TeamManager($this);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function getTeamManager() : TeamManager{
        return $this->teamManager;
    }

    public function onCommand(
        CommandSender $sender,
        Command $command,
        string $label,
        array $args
    ) : bool{

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

                if($this->teamManager->hasTeam($sender->getName())){
                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.already-team"
                        )
                    );
                    return true;
                }

                $team = $args[1];

                if(!$this->teamManager->createTeam($sender, $team)){
                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.team-exists"
                        )
                    );
                    return true;
                }

                $sender->sendMessage(
                    $this->getConfig()->getNested(
                        "messages.team-created"
                    )
                );

                $broadcast = str_replace(
                    ["{PLAYER}", "{TEAM}"],
                    [$sender->getName(), $team],
                    $this->getConfig()->getNested(
                        "broadcasts.team-created"
                    )
                );

                $this->getServer()->broadcastMessage($broadcast);

                $this->teamManager->updateNameTag($sender);

            break;

            case "leave":

                if(!$this->teamManager->hasTeam($sender->getName())){
                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.no-team"
                        )
                    );
                    return true;
                }

                if($this->teamManager->isLeader($sender->getName())){
                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.leader-cannot-leave"
                        )
                    );
                    return true;
                }

                $this->teamManager->leaveTeam($sender);

                unset($this->teamChat[$sender->getName()]);

                $sender->sendMessage(
                    str_replace(
                        "{PLAYER}",
                        $sender->getName(),
                        $this->getConfig()->getNested(
                            "messages.left-team"
                        )
                    )
                );

                $this->teamManager->updateNameTag($sender);

            break;

            case "delete":

                if(!$this->teamManager->isLeader($sender->getName())){
                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.not-leader"
                        )
                    );
                    return true;
                }

                $this->deleteConfirm[$sender->getName()] = true;

                $sender->sendMessage(
                    $this->getConfig()->getNested(
                        "messages.delete-warning"
                    )
                );

            break;

            case "deleteconfirm":

                if(!isset($this->deleteConfirm[$sender->getName()])){
                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.no-pending-delete"
                        )
                    );
                    return true;
                }

                $team = $this->teamManager->getTeam($sender->getName());

                $this->teamManager->deleteTeam($sender);

                unset($this->deleteConfirm[$sender->getName()]);
                unset($this->teamChat[$sender->getName()]);

                $sender->sendMessage(
                    $this->getConfig()->getNested(
                        "messages.team-deleted"
                    )
                );

                $broadcast = str_replace(
                    ["{PLAYER}", "{TEAM}"],
                    [$sender->getName(), $team],
                    $this->getConfig()->getNested(
                        "broadcasts.team-deleted"
                    )
                );

                $this->getServer()->broadcastMessage($broadcast);

                $this->teamManager->updateNameTag($sender);

            break;

            case "chat":

                if(!$this->teamManager->hasTeam($sender->getName())){
                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.not-in-team-chat"
                        )
                    );
                    return true;
                }

                if(isset($this->teamChat[$sender->getName()])){

                    unset($this->teamChat[$sender->getName()]);

                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.toggled-chat-off"
                        )
                    );

                }else{

                    $this->teamChat[$sender->getName()] = true;

                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.toggled-chat-on"
                        )
                    );
                }

            break;
        }

        return true;
    }

    private function sendHelp(Player $player) : void{

        $player->sendMessage("§8===== §bTeam Help §8=====");
        $player->sendMessage("§b/team create <name>");
        $player->sendMessage("§b/team leave");
        $player->sendMessage("§b/team delete");
        $player->sendMessage("§b/team deleteconfirm");
        $player->sendMessage("§b/team chat");
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $this->teamManager->updateNameTag($event->getPlayer());
    }

    public function onDamage(EntityDamageEvent $event) : void{

        $entity = $event->getEntity();

        if($entity instanceof Player){
            $this->teamManager->updateNameTag($entity);
        }
    }

    public function onHeal(EntityRegainHealthEvent $event) : void{

        $entity = $event->getEntity();

        if($entity instanceof Player){
            $this->teamManager->updateNameTag($entity);
        }
    }

    public function onHit(EntityDamageByEntityEvent $event) : void{

        $damager = $event->getDamager();
        $entity = $event->getEntity();

        if(!$damager instanceof Player || !$entity instanceof Player){
            return;
        }

        if(
            $this->teamManager->sameTeam(
                $damager->getName(),
                $entity->getName()
            )
        ){

            $event->cancel();

            $damager->sendMessage(
                $this->getConfig()->getNested(
                    "messages.friendly-fire"
                )
            );
        }

        $this->teamManager->updateNameTag($damager);
        $this->teamManager->updateNameTag($entity);
    }

    public function onChat(PlayerChatEvent $event) : void{

        if(!$this->getConfig()->getNested("chat.enabled")){
            return;
        }

        $event->cancel();

        $player = $event->getPlayer();

        $team = $this->teamManager->getTeam(
            $player->getName()
        ) ?? "NoTeam";

        $rank = "";

        $message = $event->getMessage();

        if(isset($this->teamChat[$player->getName()])){

            $format = $this->getConfig()->getNested(
                "chat.team-chat-format"
            );

        }elseif($team === "NoTeam"){

            $format = $this->getConfig()->getNested(
                "chat.no-team-public-chat-format"
            );

        }else{

            $format = $this->getConfig()->getNested(
                "chat.public-chat-format"
            );
        }

        $formatted = str_replace(
            ["{TEAM}", "{PLAYER}", "{MESSAGE}", "{RANK}"],
            [$team, $player->getName(), $message, $rank],
            $format
        );

        foreach($this->getServer()->getOnlinePlayers() as $online){

            if(isset($this->teamChat[$player->getName()])){

                if(
                    $this->teamManager->sameTeam(
                        $player->getName(),
                        $online->getName()
                    )
                ){
                    $online->sendMessage($formatted);
                }

            }else{
                $online->sendMessage($formatted);
            }
        }
    }
}
