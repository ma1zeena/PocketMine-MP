<?php

/*

           -
         /   \
      /         \
   /   PocketMine  \
/          MP         \
|\     @shoghicp     /|
|.   \           /   .|
| ..     \   /     .. |
|    ..    |    ..    |
|       .. | ..       |
\          |          /
   \       |       /
      \    |    /
         \ | /

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Lesser General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.


*/


class Player{
	private $server;
	private $buffer = "";
	private $nextBuffer = 0;
	public $recovery = array();
	private $evid = array();
	private $lastMovement = 0;
	private $timeout;
	private $connected = true;
	private $clientID;
	private $ip;
	private $port;
	private $counter = array(0, 0, 0, 0);
	private $username;
	private $iusername;
	private $eid = false;
	private $startAction = false;
	private $queue = array();
	private $need = array();
	public $data;
	public $entity = false;
	public $auth = false;
	public $CID;
	public $MTU;
	public $spawned = false;
	public $inventory;
	public $slot;
	public $armor = array();
	public $loggedIn = false;
	public $gamemode;
	public $lastBreak;
	public $windowCnt = 2;
	public $windows = array();
	public $blocked = true;
	public $chunksLoaded = array();
	private $chunksOrder = array();
	private $lastMeasure = 0;
	private $bandwidthRaw = 0;
	private $bandwidthStats = array(0, 0, 0);
	private $lag = array();
	private $lagStat = 0;
	private $spawnPosition;
	private $packetLoss = 0;
	public $lastCorrect;
	private $bigCnt;
	private $packetStats;
	public $craftingItems = array();
	public $toCraft = array();
	public $lastCraft = 0;
	
	public function __get($name){
		if(isset($this->{$name})){
			return ($this->{$name});
		}
		return null;
	}
	
	public function __construct($clientID, $ip, $port, $MTU){
		$this->bigCnt = 0;
		$this->MTU = $MTU;
		$this->server = ServerAPI::request();
		$this->lastBreak = microtime(true);
		$this->clientID = $clientID;
		$this->CID = $this->server->clientID($ip, $port);
		$this->ip = $ip;
		$this->port = $port;
		$this->spawnPosition = $this->server->spawn;
		$this->timeout = microtime(true) + 20;
		$this->inventory = array();
		$this->armor = array();
		$this->gamemode = $this->server->gamemode;
		$this->level = $this->server->api->level->getDefault();
		$this->slot = 0;
		$this->packetStats = array(0,0);
		$this->server->schedule(2, array($this, "onTick"), array(), true);
		$this->evid[] = $this->server->event("server.close", array($this, "close"));
		console("[DEBUG] New Session started with ".$ip.":".$port.". MTU ".$this->MTU.", Client ID ".$this->clientID, true, true, 2);
	}
	
	public function getSpawn(){
		return $this->spawnPosition;
	}
	
	public function setSpawn(Vector3 $pos){
		if(!($pos instanceof Level)){
			$level = $this->level;
		}else{
			$level = $pos->level;
		}
		$this->spawnPosition = new Position($pos->x, $pos->y, $pos->z, $level);
		$this->dataPacket(MC_SET_SPAWN_POSITION, array(
			"x" => (int) $this->spawnPosition->x,
			"y" => (int) $this->spawnPosition->y,
			"z" => (int) $this->spawnPosition->z,
		));
	}
	
	public function orderChunks(){
		if(!($this->entity instanceof Entity) or $this->connected === false){
			return false;
		}
		$X = $this->entity->x / 16;
		$Z = $this->entity->z / 16;
		$Y = $this->entity->y / 16;
		
		$this->chunksOrder = array();
		for($y = 0; $y < 8; ++$y){
			$distY = abs($Y - $y) / 4;
			for($x = 0; $x < 16; ++$x){
				$distX = abs($X - $x);
				for($z = 0; $z < 16; ++$z){
					$distZ = abs($Z - $z);				
					$d = $x.":".$y.":".$z;
					if(!isset($this->chunksLoaded[$d])){
						$this->chunksOrder[$d] = $distX + $distY + $distZ;
					}
				}
			}
		}
		asort($this->chunksOrder);
	}
	
	public function getNextChunk($repeat = false){
		if($this->connected === false){
			return false;
		}

		$c = key($this->chunksOrder);
		$d = @$this->chunksOrder[$c];
		if($c === null or $d > $this->server->api->getProperty("view-distance")){
			$this->server->schedule(50, array($this, "getNextChunk"));
			return false;
		}
		unset($this->chunksOrder[$c]);
		$this->chunksLoaded[$c] = true;
		$id = explode(":", $c);
		$X = $id[0];
		$Z = $id[2];
		$Y = $id[1];
		$x = $X << 4;
		$z = $Z << 4;
		$y = $Y << 4;
		$this->level->useChunk($X, $Z, $this);
		$this->dataPacket(MC_CHUNK_DATA, array(
			"x" => $X,
			"z" => $Z,
			"data" => $this->level->getOrderedMiniChunk($X, $Z, $Y),
		));
		
		$tiles = $this->server->query("SELECT ID FROM tiles WHERE spawnable = 1 AND level = '".$this->level->getName()."' AND x >= ".($x - 1)." AND x < ".($x + 17)." AND z >= ".($z - 1)." AND z < ".($z + 17)." AND y >= ".($y - 1)." AND y < ".($y + 17).";");
		if($tiles !== false and $tiles !== true){
			while(($tile = $tiles->fetchArray(SQLITE3_ASSOC)) !== false){
				$tile = $this->server->api->tile->getByID($tile["ID"]);
				if($tile instanceof Tile){
					$tile->spawn($this);
				}
			}
		}
		
		if($repeat === false){
			$this->getNextChunk(true);
		}
		
		$this->server->schedule(1, array($this, "getNextChunk"));
	}

	public function onTick(){
		if($this->connected === false){
			return false;
		}
		$time = microtime(true);
		if($time > $this->timeout){
			$this->close("timeout");
		}else{
			if($this->nextBuffer <= $time and strlen($this->buffer) > 0){
				$this->sendBuffer();
			}
		}
	}

	public function save(){
		if($this->entity instanceof Entity){
			$this->data->set("position", array(
				"level" => $this->entity->level->getName(),
				"x" => $this->entity->x,
				"y" => $this->entity->y,
				"z" => $this->entity->z,
			));
			$this->data->set("spawn", array(
				"level" => $this->spawnPosition->level->getName(),
				"x" => $this->spawnPosition->x,
				"y" => $this->spawnPosition->y,
				"z" => $this->spawnPosition->z,
			));
			$inv = array();			
			foreach($this->inventory as $slot => $item){
				$inv[$slot] = array($item->getID(), $item->getMetadata(), $item->count);
			}
			$this->data->set("inventory", $inv);
			
			$armor = array();
			foreach($this->armor as $slot => $item){
				$armor[$slot] = array($item->getID(), $item->getMetadata());
			}
			$this->data->set("armor", $armor);
			$this->data->set("gamemode", $this->gamemode);
		}
	}

