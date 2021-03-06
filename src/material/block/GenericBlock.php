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


class GenericBlock extends Block{
	public function __construct($id, $meta = 0, $name = "Unknown"){
		parent::__construct($id, $meta, $name);
	}	
	public function place(Item $item, Player $player, Block $block, Block $target, $face, $fx, $fy, $fz){
		return $this->level->setBlock($this, $this, true, false, true);
	}
	
	public function isBreakable(Item $item, Player $player){
		return ($this->breakable);
	}
	
	public function onBreak(Item $item, Player $player){
		return $this->level->setBlock($this, new AirBlock(), true, false, true);
	}
	
	public function onUpdate($type){
		if($this->hasPhysics === true){
			$down = $this->getSide(0);
			if($down->getID() === AIR or ($down instanceof LiquidBlock)){
				$data = array(
					"x" => $this->x + 0.5,
					"y" => $this->y + 0.5,
					"z" => $this->z + 0.5,
					"Tile" => $this->id,
				);
				$server = ServerAPI::request();
				$this->level->setBlock($this, new AirBlock(), false, false, true);
				$e = $server->api->entity->add($this->level, ENTITY_FALLING, FALLING_SAND, $data);
				$server->api->entity->spawnToAll($e);
				$server->api->block->blockUpdateAround(clone $this, BLOCK_UPDATE_NORMAL, 1);
			}
			return false;
		}
		return false;
	}

	public function onActivate(Item $item, Player $player){
		return $this->isActivable;
	}
}