<?php

declare(strict_types=1);

namespace Teaming;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;

use pocketmine\player\Player;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;

use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerChatEvent;

use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;

use pocketmine\scheduler\ClosureTask;

use pocketmine\world\Position;

class Main extends PluginBase implements Listener{

    private TeamManager $teamManager;

    private array $teamChat = [];

    private array $deleteConfirm = [];

    protected function onEnable() : void{

        @mkdir($this->getDataFolder());

        $this->saveDefaultConfig();

        if(!file_exists($this->getDataFolder() . "teams.yml")){
            file_put_contents(
                $this->getDataFolder() . "teams.yml",
                "[]"
            );
        }

        $this->teamManager = new TeamManager($this);

        $this->getServer()
            ->getPluginManager()
            ->registerEvents($this, $this);

        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function() : void{

                foreach(
                    $this->getServer()->getOnlinePlayers()
                    as $player
                ){

                    $this->teamManager
                        ->updateNameTag($player);
                }

            }),
            20
        );
    }

    public function onJoin(PlayerJoinEvent $event) : void{

        $this->teamManager
            ->updateNameTag(
                $event->getPlayer()
            );
    }

    public function onDamage(EntityDamageEvent $event) : void{

        $entity = $event->getEntity();

        if($entity instanceof Player){

            $this->teamManager
                ->updateNameTag($entity);
        }
    }

    public function onHeal(EntityRegainHealthEvent $event) : void{

        $entity = $event->getEntity();

        if($entity instanceof Player){

            $this->teamManager
                ->updateNameTag($entity);
        }
    }

    public function onHit(
        EntityDamageByEntityEvent $event
    ) : void{

        $damager = $event->getDamager();
        $entity = $event->getEntity();

        if(
            !$damager instanceof Player ||
            !$entity instanceof Player
        ){
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
    }

    public function onChat(PlayerChatEvent $event) : void{

        if(
            !$this->getConfig()
                ->getNested("chat.enabled")
        ){
            return;
        }

        $event->cancel();

        $player = $event->getPlayer();

        $team = $this->teamManager
            ->getTeam(
                $player->getName()
            ) ?? "NoTeam";

        $rank = "";

        $pureChat = $this->getServer()
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

        if(
            isset(
                $this->teamChat[
                    strtolower(
                        $player->getName()
                    )
                ]
            )
        ){

            if($team === "NoTeam"){

                unset(
                    $this->teamChat[
                        strtolower(
                            $player->getName()
                        )
                    ]
                );

                return;
            }

            $format = $this->getConfig()
                ->getNested(
                    "chat.team-chat-format"
                );

            $message = str_replace(
                [
                    "{TEAM}",
                    "{RANK}",
                    "{PLAYER}",
                    "{MESSAGE}"
                ],
                [
                    $team,
                    $rank,
                    $player->getName(),
                    $event->getMessage()
                ],
                $format
            );

            foreach(
                $this->teamManager
                    ->getMembers($team)
                as $member
            ){

                $target = $this->getServer()
                    ->getPlayerExact($member);

                if($target !== null){
                    $target->sendMessage($message);
                }
            }

            return;
        }

        if($team === "NoTeam"){

            $format = $this->getConfig()
                ->getNested(
                    "chat.no-team-public-chat-format"
                );

        }else{

            $format = $this->getConfig()
                ->getNested(
                    "chat.public-chat-format"
                );
        }

        $message = str_replace(
            [
                "{TEAM}",
                "{RANK}",
                "{PLAYER}",
                "{MESSAGE}"
            ],
            [
                $team,
                $rank,
                $player->getName(),
                $event->getMessage()
            ],
            $format
        );

        foreach(
            $this->getServer()
                ->getOnlinePlayers()
            as $online
        ){
            $online->sendMessage($message);
        }
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

        if(strtolower($command->getName()) !== "team"){
            return true;
        }

        if(!isset($args[0])){

            $sender->sendMessage("§e/team help");

            return true;
        }

        switch(strtolower($args[0])){

            case "help":

                $sender->sendMessage("§6----- Team Help -----");
                $sender->sendMessage("§e/team create <name>");
                $sender->sendMessage("§e/team invite <player>");
                $sender->sendMessage("§e/team accept");
                $sender->sendMessage("§e/team leave");
                $sender->sendMessage("§e/team kick <player>");
                $sender->sendMessage("§e/team list");
                $sender->sendMessage("§e/team chat");
                $sender->sendMessage("§e/team delete");
                $sender->sendMessage("§e/team deleteconfirm");
                $sender->sendMessage("§e/team sethome");
                $sender->sendMessage("§e/team home");

            break;

            case "create":

                if(!isset($args[1])){
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

                $broadcast = $this->getConfig()
                    ->getNested(
                        "broadcasts.team-created"
                    );

                $broadcast = str_replace(
                    ["{PLAYER}", "{TEAM}"],
                    [$sender->getName(), $team],
                    $broadcast
                );

                $this->getServer()
                    ->broadcastMessage($broadcast);

            break;

            case "invite":

                if(!isset($args[1])){
                    return true;
                }

                if(!$this->teamManager->isLeader($sender->getName())){

                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.not-leader"
                        )
                    );

                    return true;
                }

                $target = $this->getServer()
                    ->getPlayerByPrefix($args[1]);

                if($target === null){

                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.player-not-found"
                        )
                    );

                    return true;
                }

                $team = $this->teamManager
                    ->getTeam($sender->getName());

                if($this->teamManager->isFull($team)){

                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.team-full"
                        )
                    );

                    return true;
                }

                $this->teamManager
                    ->invitePlayer($sender, $target);

                $sender->sendMessage(
                    str_replace(
                        "{PLAYER}",
                        $target->getName(),
                        $this->getConfig()->getNested(
                            "messages.invite-sent"
                        )
                    )
                );

                $target->sendMessage(
                    str_replace(
                        ["{TEAM}", "{PLAYER}"],
                        [$team, $sender->getName()],
                        $this->getConfig()->getNested(
                            "messages.invited"
                        )
                    )
                );

            break;

            case "accept":

                if(!$this->teamManager->hasInvite($sender->getName())){

                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.no-invite"
                        )
                    );

                    return true;
                }

                $team = $this->teamManager
                    ->getInviteTeam($sender->getName());

                $this->teamManager
                    ->addMember($team, $sender->getName());

                $this->teamManager
                    ->removeInvite($sender->getName());

                foreach(
                    $this->teamManager->getMembers($team)
                    as $member
                ){

                    $online = $this->getServer()
                        ->getPlayerExact($member);

                    if($online !== null){

                        $online->sendMessage(
                            str_replace(
                                "{PLAYER}",
                                $sender->getName(),
                                $this->getConfig()->getNested(
                                    "messages.joined-team"
                                )
                            )
                        );
                    }
                }

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

                if(isset($this->teamChat[strtolower($sender->getName())])){

                    unset($this->teamChat[strtolower($sender->getName())]);

                    $sender->sendMessage(
                        $this->getConfig()->getNested(
                            "messages.toggled-chat-off"
                        )
                    );

                }else{

                    $this->teamChat[strtolower($sender->getName())] = true;

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
}