	public function close($reason = "", $msg = true){
		if($this->connected === true){
			foreach($this->evid as $ev){
				$this->server->deleteEvent($ev);
			}
			if($this->username != ""){
				$this->server->api->handle("player.quit", $this);
				$this->save();
			}
			$reason = $reason == "" ? "server stop":$reason;			
			$this->eventHandler(new Container("You have been kicked. Reason: ".$reason), "server.chat");
			$this->directDataPacket(MC_DISCONNECT);
			$this->sendBuffer();
			$this->level->freeAllChunks($this);
			$this->buffer = null;
			unset($this->buffer);
			$this->recovery = null;
			unset($this->recovery);
			$this->connected = false;
			if($msg === true and $this->username != ""){
				$this->server->api->chat->broadcast($this->username." left the game");
			}
			console("[INFO] \x1b[33m".$this->username."\x1b[0m[/".$this->ip.":".$this->port."] logged out due to ".$reason);
			$this->server->api->player->remove($this->CID);
		}
	}
	
	public function hasSpace($type, $damage, $count){
		$inv = $this->inventory;
		while($count > 0){
			$add = 0;
			foreach($inv as $s => $item){
				if($item->getID() === AIR){
					$add = min(64, $count);
					$inv[$s] = BlockAPI::getItem($type, $damage, $add);
					break;
				}elseif($item->getID() === $type and $item->getMetadata() === $damage){
					$add = min(64 - $item->count, $count);
					if($add <= 0){
						continue;
					}
					$inv[$s] = BlockAPI::getItem($type, $damage, $item->count + $add);
					break;
				}
			}
			if($add === 0){
				return false;
			}
			$count -= $add;
		}
		return true;
	}

	public function addItem($type, $damage, $count, $send = true){
		while($count > 0){
			$add = 0;
			foreach($this->inventory as $s => $item){
				if($item->getID() === AIR){
					$add = min(64, $count);
					$this->inventory[$s] = BlockAPI::getItem($type, $damage, $add);
					if($send === true){
						$this->sendInventorySlot($s);
					}
					break;
				}elseif($item->getID() === $type and $item->getMetadata() === $damage){
					$add = min(64 - $item->count, $count);
					if($add <= 0){
						continue;
					}
					$item->count += $add;
					if($send === true){
						$this->sendInventorySlot($s);
					}
					break;
				}
			}
			if($add === 0){
				return false;
			}
			$count -= $add;
		}
		return true;
	}

	public function removeItem($type, $damage, $count, $send = true){
		while($count > 0){
			$remove = 0;
			foreach($this->inventory as $s => $item){
				if($item->getID() === $type and $item->getMetadata() === $damage){
					$remove = min($count, $item->count);
					if($remove < $item->count){
						$item->count -= $remove;
					}else{
						$this->inventory[$s] = BlockAPI::getItem(AIR, 0, 0);
					}
					if($send === true){
						$this->sendInventorySlot($s);
					}
					break;
				}
			}
			if($remove === 0){
				return false;
			}
			$count -= $remove;
		}
		return true;
	}
	
	public function setSlot($slot, Item $item, $send = true){
		$this->inventory[(int) $slot] = $item;
		if($send === true){
			$this->sendInventorySlot((int) $slot);
		}
		return true;
	}
	
	public function getSlot($slot){
		if(isset($this->inventory[(int) $slot])){
			return $this->inventory[(int) $slot];
		}else{
			return BlockAPI::getItem(AIR, 0, 0);
		}
	}
	
	public function sendInventorySlot($s){
		$this->sendInventory();
		return;
		$s = (int) $s;
		if(!isset($this->inventory[$s])){
			$this->dataPacket(MC_CONTAINER_SET_SLOT, array(
				"windowid" => 0,
				"slot" => (int) $s,
				"block" => AIR,
				"stack" => 0,
				"meta" => 0,
			));
		}
		
		$slot = $this->inventory[$s];
		$this->dataPacket(MC_CONTAINER_SET_SLOT, array(
			"windowid" => 0,
			"slot" => (int) $s,
			"block" => $slot->getID(),
			"stack" => $slot->count,
			"meta" => $slot->getMetadata(),
		));
		return true;
	}
	
	public function hasItem($type, $damage = false){
		foreach($this->inventory as $s => $item){
			if($item->getID() === $type and ($item->getMetadata() === $damage or $damage === false) and $item->count > 0){
				return $s;
			}
		}
		return false;
	}
	
	public function eventHandler($data, $event){
		switch($event){
			case "tile.update":
				if($data->level === $this->level){
					if($data->class === TILE_FURNACE){
						foreach($this->windows as $id => $w){
							if($w === $data){
								$this->dataPacket(MC_CONTAINER_SET_DATA, array(
									"windowid" => $id,
									"property" => 0, //Smelting
									"value" => floor($data->data["CookTime"]),
								));
								$this->dataPacket(MC_CONTAINER_SET_DATA, array(
									"windowid" => $id,
									"property" => 1, //Fire icon
									"value" => $data->data["BurnTicks"],
								));
							}
						}
					}elseif($data->class === TILE_SIGN){
						$data->spawn($this);
					}
				}
				break;
			case "tile.container.slot":
				if($data["tile"]->level === $this->level){
					foreach($this->windows as $id => $w){
						if($w === $data["tile"]){
							$this->dataPacket(MC_CONTAINER_SET_SLOT, array(
								"windowid" => $id,
								"slot" => $data["slot"],
								"block" => $data["slotdata"]->getID(),
								"stack" => $data["slotdata"]->count,
								"meta" => $data["slotdata"]->getMetadata(),
							));
						}
					}
				}
				break;
			case "player.armor":
				if($data["player"]->level === $this->level){
					if($data["eid"] === $this->eid){
						$data["eid"] = 0;
					}
					$this->dataPacket(MC_PLAYER_ARMOR_EQUIPMENT, $data);
				}
				break;
			case "player.pickup":
				if($data["eid"] === $this->eid){
					$data["eid"] = 0;
					$this->dataPacket(MC_TAKE_ITEM_ENTITY, $data);
					if(($this->gamemode & 0x01) === 0x00){
						$this->addItem($data["entity"]->type, $data["entity"]->meta, $data["entity"]->stack, false);
					}
				}elseif($data["entity"]->level === $this->level){
					$this->dataPacket(MC_TAKE_ITEM_ENTITY, $data);
				}
				break;
			case "player.equipment.change":
				if($data["eid"] === $this->eid or $data["player"]->level !== $this->level){
					break;
				}
				$data["slot"] = 0;
				$this->dataPacket(MC_PLAYER_EQUIPMENT, $data);

				break;
			case "entity.move":
				if($data->eid === $this->eid or $data->level !== $this->level){
					break;
				}
				$this->dataPacket(MC_MOVE_ENTITY_POSROT, array(
					"eid" => $data->eid,
					"x" => $data->x,
					"y" => $data->y,
					"z" => $data->z,
					"yaw" => $data->yaw,
					"pitch" => $data->pitch,
				));
				break;
			case "entity.motion":
				if($data->eid === $this->eid or $data->level !== $this->level){
					break;
				}
				$this->dataPacket(MC_SET_ENTITY_MOTION, array(
					"eid" => $data->eid,
					"speedX" => (int) ($data->speedX * 400),
					"speedY" => (int) ($data->speedY * 400),
					"speedZ" => (int) ($data->speedZ * 400),
				));
				break;
			case "entity.remove":
				if($data->eid === $this->eid or $data->level !== $this->level){
					break;
				}
				$this->dataPacket(MC_REMOVE_ENTITY, array(
					"eid" => $data->eid,
				));
				break;
			case "entity.animate":
				if($data["eid"] === $this->eid or $data["entity"]->level !== $this->level){
					break;
				}
				$this->dataPacket(MC_ANIMATE, array(
					"eid" => $data["eid"],
					"action" => $data["action"], //1 swing arm,
				));
				break;
			case "entity.metadata":
				if($data->eid === $this->eid){
					$eid = 0;
				}else{
					$eid = $data->eid;
				}
				if($data->level === $this->level){
					$this->dataPacket(MC_SET_ENTITY_DATA, array(
						"eid" => $eid,
						"metadata" => $data->getMetadata(),
					));
				}
				break;
			case "entity.event":
				if($data["entity"]->eid === $this->eid){
					$eid = 0;
				}else{
					$eid = $data["entity"]->eid;
				}
				if($data["entity"]->level === $this->level){
					$this->dataPacket(MC_ENTITY_EVENT, array(
						"eid" => $eid,
						"event" => $data["event"],
					));
				}
				break;
			case "server.chat":
				if(($data instanceof Container) === true){
					if(!$data->check($this->username) and !$data->check($this->iusername)){
						return;
					}else{
						$message = $data->get();
					}
				}else{
					$message = (string) $data;
				}
				$this->sendChat(preg_replace('/\x1b\[[0-9;]*m/', "", $message)); //Remove ANSI codes from chat
				break;
		}
	}
	
