<?php

/**
 *  ███╗   ███╗██╗███╗   ██╗███████╗██████╗ ██╗    ██╗ █████╗ ██████╗ ███████╗
 *  ████╗ ████║██║████╗  ██║██╔════╝██╔══██╗██║    ██║██╔══██╗██╔══██╗██╔════╝
 *  ██╔████╔██║██║██╔██╗ ██║█████╗  ██████╔╝██║ █╗ ██║███████║██████╔╝█████╗
 *  ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ██╔══██╗██║███╗██║██╔══██║██╔══██╗██╔══╝
 *  ██║ ╚═╝ ██║██║██║ ╚████║███████╗██║  ██║╚███╔███╔╝██║  ██║██║  ██║███████╗
 *  ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝╚═╝  ╚═╝ ╚══╝╚══╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚══════╝
 *
 * A game written in PHP for PocketMine-MP software.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Copyright 2022 © LatamPMDevs
 */

declare(strict_types=1);

namespace LatamPMDevs\minerware\arena\microgame;

use LatamPMDevs\minerware\arena\Map;

use pocketmine\block\Block;
use pocketmine\block\TNT;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ExplosionPrimeEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\HandlerListManager;
use pocketmine\event\Listener;
use pocketmine\item\FlintSteel;
use pocketmine\item\VanillaItems;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;

class IgniteTNT extends Microgame implements Listener {

	/** @var Block[] */
	protected array $changedBlocks = [];

	/** @var array<int, int> */
	protected array $ignitedTNTs = [];

	protected int $totalIgnitedTNTs = 0;

	public function getName() : string {
		return "Ignite The TNT";
	}

	public function getLevel() : Level {
		return Level::NORMAL();
	}

	public function getGameDuration() : float {
		return 15.0;
	}

	public function getRecompensePoints() : int {
		return self::DEFAULT_RECOMPENSE_POINTS;
	}

	public function start() : void {
		$this->startTime = microtime(true);
		$this->hasStarted = true;
		$this->plugin->getServer()->getPluginManager()->registerEvents($this, $this->plugin);

		$map = $this->arena->getMap();
		$minPos = $map->getPlatformMinPos();
		$world = $this->arena->getWorld();
		foreach (Map::MINI_PLATFORMS as $platformBlocks) {
			$blockPos = $platformBlocks[array_rand($platformBlocks)];
			$this->changedBlocks[] = $world->getBlockAt((int)($minPos->x + $blockPos[0]), (int)($minPos->y + $blockPos[1]), (int)($minPos->z + $blockPos[2]));
			$world->setBlockAt((int)($minPos->x + $blockPos[0]), (int)($minPos->y + $blockPos[1]), (int)($minPos->z + $blockPos[2]), VanillaBlocks::TNT(), false);
		}

		foreach ($this->arena->getPlayers() as $player) {
			$player->getInventory()->clearAll();
			$player->getArmorInventory()->clearAll();
			$player->getCursorInventory()->clearAll();
			$player->getOffHandInventory()->clearAll();
			$player->setGamemode(GameMode::SURVIVAL());
			$player->getInventory()->setItem(0, VanillaItems::FLINT_AND_STEEL());
			$player->getInventory()->setHeldItemIndex(0);

			$this->ignitedTNTs[$player->getId()] = 0;
		}
	}

	public function tick() : void {
		$timeLeft = $this->getTimeLeft();
		if ($timeLeft <= 0) {
			foreach ($this->arena->getPlayers() as $player) {
				if (!$this->isWinner($player) && !$this->isLoser($player)) {
					$this->addLoser($player);
				}
			}
			$this->arena->endCurrentMicrogame();
			return;
		}
		foreach ($this->arena->getPlayers() as $player) {
			$player->getXpManager()->setXpAndProgress((int) $timeLeft, $timeLeft / $this->getGameDuration());
		}
	}

	public function end() : void {
		$this->hasEnded = true;
		HandlerListManager::global()->unregisterAll($this);

		$players = $this->arena->getPlayers();
		foreach ($players as $player) {
			$player->sendMessage($this->plugin->getTranslator()->translate(
				$player, "microgame.ignitetnt.total", [
					"{%count}" => $this->totalIgnitedTNTs
				]
			));
			if ($this->isWinner($player)) {
				$player->sendMessage($this->plugin->getTranslator()->translate($player, "microgame.ignitetnt.won"));
			} else {
				# TODO: Loser message
			}
		}
		foreach ($this->changedBlocks as $block) {
			$this->arena->getWorld()->setBlock($block->getPosition(), $block, false);
		}
	}

	public function getIgnitedTNTs(Player $player) : int {
		return $this->ignitedTNTs[$player->getId()] ?? 0;
	}

	/**
	 * @return array<int, int>
	 */
	public function getIgnitedTNTsOrderedByHigherScore() : array {
		$array = $this->ignitedTNTs;
		if (asort($array) === false) {
			throw new AssumptionFailedError("Failed to sort score");
		}
		return array_reverse($array, true);
	}

	public function getTotalIgnitedTNTs() : int {
		return $this->totalIgnitedTNTs;
	}

	# Listener

	public function onBlockBreak(BlockBreakEvent $event) : void {
		$player = $event->getPlayer();
		if (!$this->arena->inGame($player)) return;
		$event->cancel();
	}

	public function onBlockPlace(BlockPlaceEvent $event) : void {
		$player = $event->getPlayer();
		if (!$this->arena->inGame($player)) return;
		$event->cancel();
	}

	public function onDamage(EntityDamageEvent $event) : void {
		$player = $event->getEntity();
		if (!$player instanceof Player) return;
		if (!$this->arena->inGame($player)) return;
		$event->cancel();
		if ($event->getCause() === EntityDamageEvent::CAUSE_VOID && !$this->isWinner($player)) {
			$this->addLoser($player);
			$this->arena->sendToLosersCage();
		}
	}

	/**
	 * @ignoreCancelled
	 * @priority HIGH
	 */
	public function onInteract(PlayerInteractEvent $event) : void {
		$player = $event->getPlayer();
		if (!$this->arena->inGame($player)) return;
		if ($event->getItem() instanceof FlintSteel) {
			if ($event->getBlock() instanceof TNT) {
				$this->ignitedTNTs[$player->getId()] = $this->getIgnitedTNTs($player) + 1;
				$this->totalIgnitedTNTs++;
				if (!$this->isWinner($player) && !$this->isLoser($player)) {
					$this->addWinner($player);
				}
			} else {
				$event->cancel();
			}
		}
	}

	public function onExplosion(ExplosionPrimeEvent $event) : void {
		$entity = $event->getEntity();
		if ($entity->getWorld() !== $this->arena->getWorld()) return;
		if ($entity instanceof PrimedTNT) {
			$event->setBlockBreaking(false);
		}
	}
}