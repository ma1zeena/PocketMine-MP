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

class SignPostBlock extends TransparentBlock{
	public function __construct($meta = 0){
		parent::__construct(SIGN_POST, $meta, "Sign Post");
		$this->isSolid = false;
		$this->isFullBlock = false;
	}
	
	public function place(Item $item, Player $player, Block $block, Block $target, $face, $fx, $fy, $fz){
		if($face !== 0){
			$faces = array(
				2 => 2,
				3 => 3,
				4 => 4,
				5 => 5,
			);
			if(!isset($faces[$face])){
				$this->meta = floor((($player->entity->yaw + 180) * 16 / 360) + 0.5) & 0x0F;
				$this->level->setBlock($block, BlockAPI::get(SIGN_POST, $this->meta));
				return true;
			}else{
				$this->meta = $faces[$face];
				$this->level->setBlock($block, BlockAPI::get(WALL_SIGN, $this->meta));
				return true;
			}
		}
		return false;
	}
	
	public function onBreak(Item $item, Player $player){
		$this->level->setBlock($this, new AirBlock(), true, true);
		return true;
	}

	public function getDrops(Item $item, Player $player){
		return array(
			array(SIGN, 0, 1),
		);
	}	
}