	public function sendChat($message){
		$mes = explode("\n", $message);
		foreach($mes as $m){
			if(preg_match_all('#@([@A-Za-z_]{1,})#', $m, $matches, PREG_OFFSET_CAPTURE) > 0){
				$offsetshift = 0;
				foreach($matches[1] as $selector){
					if($selector[0]{0} === "@"){ //Escape!
						$m = substr_replace($m, $selector[0], $selector[1] + $offsetshift - 1, strlen($selector[0]) + 1);
						--$offsetshift;
						continue;
					}
					switch(strtolower($selector[0])){
						case "player":
						case "username":
							$m = substr_replace($m, $this->username, $selector[1] + $offsetshift - 1, strlen($selector[0]) + 1);
							$offsetshift += strlen($selector[0]) - strlen($this->username) + 1;
							break;
					}
				}
			}
			$this->dataPacket(MC_CHAT, array(				
				"message" => $m,
			));	
		}
	}
	
	public function sendSettings($nametags = true){
		/*
		 bit mask | flag name
		0x00000001 world_inmutable
		0x00000002 -
		0x00000004 -
		0x00000008 - (autojump)
		0x00000010 -
		0x00000020 nametags_visible
		0x00000040 ?
		0x00000080 ?
		0x00000100 ?
		0x00000200 ?
		0x00000400 ?
		0x00000800 ?
		0x00001000 ?
		0x00002000 ?
		0x00004000 ?
		0x00008000 ?
		0x00010000 ?
		0x00020000 ?
		0x00040000 ?
		0x00080000 ?
		0x00100000 ?
		0x00200000 ?
		0x00400000 ?
		0x00800000 ?
		0x01000000 ?
		0x02000000 ?
		0x04000000 ?
		0x08000000 ?
		0x10000000 ?
		0x20000000 ?
		0x40000000 ?
		0x80000000 ?
		*/
		$flags = 0;
		if(($this->gamemode & 0x02) === 0x02){
			$flags |= 0x01; //Not allow placing/breaking blocks, adventure mode
		}
		
		if($nametags !== false){
			$flags |= 0x20; //Show Nametags
		}

		$this->dataPacket(MC_ADVENTURE_SETTINGS, array(
			"flags" => $flags,
		));
	}
	
	public function craftItems(array $craft, array $recipe, $type){
		$craftItem = array(0, true, 0);
		unset($craft[-1]);
		foreach($craft as $slot => $item){
			if($item instanceof Item){
				$craftItem[0] = $item->getID();
				if($item->getMetadata() !== $craftItem[1] and $craftItem[1] !== true){
					$craftItem[1] = false;
				}else{
					$craftItem[1] = $item->getMetadata();
				}
				$craftItem[2] += $item->count;
			}
			
		}

		$recipeItems = array();
		foreach($recipe as $slot => $item){
			if(!isset($recipeItems[$item->getID()])){
				$recipeItems[$item->getID()] = array($item->getID(), $item->getMetadata(), $item->count);
			}else{
				if($item->getMetadata() !== $recipeItems[$item->getID()][1]){
					$recipeItems[$item->getID()][1] = false;
				}
				$recipeItems[$item->getID()][2] += $item->count;
			}
		}
	
		$res = CraftingRecipes::canCraft($craftItem, $recipeItems, $type);

		if(!is_array($res) and $type === 1){
			$res2 = CraftingRecipes::canCraft($craftItem, $recipeItems, 0);
			if(is_array($res2)){
				$res = $res2;
			}
		}
		
		if(is_array($res)){
			foreach($recipe as $slot => $item){
				$s = $this->getSlot($slot);
				$s->count -= $item->count;
				if($s->count <= 0){				
					$this->setSlot($slot, BlockAPI::getItem(AIR, 0, 0));
				}
			}
			foreach($craft as $slot => $item){
				$s = $this->getSlot($slot);				
				if($s->count <= 0 or $s->getID() === AIR){				
					$this->setSlot($slot, BlockAPI::getItem($item->getID(), $item->getMetadata(), $item->count));
				}else{
					$this->setSlot($slot, BlockAPI::getItem($item->getID(), $item->getMetadata(), $s->count + $item->count));
				}
			}
		}
		return $res;
	}
	
	public function teleport(Vector3 $pos, $yaw = false, $pitch = false, $terrain = true){
		if($this->entity instanceof Entity){
			$this->entity->check = false;
			if($yaw === false){
				$yaw = $this->entity->yaw;
			}
			if($pitch === false){
				$pitch = $this->entity->pitch;
			}
			if($this->server->api->dhandle("player.teleport", array("player" => $this, "target" => $pos)) === false){
				$this->entity->check = true;
				return false;
			}
			
			if($pos instanceof Position and $pos->level !== $this->level){
				if($this->server->api->dhandle("player.teleport.level", array("player" => $this, "origin" => $this->level, "target" => $pos->level)) === false){
					$this->entity->check = true;
					return false;
				}
				foreach($this->server->api->entity->getAll($this->level) as $e){
					if($e !== $this->entity){
						if($e->player instanceof Player){
							$e->player->dataPacket(MC_REMOVE_ENTITY, array(
								"eid" => $this->eid,
							));
						}
						$this->dataPacket(MC_REMOVE_ENTITY, array(
							"eid" => $e->eid,
						));
					}
				}
				$this->level->freeAllChunks($this);
				$this->level = $pos->level;
				$this->chunksLoaded = array();
				$this->server->api->entity->spawnToAll($this->entity);
				$this->server->api->entity->spawnAll($this);
				$terrain = true;
			}
			$this->lastCorrect = $pos;
			$this->entity->fallY = false;
			$this->entity->fallStart = false;
			$this->entity->setPosition($pos, $yaw, $pitch);
			$this->entity->resetSpeed();
			$this->entity->updateLast();
			$this->entity->calculateVelocity();
			if($terrain === true){
				$this->orderChunks();
				$this->getNextChunk();
			}
			$this->entity->check = true;
		}
		$this->dataPacket(MC_MOVE_PLAYER, array(
			"eid" => 0,
			"x" => $pos->x,
			"y" => $pos->y,
			"z" => $pos->z,
			"yaw" => $yaw,
			"pitch" => $pitch,
		));
	}
	
