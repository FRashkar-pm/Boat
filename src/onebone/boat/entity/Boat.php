<?php

namespace onebone\boat\entity;

use InvalidArgumentException;
use onebone\boat\Main;
use pocketmine\block\utils\TreeType;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\item\ItemIds as Ids;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\SetActorLinkPacket;
use pocketmine\network\mcpe\protocol\AnimatePacket;

class Boat extends Entity{

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.455, 1.4); }

	public static function getNetworkTypeId() : string{ return EntityIds::BOAT; }

	/** @var float */
	public $gravity = 0.0;
	/** @var float */
	public $drag = 0.1;

	public const TAG_WOOD_ID = "WoodID";

	public const ACTION_ROW_RIGHT = 128;
	public const ACTION_ROW_LEFT = 129;

	public ?Entity $rider = null;

	private int $woodId;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$woodId = $nbt->getInt(self::TAG_WOOD_ID, $default = TreeType::OAK()->getMagicNumber());
		if($woodId > 5 || $woodId < 0){
			$woodId = $default;
		}
		$this->setWoodId($woodId);
		$this->setMaxHealth(4);
		$this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::STACKABLE, true);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_WOOD_ID, $this->woodId);
		return $nbt;
	}

	public function getWoodId(): int{
		return $this->woodId;
	}

	public function setWoodId(int $woodId): void{
		if($woodId > 5 || $woodId < 0){
			throw new InvalidArgumentException("The woodId can only be specified as a number greater than 0 and less than 5.");
		}
		$this->woodId = $woodId;
		$this->networkPropertiesDirty = true;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->woodId);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled()){
			$this->getWorld()->broadcastPacketToViewers($this->getPosition(), ActorEventPacket::create(
				actorRuntimeId: $this->id,
				eventId: ActorEvent::HURT_ANIMATION,
				eventData: 0
			));
		}
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		if($this->rider !== null){
			$player->getNetworkSession()->sendDataPacket(SetActorLinkPacket::create(
				link: new EntityLink($this->id, $this->rider->getId(), EntityLink::TYPE_RIDER, true, true)
			));
		}
	}

	public function kill() : void{
		parent::kill();
		if($this->lastDamageCause instanceof EntityDamageByEntityEvent){
			$damager = $this->lastDamageCause->getDamager();
			if(($damager instanceof Player) && !$damager->hasFiniteResources()){
				return;
			}
		}
		foreach($this->getDrops() as $drop){
			$this->getWorld()->dropItem($this->getPosition(), $drop);
		}
	}

	public function getDrops(): array{
		return [
			ItemFactory::getInstance()->get(Ids::BOAT, $this->woodId)->setCount(1)
		];
	}

	public function onUpdate(int $currentTick) : bool{
		$hasUpdate = parent::onUpdate($currentTick);
		if($this->closed){
			return false;
		}
		if($currentTick & 10 == 0 && $this->getHealth() < $this->getMaxHealth()){
			$this->heal(new EntityRegainHealthEvent($this, 1, EntityRegainHealthEvent::CAUSE_REGEN));
		}
		return $hasUpdate;
	}

	public function isRiding(): bool{
		return $this->rider !== null;
	}

	public function getRider(): ?Entity{
		return $this->rider;
	}

	public function isRider(Entity $entity): bool{
		if($this->rider === null){
			return false;
		}
		return $this->rider->getId() === $entity->getId();
	}

	public function link(Entity $rider): bool{
		if($this->rider === null){
			$properties = $rider->getNetworkProperties();
			$properties->setGenericFlag(EntityMetadataFlags::RIDING, true);
			$properties->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, new Vector3(0, 1, 0));
			$properties->setByte(EntityMetadataProperties::RIDER_ROTATION_LOCKED, true);
			$properties->setFloat(EntityMetadataProperties::RIDER_MAX_ROTATION, 180);
			$properties->setFloat(EntityMetadataProperties::RIDER_MIN_ROTATION, -180);

			$this->getWorld()->broadcastPacketToViewers($this->getPosition(), SetActorLinkPacket::create(
				link: new EntityLink($this->id, $rider->getId(), EntityLink::TYPE_RIDER, true, true)
			));

			if($rider instanceof Player){
				Main::$playerBoat[$rider->getUniqueId()->toString()] = $this;
			}

			$this->rider = $rider;
			return true;
		}
		return false;
	}

	public function unlink(Entity $rider): bool{
		if($this->isRider($rider)){
			$properties = $rider->getNetworkProperties();
			$properties->setGenericFlag(EntityMetadataFlags::RIDING, false);
			$properties->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, new Vector3(0, 0, 0));
			$properties->setByte(EntityMetadataProperties::RIDER_ROTATION_LOCKED, false);

			$this->getWorld()->broadcastPacketToViewers($this->getPosition(), SetActorLinkPacket::create(
				link: new EntityLink($this->id, $rider->getId(), EntityLink::TYPE_REMOVE, true, true)
			));

			if(($rider instanceof Player) && isset(Main::$playerBoat[$rider->getUniqueId()->toString()])){
				unset(Main::$playerBoat[$rider->getUniqueId()->toString()]);
			}

			$this->rider = null;
			return true;
		}
		return false;
	}

	public function absoluteMove(Vector3 $pos, float $yaw = 0, float $pitch = 0): void{
		$this->location->withComponents($pos->x, $pos->y, $pos->z); //TODO: check $this->setComponents($pos->x, $pos->y, $pos->z); hmm..
		$this->setRotation($yaw, $pitch);
		$this->updateMovement();
	}

	public function handleAnimatePacket(AnimatePacket $packet): void{
		if(!$this->isRiding()){
			return;
		}
		switch($packet->action){
			case self::ACTION_ROW_RIGHT:
				$this->getNetworkProperties()->setFloat(EntityMetadataProperties::PADDLE_TIME_RIGHT, $packet->float);
				$this->networkPropertiesDirty = true;
				break;
			case self::ACTION_ROW_LEFT:
				$this->getNetworkProperties()->setFloat(EntityMetadataProperties::PADDLE_TIME_LEFT, $packet->float);
				$this->networkPropertiesDirty = true;
				break;
			default:
				break;
		}
	}
}
