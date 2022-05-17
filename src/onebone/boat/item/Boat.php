<?php

namespace onebone\boat\item;

use JetBrains\PhpStorm\Pure;
use pocketmine\block\Block;
use pocketmine\entity\Location;
use pocketmine\item\Boat as PMBoat;
use onebone\boat\entity\Boat as BoatEntity;
use pocketmine\item\ItemUseResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;

class Boat extends PMBoat{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector) : ItemUseResult{
		$this->pop();

		$entity = new BoatEntity(Location::fromObject($blockClicked->getSide($face)->getPosition()->add(0.5, 0.5, 0.5), $player->getWorld()), CompoundTag::create()
			->setInt(BoatEntity::TAG_WOOD_ID, $this->getWoodType()->getMagicNumber())
		);
		$entity->spawnToAll();
		return ItemUseResult::SUCCESS();
	}

	#[Pure]
	public function getVanillaName() : string{
		return $this->getWoodType()->getDisplayName();
	}
}