	public function getGamemode(){
		switch($this->gamemode){
			case SURVIVAL:
				return "survival";
			case CREATIVE:
				return "creative";
			case ADVENTURE:
				return "adventure";
			case VIEW:
				return "view";
		}
	}
	
	public function setGamemode($gm){
		if($gm < 0 or $gm > 3 or $this->gamemode === $gm){
			return false;
		}
		
		if($this->server->api->dhandle("player.gamemode.change", array("player" => $this, "gamemode" => $gm)) === false){
			return false;
		}
		
		$inv =& $this->inventory;
		if(($this->gamemode & 0x01) === ($gm & 0x01)){			
			if(($gm & 0x01) === 0x01 and ($gm & 0x02) === 0x02){
				$inv = array();
				foreach(BlockAPI::$creative as $item){
					$inv[] = BlockAPI::getItem(DANDELION, 0, 1);
				}
			}elseif(($gm & 0x01) === 0x01){
				$inv = array();
				foreach(BlockAPI::$creative as $item){
					$inv[] = BlockAPI::getItem($item[0], $item[1], 1);
				}
			}
			$this->gamemode = $gm;
			$this->eventHandler("Your gamemode has been changed to ".$this->getGamemode().".", "server.chat");
		}else{
			foreach($this->inventory as $slot => $item){
				$inv[$slot] = BlockAPI::getItem(AIR, 0, 0);
			}
			$this->blocked = true;
			$this->gamemode = $gm;
			$this->eventHandler("Your gamemode has been changed to ".$this->getGamemode().", you've to do a forced reconnect.", "server.chat");
			$this->server->schedule(30, array($this, "close"), "gamemode change"); //Forces a kick
		}
		$this->inventory = $inv;
		$this->sendSettings();
		$this->sendInventory();
		return true;
	}
	
	public function measureLag(){
		if($this->connected === false){
			return false;
		}
		if($this->packetStats[1] > 2){
			$this->packetLoss = $this->packetStats[1] / max(1, $this->packetStats[0]);
		}else{
			$this->packetLoss = 0;
		}
		$this->packetStats = array(0, 0);
		array_shift($this->bandwidthStats);
		$this->bandwidthStats[] = $this->bandwidthRaw / max(0.00001, microtime(true) - $this->lastMeasure);
		$this->bandwidthRaw = 0;
		$this->lagStat = array_sum($this->lag) / max(1, count($this->lag));
		$this->lag = array();
		$this->sendBuffer();
		if($this->packetLoss >= PLAYER_MAX_PACKET_LOSS){
			//$this->sendChat("Your connection suffers high packet loss");
			//$this->close("packet.loss");
		}
		$this->lastMeasure = microtime(true);
	}
	
	public function getLag(){
		return $this->lagStat * 1000;
	}
	
	public function getPacketLoss(){
		return $this->packetLoss;
	}
	
	public function getBandwidth(){
		return array_sum($this->bandwidthStats) / max(1, count($this->bandwidthStats));
	}

