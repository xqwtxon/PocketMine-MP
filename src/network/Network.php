<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

/**
 * Network-related classes
 */
namespace pocketmine\network;

use pocketmine\event\server\NetworkInterfaceRegisterEvent;
use pocketmine\event\server\NetworkInterfaceUnregisterEvent;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\Server;
use pocketmine\utils\Utils;
use function base64_encode;
use function get_class;
use function preg_match;
use function spl_object_id;
use function time;
use const PHP_INT_MAX;

class Network{
	/** @var NetworkInterface[] */
	private array $interfaces = [];

	/** @var AdvancedNetworkInterface[] */
	private array $advancedInterfaces = [];

	/** @var RawPacketHandler[] */
	private array $rawPacketHandlers = [];

	/**
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private array $bannedIps = [];

	private BidirectionalBandwidthStatsTracker $bandwidthTracker;
	private string $name;
	private ?string $lanName;
	private NetworkSessionManager $sessionManager;

	public function __construct(
		private \Logger $logger
	){
		$this->sessionManager = new NetworkSessionManager();
		$this->bandwidthTracker = new BidirectionalBandwidthStatsTracker(5);
	}

	public function getBandwidthTracker() : BidirectionalBandwidthStatsTracker{ return $this->bandwidthTracker; }

	/**
	 * @return NetworkInterface[]
	 */
	public function getInterfaces() : array{
		return $this->interfaces;
	}

	public function getSessionManager() : NetworkSessionManager{
		return $this->sessionManager;
	}

	public function getConnectionCount() : int{
		return $this->sessionManager->getSessionCount();
	}

	public function tick() : void{
		foreach($this->interfaces as $interface){
			$interface->tick();
		}

		$this->sessionManager->tick();
	}

	/**
	 * @throws NetworkInterfaceStartException
	 */
	public function registerInterface(NetworkInterface $interface) : bool{
		$ev = new NetworkInterfaceRegisterEvent($interface);
		$ev->call();
		if(!$ev->isCancelled()){
			$interface->start();
			$this->interfaces[$hash = spl_object_id($interface)] = $interface;
			if($interface instanceof AdvancedNetworkInterface){
				$this->advancedInterfaces[$hash] = $interface;
				$interface->setNetwork($this);
				foreach(Utils::stringifyKeys($this->bannedIps) as $ip => $until){
					$interface->blockAddress($ip);
				}
				foreach($this->rawPacketHandlers as $handler){
					$interface->addRawPacketFilter($handler->getPattern());
				}
			}
			$interface->setName($this->name);
			$interface->setLanName($this->lanName);
			return true;
		}
		return false;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function unregisterInterface(NetworkInterface $interface) : void{
		if(!isset($this->interfaces[$hash = spl_object_id($interface)])){
			throw new \InvalidArgumentException("Interface " . get_class($interface) . " is not registered on this network");
		}
		(new NetworkInterfaceUnregisterEvent($interface))->call();
		unset($this->interfaces[$hash], $this->advancedInterfaces[$hash]);
		$interface->shutdown();
	}

	public function setName(string $name) : void{
		$this->name = $name;
		foreach($this->interfaces as $interface){
			if($interface instanceof RakLibInterface){
				$interface->internalSetName($this->name, $this->lanName);
			} else {
				$interface->setName($this->name);
				$interface->setLanName($this->lanName);
			}
		}
	}

	/**
	 * This changes the behaivor of lan motd. Since this can be only viewed in the lan list on minecraft.
	 *
	 * @param $lanName  If null, returns the default value to prevent query bugs.
	 */
	public function setLanName(?string $lanName = null) : void{
		$this->lanName = $lanName;
		foreach($this->interfaces as $interface){
						if($interface instanceof RakLibInterface){
								$interface->internalSetName($this->name, $this->lanName);
						} else {
								$interface->setName($this->name);
								$interface->setLanName($this->lanName);
						}
				}
	}

	public function getName() : string{
		return $this->name;
	}

	/**
	 * This returns the of lan motd.
	 */
	public function getLanName() : string{
		return ($this->lanName ?? Server::getInstance()->getName());
	}

	public function updateName() : void{
		foreach($this->interfaces as $interface){
			$interface->setName($this->name);
			$interface->setLanName($this->lanName);
		}
	}

	public function sendPacket(string $address, int $port, string $payload) : void{
		foreach($this->advancedInterfaces as $interface){
			$interface->sendRawPacket($address, $port, $payload);
		}
	}

	/**
	 * Blocks an IP address from the main interface. Setting timeout to -1 will block it forever
	 */
	public function blockAddress(string $address, int $timeout = 300) : void{
		$this->bannedIps[$address] = $timeout > 0 ? time() + $timeout : PHP_INT_MAX;
		foreach($this->advancedInterfaces as $interface){
			$interface->blockAddress($address, $timeout);
		}
	}

	public function unblockAddress(string $address) : void{
		unset($this->bannedIps[$address]);
		foreach($this->advancedInterfaces as $interface){
			$interface->unblockAddress($address);
		}
	}

	/**
	 * Registers a raw packet handler on the network.
	 */
	public function registerRawPacketHandler(RawPacketHandler $handler) : void{
		$this->rawPacketHandlers[spl_object_id($handler)] = $handler;

		$regex = $handler->getPattern();
		foreach($this->advancedInterfaces as $interface){
			$interface->addRawPacketFilter($regex);
		}
	}

	/**
	 * Unregisters a previously-registered raw packet handler.
	 */
	public function unregisterRawPacketHandler(RawPacketHandler $handler) : void{
		unset($this->rawPacketHandlers[spl_object_id($handler)]);
	}

	public function processRawPacket(AdvancedNetworkInterface $interface, string $address, int $port, string $packet) : void{
		if(isset($this->bannedIps[$address]) && time() < $this->bannedIps[$address]){
			$this->logger->debug("Dropped raw packet from banned address $address $port");
			return;
		}
		$handled = false;
		foreach($this->rawPacketHandlers as $handler){
			if(preg_match($handler->getPattern(), $packet) === 1){
				try{
					$handled = $handler->handle($interface, $address, $port, $packet);
				}catch(PacketHandlingException $e){
					$handled = true;
					$this->logger->error("Bad raw packet from /$address:$port: " . $e->getMessage());
					$this->blockAddress($address, 600);
					break;
				}
			}
		}
		if(!$handled){
			$this->logger->debug("Unhandled raw packet from /$address:$port: " . base64_encode($packet));
		}
	}
}
