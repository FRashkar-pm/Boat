<?php

namespace onebone\boat;

use onebone\boat\entity\Boat as BoatEntity;
use onebone\boat\item\Boat;
use pocketmine\block\utils\TreeType;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemIds;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\PlayerInputPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;

class Main extends PluginBase{

	/**
	 * @phpstan-var array<string, BoatEntity>
	 * @var BoatEntity[];
	 */
	public static array $playerBoat = [];

	protected function onLoad() : void{
		$itemFactory = ItemFactory::getInstance();
		$craftingManager = $this->getServer()->getCraftingManager();
		$creativeInventory = CreativeInventory::getInstance();
		foreach(TreeType::getAll() as $treeType){
			$itemFactory->register($item = new Boat(new ItemIdentifier(ItemIds::BOAT, $treeType->getMagicNumber()), $treeType->getDisplayName() . " Boat", $treeType), true);
			if(!$creativeInventory->contains($item)){
				$creativeInventory->add($item);
			}
			$craftingManager->registerShapelessRecipe(new ShapelessRecipe(
				ingredients: [$itemFactory->get(ItemIds::WOODEN_PLANKS, $treeType->getMagicNumber(), 5), $itemFactory->get(ItemIds::WOODEN_SHOVEL, 0, 1)],
				results: [$itemFactory->get(ItemIds::BOAT, $treeType->getMagicNumber(), 1)]
			));
		}
		EntityFactory::getInstance()->register(BoatEntity::class, function(World $world, CompoundTag $nbt): BoatEntity{
			return new BoatEntity(EntityDataHelper::parseLocation($nbt, $world), $nbt);
		}, ['BoatEntity', 'minecraft:boat']);
	}

	protected function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvent(PlayerQuitEvent::class, function(PlayerQuitEvent $event): void{
			$player = $event->getPlayer();
			$rawUUID = $player->getUniqueId()->toString();
			if(!isset(self::$playerBoat[$rawUUID])){
				return;
			}
			$boat = self::$playerBoat[$rawUUID];
			if(!$boat->isClosed()){
				$boat->unlink($player);
			}
		}, EventPriority::MONITOR, $this, false);

		$this->getServer()->getPluginManager()->registerEvent(DataPacketReceiveEvent::class, function(DataPacketReceiveEvent $event): void{
			$player = $event->getOrigin()->getPlayer();
			if($player === null){
				return;
			}
			$rawUUID = $player->getUniqueId()->toString();
			$packet = $event->getPacket();
			if(
				$packet instanceof InventoryTransactionPacket &&
				$packet->trData instanceof UseItemOnEntityTransactionData &&
				$packet->trData->getActionType() === UseItemOnEntityTransactionData::ACTION_INTERACT
			){
				$entity = $player->getWorld()->getEntity($packet->trData->getActorRuntimeId());
				if(!$entity instanceof BoatEntity){
					return;
				}
				if(!$entity->isRiding()){
					$entity->link($player);
				}
				$event->cancel();
			}elseif($packet instanceof InteractPacket){
				$entity = $player->getWorld()->getEntity($packet->targetActorRuntimeId);
				if(!$entity instanceof BoatEntity){
					return;
				}
				if($packet->action === InteractPacket::ACTION_LEAVE_VEHICLE && $entity->isRider($player)){
					$entity->unlink($player);
				}
				$event->cancel();
			}elseif($packet instanceof MoveActorAbsolutePacket){
				$entity = $player->getWorld()->getEntity($packet->actorRuntimeId);
				if($entity instanceof BoatEntity && $entity->isRider($player)){
					/**
					 * $packet->xRot to $packet->yaw
					 * $packet->zRot to $packet->pitch
					 */
					$entity->absoluteMove($packet->position, $packet->yaw, $packet->pitch);
					$event->cancel();
				}
			}elseif($packet instanceof AnimatePacket){
				if(!isset(self::$playerBoat[$rawUUID])){
					return;
				}
				$entity = self::$playerBoat[$rawUUID];
				switch($packet->action){
					case BoatEntity::ACTION_ROW_RIGHT:
					case BoatEntity::ACTION_ROW_LEFT:
						$entity->handleAnimatePacket($packet);
						$event->cancel();
						break;
				}
			}elseif($packet instanceof PlayerInputPacket || $packet instanceof SetActorMotionPacket){
				if(!isset(self::$playerBoat[$rawUUID])){
					return;
				}
				$event->cancel();
			}
		}, EventPriority::MONITOR, $this, false);
	}
}