	public function handle($pid, $data){
		if($this->connected === true){
			$this->timeout = microtime(true) + 20;
			switch($pid){
				case 0xa0: //NACK
					foreach($data[0] as $count){
						if(isset($this->recovery[$count])){
							$this->directDataPacket($this->recovery[$count]["id"], $this->recovery[$count], $this->recovery[$count]["pid"]);
							++$this->packetStats[1];
							$this->lag[] = microtime(true) - $this->recovery[$count]["sendtime"];
							unset($this->recovery[$count]);
						}
					}
					break;
				case 0xc0: //ACK
					foreach($data[0] as $count){
						if($count > $this->counter[2]){
							$this->counter[2] = $count;
						}						
						if(isset($this->recovery[$count])){
							$this->lag[] = microtime(true) - $this->recovery[$count]["sendtime"];
							unset($this->recovery[$count]);
						}
					}
					$limit = microtime(true) - 6; //max lag
					foreach($this->recovery as $count => $d){
						$diff = $this->counter[2] - $count;
						if($diff > 16 and $d["sendtime"] < $limit){
							$this->directDataPacket($d["id"], $d, $d["pid"]);
							++$this->packetStats[1];
							$this->lag[] = microtime(true) - $d["sendtime"];
							unset($this->recovery[$count]);
						}
					}
					break;
				case 0x07:
					if($this->loggedIn === true){
						break;
					}
					$this->send(0x08, array(
						RAKNET_MAGIC,
						$this->server->serverID,
						$this->port,
						$data[3],
						0,
					));
					break;
				case 0x80:
				case 0x81:
				case 0x82:
				case 0x83:
				case 0x84:
				case 0x85:
				case 0x86:
				case 0x87:
				case 0x88:
				case 0x89:
				case 0x8a:
				case 0x8b:
				case 0x8c:
				case 0x8d:
				case 0x8e:
				case 0x8f:
					if(isset($data[0])){
						$this->send(0xc0, array(array($data[0])));
						$diff = $data[0] - $this->counter[1];
						unset($this->need[$data[0]]);
						if($diff > 1){ //Packet recovery
							$arr = array();
							for($i = $this->counter[1]; $i < $data[0]; ++$i){
								$arr[] = $i;
								$this->need[$i] = true;
							}
							$this->send(0xa0, $arr);
							$this->queue[$data[0]] = array($pid, $data);
							break;
						}elseif($diff === 1){
							$this->counter[1] = $data[0];
							++$this->packetStats[0];
						}
					}
					
					if(!isset($data["id"])){
						break;
					}
					switch($data["id"]){
						case 0x01:
							break;
						case MC_PONG:
							break;
						case MC_PING:
							$t = (int) (microtime(true) * 1000);
							$this->dataPacket(MC_PONG, array(
								"ptime" => $data["time"],
								"time" => (int) (microtime(true) * 1000),
							));
							$this->sendBuffer();
							break;
						case MC_DISCONNECT:
							$this->close("client disconnect");
							break;
						case MC_CLIENT_CONNECT:
							if($this->loggedIn === true){
								break;
							}
							$this->dataPacket(MC_SERVER_HANDSHAKE, array(
								"port" => $this->port,
								"session" => $data["session"],
								"session2" => Utils::readLong("\x00\x00\x00\x00\x04\x44\x0b\xa9"),
							));
							break;
						case MC_CLIENT_HANDSHAKE:
							if($this->loggedIn === true){
								break;
							}
							break;
						case MC_LOGIN:
							if($this->loggedIn === true){
								break;
							}
							if(count($this->server->clients) >= $this->server->maxClients){
								$this->close("server is full!", false);
								return;
							}					
							if($data["protocol1"] !== CURRENT_PROTOCOL){
								if($data["protocol1"] < CURRENT_PROTOCOL){
									$this->directDataPacket(MC_LOGIN_STATUS, array(
										"status" => 1,
									));
								}else{
									$this->directDataPacket(MC_LOGIN_STATUS, array(
										"status" => 2,
									));
								}
								$this->close("Incorrect protocol #".$data["protocol1"], false);
								break;
							}
							if(preg_match('#[^a-zA-Z0-9_]#', $data["username"]) == 0){
								$this->username = $data["username"];
								$this->iusername = strtolower($this->username);
							}else{
								$this->close("Bad username", false);
								break;
							}
							if($this->server->api->handle("player.connect", $this) === false){
								$this->close("Unknown reason", false);
								return;
							}
							
							if($this->server->whitelist === true and !$this->server->api->ban->inWhitelist($this->iusername)){
								$this->close("Server is white-listed", false);
								return;
							}elseif($this->server->api->ban->isBanned($this->iusername) or $this->server->api->ban->isIPBanned($this->ip)){
								$this->close("You are banned!", false);
								return;
							}
							$this->loggedIn = true;
							
							$u = $this->server->api->player->get($this->iusername);
							if($u !== false){
								$u->close("logged in from another location");
							}
							
							$this->server->api->player->add($this->CID);
							if($this->server->api->handle("player.join", $this) === false){
								$this->close("join cancelled", false);
								return;
							}
							
							if(!($this->data instanceof Config)){
								$u->close("no config created", false);
								return;
							}
							
							$this->auth = true;
							if(!$this->data->exists("inventory") or ($this->gamemode & 0x01) === 0x01){
								if(($this->gamemode & 0x01) === 0x01){
									$inv = array();
									if(($this->gamemode & 0x02) === 0x02){
										foreach(BlockAPI::$creative as $item){
											$inv[] = array(DANDELION, 0, 1);
										}
									}else{
										foreach(BlockAPI::$creative as $item){
											$inv[] = array($item[0], $item[1], 1);
										}
									}
								}
								$this->data->set("inventory", $inv);
							}
							$this->data->set("caseusername", $this->username);
							$this->inventory = array();							
							foreach($this->data->get("inventory") as $slot => $item){
								$this->inventory[$slot] = BlockAPI::getItem($item[0], $item[1], $item[2]);
							}

							$this->armor = array();					
							foreach($this->data->get("armor") as $slot => $item){
								$this->armor[$slot] = BlockAPI::getItem($item[0], $item[1], 1);
							}
							
							$this->data->set("lastIP", $this->ip);
							$this->data->set("lastID", $this->clientID);

							if($this->data instanceof Config){
								$this->server->api->player->saveOffline($this->data);
							}
							$this->dataPacket(MC_LOGIN_STATUS, array(
								"status" => 0,
							));
							$this->dataPacket(MC_START_GAME, array(
								"seed" => $this->level->getSeed(),
								"x" => $this->data->get("position")["x"],
								"y" => $this->data->get("position")["y"],
								"z" => $this->data->get("position")["z"],
								"unknown1" => 0,
								"gamemode" => ($this->gamemode & 0x01),
								"eid" => 0,
							));
							if(($this->gamemode & 0x01) === 0x01){
								$this->slot = 7;
							}else{
								$this->slot = 0;
							}
							$this->entity = $this->server->api->entity->add($this->level, ENTITY_PLAYER, 0, array("player" => $this));
							$this->eid = $this->entity->eid;
							$this->server->query("UPDATE players SET EID = ".$this->eid." WHERE clientID = ".$this->clientID.";");
							$this->entity->x = $this->data->get("position")["x"];
							$this->entity->y = $this->data->get("position")["y"];
							$this->entity->z = $this->data->get("position")["z"];
							$this->entity->check = false;
							$this->entity->setName($this->username);
							$this->entity->data["clientID"] = $this->clientID;
							$this->evid[] = $this->server->event("server.chat", array($this, "eventHandler"));
							$this->evid[] = $this->server->event("entity.remove", array($this, "eventHandler"));
							$this->evid[] = $this->server->event("entity.move", array($this, "eventHandler"));
							$this->evid[] = $this->server->event("entity.motion", array($this, "eventHandler"));
							$this->evid[] = $this->server->event("entity.animate", array($this, "eventHandler"));
							$this->evid[] = $this->server->event("entity.event", array($this, "eventHandler"));
							$this->evid[] = $this->server->event("entity.metadata", array($this, "eventHandler"));
							$this->evid[] = $this->server->event("player.equipment.change", array($this, "eventHandler"));
							$this->evid[] = $this->server->event("player.armor", array($this, "eventHandler"));
							$this->evid[] = $this->server->event("player.pickup", array($this, "eventHandler"));
							$this->evid[] = $this->server->event("tile.container.slot", array($this, "eventHandler"));
							$this->evid[] = $this->server->event("tile.update", array($this, "eventHandler"));
							$this->lastMeasure = microtime(true);
							$this->server->schedule(50, array($this, "measureLag"), array(), true);
							console("[INFO] \x1b[33m".$this->username."\x1b[0m[/".$this->ip.":".$this->port."] logged in with entity id ".$this->eid." at (".$this->entity->level->getName().", ".round($this->entity->x, 2).", ".round($this->entity->y, 2).", ".round($this->entity->z, 2).")");
							break;
						case MC_READY:
							if($this->loggedIn === false){
								break;
							}
							switch($data["status"]){
								case 1: //Spawn!!
									if($this->spawned !== false){
										break;
									}
									$this->spawned = true;						
									$this->server->api->entity->spawnAll($this);
									$this->server->api->entity->spawnToAll($this->entity);
									$this->server->schedule(5, array($this->entity, "update"), array(), true);
									$this->sendArmor();
									$this->eventHandler(new Container($this->server->motd), "server.chat");
									if($this->MTU <= 548){
										$this->eventHandler("Your connection is bad, you may experience lag and slow map loading.", "server.chat");
									}
									
									if($this->iusername === "steve" or $this->iusername === "stevie"){
										$this->eventHandler("You're using the default username. Please change it on the Minecraft PE settings.", "server.chat");
									}
									$this->sendInventory();
									$this->sendSettings();
									$this->server->schedule(50, array($this, "orderChunks"), array(), true);
									$this->blocked = false;
									$this->teleport(new Position($this->data->get("position")["x"], $this->data->get("position")["y"], $this->data->get("position")["z"], $this->level));
									$this->server->handle("player.spawn", $this);
									break;
								case 2://Chunk loaded?
									break;
							}
							break;
						case MC_MOVE_PLAYER:
							if($this->spawned === false){
								break;
							}
							if(($this->entity instanceof Entity) and $data["counter"] > $this->lastMovement){
								$this->lastMovement = $data["counter"];
								$speed = $this->entity->getSpeed();
								if($this->blocked === true or ($this->server->api->getProperty("allow-flight") !== true and (($speed > 6 and ($this->gamemode & 0x01) === 0x00) or $speed > 15)) or $this->server->api->handle("player.move", $this->entity) === false){
									if($this->lastCorrect instanceof Vector3){
										$this->teleport($this->lastCorrect, $this->entity->yaw, $this->entity->pitch, false);
									}
									if($this->blocked !== true){
										console("[WARNING] ".$this->username." moved too quickly!");
									}
								}else{
									$this->entity->setPosition(new Vector3($data["x"], $data["y"], $data["z"]), $data["yaw"], $data["pitch"]);
								}
							}
							break;
						case MC_PLAYER_EQUIPMENT:
							if($this->spawned === false){
								break;
							}
							$data["eid"] = $this->eid;
							$data["player"] = $this;
							
							if($data["slot"] === 0){
								$data["slot"] = -1;
								$data["item"] = BlockAPI::getItem(AIR, 0, 0);
								if($this->server->handle("player.equipment.change", $data) !== false){
									$this->slot = -1;
								}
								break;
							}else{
								$data["slot"] -= 9;
							}
							
							$data["item"] = $this->getSlot($data["slot"]);
							if(!($data["item"] instanceof Item)){
								break;
							}
							$data["block"] = $data["item"]->getID();
							$data["meta"] = $data["item"]->getMetadata();
							if($this->server->handle("player.equipment.change", $data) !== false){
								$this->slot = $data["slot"];
							}else{
								$this->sendInventorySlot($data["slot"]);
							}
							if($this->entity->inAction === true){
								$this->entity->inAction = false;
								$this->entity->updateMetadata();
							}
							break;
						case MC_REQUEST_CHUNK:
							if($this->spawned === false){
								break;
							}
							break;
						case MC_USE_ITEM:
							if($this->spawned === false){
								break;
							}
							$this->craftingItems = array();
							$this->toCraft = array();
							$data["eid"] = $this->eid;
							$data["player"] = $this;
							if($data["face"] >= 0 and $data["face"] <= 5){ //Use Block, place
								if($this->entity->inAction === true){
									$this->entity->inAction = false;
									$this->entity->updateMetadata();
								}
								if($this->blocked === true or Utils::distance($this->entity->position, $data) > 10){
								}elseif($this->getSlot($this->slot)->getID() !== $data["block"] or ($this->getSlot($this->slot)->isTool() === false and $this->getSlot($this->slot)->getMetadata() !== $data["meta"])){
									$this->sendInventorySlot($this->slot);
								}else{
									$this->server->api->block->playerBlockAction($this, new Vector3($data["x"], $data["y"], $data["z"]), $data["face"], $data["fx"], $data["fy"], $data["fz"]);
									break;
								}
								$target = $this->level->getBlock(new Vector3($data["x"], $data["y"], $data["z"]));
								$block = $target->getSide($data["face"]);
								$this->dataPacket(MC_UPDATE_BLOCK, array(
									"x" => $target->x,
									"y" => $target->y,
									"z" => $target->z,
									"block" => $target->getID(),
									"meta" => $target->getMetadata()		
								));
								$this->dataPacket(MC_UPDATE_BLOCK, array(
									"x" => $block->x,
									"y" => $block->y,
									"z" => $block->z,
									"block" => $block->getID(),
									"meta" => $block->getMetadata()		
								));
								break;
							}elseif($data["face"] === 0xFF and $this->server->handle("player.action", $data) !== false){
								$this->entity->inAction = true;
								$this->startAction = microtime(true);
								$this->entity->updateMetadata();
							}
							break;
						case MC_PLAYER_ACTION:
							if($this->spawned === false){
								break;
							}
							$this->craftingItems = array();
							$this->toCraft = array();
							if($this->entity->inAction === true){
								switch($data["action"]){
									case 5: //Shot arrow
										if($this->getSlot($this->slot)->getID() === BOW){
											if($this->startAction !== false){
												$time = microtime(true) - $this->startAction;
												$d = array(
													"x" => $this->entity->x,
													"y" => $this->entity->y + 1.6,
													"z" => $this->entity->z,
												);
												$e = $this->server->api->entity->add($this->level, ENTITY_OBJECT, OBJECT_ARROW, $d);
												$this->server->api->entity->spawnToAll($e);
											}
										}
										break;
								}
							}
							$this->startAction = false;
							$this->entity->inAction = false;
							$this->entity->updateMetadata();
							break;
						case MC_REMOVE_BLOCK:
							if($this->spawned === false){
								break;
							}
							if($this->blocked === true or $this->entity->distance(new Vector3($data["x"], $data["y"], $data["z"])) > 8){
								break;
							}
							$this->craftingItems = array();
							$this->toCraft = array();
							$this->server->api->block->playerBlockBreak($this, new Vector3($data["x"], $data["y"], $data["z"]));
							break;
						case MC_PLAYER_ARMOR_EQUIPMENT:
							if($this->spawned === false){
								break;
							}
							$this->craftingItems = array();
							$this->toCraft = array();
							$data["eid"] = $this->eid;
							$data["player"] = $this;
							for($i = 0; $i < 4; ++$i){
								$s = $data["slot$i"];
								if($s === 0){
									$s = BlockAPI::getItem(AIR, 0, 0);
								}else{
									$s = BlockAPI::getItem($s + 256, 0, 1);
								}
								$slot = $this->armor[$i];
								if($slot->getID() !== AIR and $s->getID() === AIR){
									$this->addItem($slot->getID(), $slot->getMetadata(), 1);
									$this->armor[$i] = BlockAPI::getItem(AIR, 0, 0);
								}elseif($s->getID() !== AIR and $slot->getID() === AIR and ($sl = $this->hasItem($s->getID())) !== false){
									$this->armor[$i] = $this->getSlot($sl);
									$this->setSlot($sl, BlockAPI::getItem(AIR, 0, 0));
								}else{
									$data["slot$i"] = 0;
								}
								
							}							
							$this->server->handle("player.armor", $data);
							if($this->entity->inAction === true){
								$this->entity->inAction = false;
								$this->entity->updateMetadata();
							}
							break;
						case MC_INTERACT:
							if($this->spawned === false){
								break;
							}
							$this->craftingItems = array();
							$this->toCraft = array();
							$target = $this->server->api->entity->get($data["target"]);
							if($this->gamemode !== VIEW and $this->blocked === false and ($target instanceof Entity) and $this->entity->distance($target) <= 8){
								$data["targetentity"] = $target;
								$data["entity"] = $this->entity;
								if(!($target instanceof Entity)){
									break;
								}elseif($target->class === ENTITY_PLAYER and ($this->server->api->getProperty("pvp") == false or $this->server->difficulty <= 0 or ($target->player->gamemode & 0x01) === 0x01)){
									break;
								}elseif($this->server->handle("player.interact", $data) !== false){
									$slot = $this->getSlot($this->slot);
									switch($slot->getID()){
										case WOODEN_SWORD:
										case GOLD_SWORD:
											$damage = 4;
											break;
										case STONE_SWORD:
											$damage = 5;
											break;
										case IRON_SWORD:
											$damage = 6;
											break;
										case DIAMOND_SWORD:
											$damage = 7;
											break;
											
										case WOODEN_AXE:
										case GOLD_AXE:
											$damage = 3;
											break;
										case STONE_AXE:
											$damage = 4;
											break;
										case IRON_AXE:
											$damage = 5;
											break;
										case DIAMOND_AXE:
											$damage = 6;
											break;

										case WOODEN_PICKAXE:
										case GOLD_PICKAXE:
											$damage = 2;
											break;
										case STONE_PICKAXE:
											$damage = 3;
											break;
										case IRON_PICKAXE:
											$damage = 4;
											break;
										case DIAMOND_PICKAXE:
											$damage = 5;
											break;

										case WOODEN_SHOVEL:
										case GOLD_SHOVEL:
											$damage = 1;
											break;
										case STONE_SHOVEL:
											$damage = 2;
											break;
										case IRON_SHOVEL:
											$damage = 3;
											break;
										case DIAMOND_SHOVEL:
											$damage = 4;
											break;

										default:
											$damage = 1;//$this->server->difficulty;
									}
									$target->harm($damage, $this->eid);
									if($slot->isTool() === true and ($this->gamemode & 0x01) === 0){
										$slot->useOn($target);
									}
								}
							}
							break;
						case MC_ANIMATE:
							if($this->spawned === false){
								break;
							}
							$this->server->api->dhandle("entity.animate", array("eid" => $this->eid, "entity" => $this->entity, "action" => $data["action"]));
							break;
						case MC_RESPAWN:
							if($this->spawned === false){
								break;
							}
							if($this->entity->dead === false){
								break;
							}
							$this->craftingItems = array();
							$this->toCraft = array();
							$this->entity->fire = 0;
							$this->entity->air = 300;
							$this->entity->setHealth(20, "respawn");
							$this->entity->updateMetadata();
							$this->sendInventory();
							$this->teleport($this->spawnPosition);
							$this->blocked = false;
							$this->server->handle("player.respawn", $this);
							break;
						case MC_SET_HEALTH: //Not used
							break;
						case MC_ENTITY_EVENT:
							if($this->spawned === false){
								break;
							}
							$this->craftingItems = array();
							$this->toCraft = array();
							$data["eid"] = $this->eid;
							if($this->entity->inAction === true){
								$this->entity->inAction = false;
								$this->entity->updateMetadata();
							}
							switch($data["event"]){
								case 9: //Eating
									$items = array(
										APPLE => 2,
										MUSHROOM_STEW => 10,
										BREAD => 5,
										RAW_PORKCHOP => 3,
										COOKED_PORKCHOP => 8,
										RAW_BEEF => 3,
										STEAK => 8,
										COOKED_CHICKEN => 6,
										RAW_CHICKEN => 2,
										MELON_SLICE => 2,
										GOLDEN_APPLE => 10,
										COOKIE => 2,
										COOKED_FISH => 5,
										RAW_FISH => 2,
										
									);
									$slot = $this->getSlot($this->slot);
									if($this->entity->getHealth() < 20 and isset($items[$slot->getID()])){
										$this->dataPacket(MC_ENTITY_EVENT, array(
											"eid" => 0,
											"event" => 9,
										));
										$this->entity->heal($items[$slot->getID()], "eating");
										--$slot->count;
										if($slot->count <= 0){
											$this->setSlot($this->slot, BlockAPI::getItem(AIR, 0, 0), false);
										}
									}
									break;
							}
							break;
						case MC_DROP_ITEM:
							if($this->spawned === false){
								break;
							}
							$this->craftingItems = array();
							$this->toCraft = array();
							$data["item"] = $this->getSlot($this->slot);
							if($this->blocked === false and $this->server->handle("player.drop", $data) !== false){
								$this->server->api->entity->drop(new Position($this->entity->x - 0.5, $this->entity->y, $this->entity->z - 0.5, $this->level), $data["item"]);
								$this->setSlot($this->slot, BlockAPI::getItem(AIR, 0, 0));
							}
							if($this->entity->inAction === true){
								$this->entity->inAction = false;
								$this->entity->updateMetadata();
							}
							break;
						case MC_SIGN_UPDATE:
							if($this->spawned === false){
								break;
							}
							$this->craftingItems = array();
							$this->toCraft = array();
							$t = $this->server->api->tile->get(new Position($data["x"], $data["y"], $data["z"], $this->level));
							if(($t instanceof Tile) and $t->class === TILE_SIGN){
								if($t->data["creator"] !== $this->username){
									$t->spawn($this);
								}else{
									$t->setText($data["line0"], $data["line1"], $data["line2"], $data["line3"]);
								}
							}
							break;
						case MC_CHAT:
							if($this->spawned === false){
								break;
							}
							$this->craftingItems = array();
							$this->toCraft = array();
							$message = preg_replace('#^<.*> #', "", $data["message"]);
							if(trim($data["message"]) != "" and strlen($data["message"]) <= 100 and preg_match('#[^\\x20-\\xff]#', $message) == 0){
								if($message{0} === "/"){ //Command
									$this->server->api->console->run(substr($message, 1), $this);
								}else{
									if($this->server->api->dhandle("player.chat", array("player" => $this, "message" => $message)) !== false){
										$this->server->api->chat->send($this, $message);
									}
								}
							}
							break;
						case MC_CONTAINER_CLOSE:
							if($this->spawned === false){
								break;
							}
							$this->craftingItems = array();
							$this->toCraft = array();
							unset($this->windows[$data["windowid"]]);
							$this->dataPacket(MC_CONTAINER_CLOSE, array(
								"windowid" => $data["windowid"],
							));
							break;
						case MC_CONTAINER_SET_SLOT:
							if($this->spawned === false){
								break;
							}
							
							if($this->lastCraft <= (microtime(true) - 1)){
								if(isset($this->toCraft[-1])){
									$this->toCraft = array(-1 => $this->toCraft[-1]);
								}else{
									$this->toCraft = array();
								}
								$this->craftingItems = array();								
							}
							
							if($data["windowid"] === 0){
								$craft = false;
								$slot = $this->getSlot($data["slot"]);
								if($slot->count >= $data["stack"] and $slot->getID() === $data["block"] and $slot->getMetadata() === $data["meta"] and !isset($this->craftingItems[$data["slot"]])){ //Crafting recipe
									$use = BlockAPI::getItem($slot->getID(), $slot->getMetadata(), $slot->count - $data["stack"]);
									$this->craftingItems[$data["slot"]] = $use;
									$craft = true;
								}elseif($slot->count <= $data["stack"] and ($slot->getID() === AIR or ($slot->getID() === $data["block"] and $slot->getMetadata() === $data["meta"]))){ //Crafting final
									$craftItem = BlockAPI::getItem($data["block"], $data["meta"], $data["stack"] - $slot->count);
									if(count($this->toCraft) === 0){
										$this->toCraft[-1] = 0;
									}
									$this->toCraft[$data["slot"]] = $craftItem;
									$craft = true;
								}elseif(((count($this->toCraft) === 1 and isset($this->toCraft[-1])) or count($this->toCraft) === 0) and $slot->count > 0 and $slot->getID() !== AIR and ($slot->getID() !== $data["block"] or $slot->getMetadata() !== $data["meta"])){ //Crafting final
									$craftItem = BlockAPI::getItem($data["block"], $data["meta"], $data["stack"]);
									if(count($this->toCraft) === 0){
										$this->toCraft[-1] = 0;
									}
									$use = BlockAPI::getItem($slot->getID(), $slot->getMetadata(), $slot->count);
									$this->craftingItems[$data["slot"]] = $use;
									$this->toCraft[$data["slot"]] = $craftItem;
									$craft = true;
								}
								
								if($craft === true){
									$this->lastCraft = microtime(true);
								}

								if($craft === true and count($this->craftingItems) > 0 and count($this->toCraft) > 0 and ($recipe = $this->craftItems($this->toCraft, $this->craftingItems, $this->toCraft[-1])) !== true){
									if($recipe === false){
										$this->sendInventory();
										$this->toCraft = array();
									}else{
										$this->toCraft = array(-1 => $this->toCraft[-1]);
									}
									$this->craftingItems = array();									
								}
							}
							if(!isset($this->windows[$data["windowid"]])){
								break;
							}
							$tile = $this->windows[$data["windowid"]];
							if(($tile->class !== TILE_CHEST and $tile->class !== TILE_FURNACE) or $data["slot"] < 0 or ($tile->class === TILE_CHEST and $data["slot"] >= CHEST_SLOTS) or ($tile->class === TILE_FURNACE and $data["slot"] >= FURNACE_SLOTS)){
								break;
							}
							$done = false;
							$item = BlockAPI::getItem($data["block"], $data["meta"], $data["stack"]);
							
							$slot = $tile->getSlot($data["slot"]);
							$done = true;
							if($this->server->api->dhandle("player.container.slot", array(
								"tile" => $tile,
								"slot" => $data["slot"],
								"slotdata" => $slot,
								"itemdata" => $item,
								"player" => $this,
							)) === false){
								$this->dataPacket(MC_CONTAINER_SET_SLOT, array(
									"windowid" => $data["windowid"],
									"slot" => $data["slot"],
									"block" => $slot->getID(),
									"stack" => $slot->count,
									"meta" => $slot->getMetadata(),
								));
								break;
							}
							if($item->getID() !== AIR and $slot->getID() == $item->getID()){
								if($slot->count < $item->count){
									if($this->removeItem($item->getID(), $item->getMetadata(), $item->count - $slot->count) === false){
										break;
									}
								}elseif($slot->count > $item->count){
									$this->addItem($item->getID(), $item->getMetadata(), $slot->count - $item->count);
								}
							}else{
								if($this->removeItem($item->getID(), $item->getMetadata(), $item->count) === false){
									break;
								}
								$this->addItem($slot->getID(), $slot->getMetadata(), $slot->count);
							}
							$tile->setSlot($data["slot"], $item);
							break;
						case MC_SEND_INVENTORY: //TODO, Mojang, enable this ´^_^`
							if($this->spawned === false){
								break;
							}
							break;
						default:
							console("[DEBUG] Unhandled 0x".dechex($data["id"])." Data Packet for Client ID ".$this->clientID.": ".print_r($data, true), true, true, 2);
							break;
					}
					
					if(isset($this->queue[$this->counter[1] + 1])){
						$d = $this->queue[$this->counter[1] + 1];
						unset($this->queue[$this->counter[1] + 1]);
						$this->handle($d[0], $d[1]);
					}elseif(count($this->queue) > 25){
						$q = array_shift($this->queue);
						$this->counter[1] = $q[1][0];
						$this->handle($q[0], $q[1]);
					}
					break;
			}
		}
	}
	
	public function sendArmor($player = false){
		$data = array(
			"player" => $this,
			"eid" => $this->eid
		);
		for($i = 0; $i < 4; ++$i){
			if($this->armor[$i] instanceof Item){
				$data["slot$i"] = $this->armor[$i]->getID() !== AIR ? $this->armor[$i]->getID() - 256:0;
			}else{
				$this->armor[$i] = BlockAPI::getItem(AIR, 0, 0);
				$data["slot$i"] = 0;
			}
		}
		if($player instanceof Player){
			$player->dataPacket(MC_PLAYER_ARMOR_EQUIPMENT, $data);
		}else{
			$this->server->api->dhandle("player.armor", $data);
		}
	}
	
	public function sendInventory(){
		$this->dataPacket(MC_CONTAINER_SET_CONTENT, array(
			"windowid" => 0,
			"count" => count($this->inventory),
			"slots" => $this->inventory,
		));
	}

	public function send($pid, $data = array(), $raw = false){
		if($this->connected === true){
			$this->bandwidthRaw += $this->server->send($pid, $data, $raw, $this->ip, $this->port);
		}
	}
	
	public function sendBuffer(){
		if(strlen($this->buffer) > 0){
			$this->directDataPacket(false, array("raw" => $this->buffer), 0x40);
		}
		$this->buffer = "";
		$this->nextBuffer = microtime(true) + 0.1;
	}
	
	public function directBigRawPacket($id, $buffer){
		if($this->connected === false){
			return false;
		}
		$data = array(
			"id" => false,
			"pid" => 0x50,
			"sendtime" => microtime(true),
			"raw" => "",
		);
		$size = $this->MTU - 34;
		$buffer = str_split(($id === false ? "":chr($id)).$buffer, $size);
		$h = Utils::writeInt(count($buffer)).Utils::writeShort($this->bigCnt);
		$this->bigCnt = ($this->bigCnt + 1) % 0x10000;
		foreach($buffer as $i => $buf){
			$data["raw"] = Utils::writeShort(strlen($buf) << 3).strrev(Utils::writeTriad($this->counter[3]++)).$h.Utils::writeInt($i).$buf;
			$count = $this->counter[0]++;
			if(count($this->recovery) >= PLAYER_RECOVERY_BUFFER){
				reset($this->recovery);
				$k = key($this->recovery);
				unset($this->recovery[$k]);
				end($this->recovery);
			}
			$this->recovery[$count] = $data;
			$this->send(0x80, array(
				$count,
				0x50, //0b01010000
				$data,
			));
			++$this->packetStats[0];
		}
	}
	
	public function directDataPacket($id, $data = array(), $pid = 0x00){
		if($this->connected === false){
			return false;
		}
		$data["id"] = $id;
		$data["pid"] = $pid;
		$data["sendtime"] = microtime(true);
		$count = $this->counter[0]++;
		if(count($this->recovery) >= PLAYER_RECOVERY_BUFFER){
			reset($this->recovery);
			$k = key($this->recovery);
			unset($this->recovery[$k]);
			end($this->recovery);
		}
		$this->recovery[$count] = $data;

		$this->send(0x80, array(
			$count,
			$pid,
			$data,
		));
		++$this->packetStats[0];
	}

	public function dataPacket($id, $data = array()){
		$data["id"] = $id;
		if($id === false){
			$raw = $data["raw"];
		}else{
			$data = new CustomPacketHandler($id, "", $data, true);
			$raw = chr($id).$data->raw;
		}
		$len = strlen($raw);
		$MTU = $this->MTU - 24;
		if($len > $MTU){
			$this->directBigRawPacket(false, $raw);
			return;
		}
		
		if((strlen($this->buffer) + $len) >= $MTU){
			$this->sendBuffer();
		}
			$this->buffer .= ($this->buffer === "" ? "":"\x40").Utils::writeShort($len << 3).strrev(Utils::writeTriad($this->counter[3]++)).$raw;
		
	}
	
	function __toString(){
		if($this->username != ""){
			return $this->username;
		}
		return $this->clientID;
	}

}
