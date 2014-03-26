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

namespace PocketMine;

use PocketMine\Level\Level;
use PocketMine\Utils\TextFormat;

class ConsoleAPI{
	private $loop, $server, $event, $help, $cmds, $alias;

	function __construct(){
		$this->help = array();
		$this->cmds = array();
		$this->alias = array();
		$this->server = Server::getInstance();
	}

	public function init(){
		$this->server->schedule(2, array($this, "handle"), array(), true);
		if(!defined("NO_THREADS")){
			$this->loop = new ConsoleLoop();
		}
		$this->register("help", "[page|command name]", array($this, "defaultCommands"));
		$this->register("status", "", array($this, "defaultCommands"));
		$this->register("difficulty", "<0|1|2|3>", array($this, "defaultCommands"));
		$this->register("stop", "", array($this, "defaultCommands"));
		$this->register("defaultgamemode", "<mode>", array($this, "defaultCommands"));
		$this->server->api->ban->cmdWhitelist("help");
	}

	function __destruct(){
		$this->server->deleteEvent($this->event);
		if(!defined("NO_THREADS")){
			$this->loop->stop();
			$this->loop->notify();
			//@fclose($this->loop->fp);
			usleep(50000);
			$this->loop->kill();
			//$this->loop->join();
		}
	}

	public function defaultCommands($cmd, $params, $issuer, $alias){
		$output = "";
		switch($cmd){
			case "defaultgamemode":
				$gms = array(
					"0" => 0,
					"survival" => 0,
					"s" => 0,
					"1" => 1,
					"creative" => 1,
					"c" => 1,
					"2" => 2,
					"adventure" => 2,
					"a" => 2,
					"3" => 3,
					"view" => 3,
					"viewer" => 3,
					"spectator" => 3,
					"v" => 3,
				);
				if(!isset($gms[strtolower($params[0])])){
					$output .= "Usage: /$cmd <mode>\n";
					break;
				}
				$this->server->api->setProperty("gamemode", $gms[strtolower($params[0])]);
				$output .= "Default Gamemode is now " . strtoupper($this->server->getGamemode()) . ".\n";
				break;
			case "status":
				if(!($issuer instanceof Player) and $issuer === "console"){
					$this->server->debugInfo(true);
				}
				$info = $this->server->debugInfo();
				$output .= "TPS: " . $info["tps"] . ", Memory usage: " . $info["memory_usage"] . " (Peak " . $info["memory_peak_usage"] . ")\n";
				break;
			case "update-done":
				$this->server->api->setProperty("last-update", time());
				break;
			case "stop":
				$this->loop->stop = true;
				$output .= "Stopping the server\n";
				$this->server->close();
				break;
			case "difficulty":
				$s = trim(array_shift($params));
				if($s === "" or (((int) $s) > 3 and ((int) $s) < 0)){
					$output .= "Usage: /difficulty <0|1|2|3>\n";
					break;
				}
				$this->server->api->setProperty("difficulty", (int) $s);
				$output .= "Difficulty changed to " . $this->server->difficulty . "\n";
				break;
			default:
				$output .= "Command doesn't exist! Use /help\n";
				break;
		}

		return $output;
	}

	public function alias($alias, $cmd){
		$this->alias[strtolower(trim($alias))] = trim($cmd);

		return true;
	}

	public function register($cmd, $help, $callback){
		if(!is_callable($callback)){
			return false;
		}
		$cmd = strtolower(trim($cmd));
		$this->cmds[$cmd] = $callback;
		$this->help[$cmd] = $help;
		ksort($this->help, SORT_NATURAL | SORT_FLAG_CASE);
	}

	public function run($line = "", $issuer = "console", $alias = false){
		if($line != ""){
			$output = "";
			$end = strpos($line, " ");
			if($end === false){
				$end = strlen($line);
			}
			$cmd = strtolower(substr($line, 0, $end));
			$params = (string) substr($line, $end + 1);
			if(isset($this->alias[$cmd])){
				return $this->run($this->alias[$cmd] . ($params !== "" ? " " . $params : ""), $issuer, $cmd);
			}
			if($issuer instanceof Player){
				console("[DEBUG] " . TextFormat::AQUA . $issuer->getName() . TextFormat::RESET . " issued server command: " . ltrim("$alias ") . "/$cmd " . $params, true, true, 2);
			}else{
				console("[DEBUG] " . TextFormat::YELLOW . "*" . $issuer . TextFormat::RESET . " issued server command: " . ltrim("$alias ") . "/$cmd " . $params, true, true, 2);
			}

			if(preg_match_all('#@([@a-z]{1,})#', $params, $matches, PREG_OFFSET_CAPTURE) > 0){
				$offsetshift = 0;
				foreach($matches[1] as $selector){
					if($selector[0]{0} === "@"){ //Escape!
						$params = substr_replace($params, $selector[0], $selector[1] + $offsetshift - 1, strlen($selector[0]) + 1);
						--$offsetshift;
						continue;
					}
					switch(strtolower($selector[0])){
						case "u":
						case "player":
						case "username":
							$p = ($issuer instanceof Player) ? $issuer->getName() : $issuer;
							$params = substr_replace($params, $p, $selector[1] + $offsetshift - 1, strlen($selector[0]) + 1);
							$offsetshift -= strlen($selector[0]) - strlen($p) + 1;
							break;
						case "w":
						case "world":
							$p = ($issuer instanceof Player) ? $issuer->level->getName() : Level::getDefault()->getName();
							$params = substr_replace($params, $p, $selector[1] + $offsetshift - 1, strlen($selector[0]) + 1);
							$offsetshift -= strlen($selector[0]) - strlen($p) + 1;
							break;
						case "a":
						case "all":
							if($issuer instanceof Player){
								if($this->server->api->ban->isOp($issuer->getName())){
									$output = "";
									foreach(Player::getAll() as $p){
										$output .= $this->run($cmd . " " . substr_replace($params, $p->getName(), $selector[1] + $offsetshift - 1, strlen($selector[0]) + 1), $issuer, $alias);
									}
								}else{
									$issuer->sendMessage("You don't have permissions to use this command.\n");
								}
							}else{
								$output = "";
								foreach(Player::getAll() as $p){
									$output .= $this->run($cmd . " " . substr_replace($params, $p->getName(), $selector[1] + $offsetshift - 1, strlen($selector[0]) + 1), $issuer, $alias);
								}
							}

							return $output;
						case "r":
						case "random":
							$l = array();
							foreach(Player::getAll() as $p){
								if($p !== $issuer){
									$l[] = $p;
								}
							}
							if(count($l) === 0){
								return;
							}

							$p = $l[mt_rand(0, count($l) - 1)]->getName();
							$params = substr_replace($params, $p, $selector[1] + $offsetshift - 1, strlen($selector[0]) + 1);
							$offsetshift -= strlen($selector[0]) - strlen($p) + 1;
							break;
					}
				}
			}
			$params = explode(" ", $params);
			if(count($params) === 1 and $params[0] === ""){
				$params = array();
			}

			if(($d1 = $this->server->api->dhandle("console.command." . $cmd, array("cmd" => $cmd, "parameters" => $params, "issuer" => $issuer, "alias" => $alias))) === false
				or ($d2 = $this->server->api->dhandle("console.command", array("cmd" => $cmd, "parameters" => $params, "issuer" => $issuer, "alias" => $alias))) === false
			){
				$output = "You don't have permissions to use this command.\n";
			}elseif($d1 !== true and (!isset($d2) or $d2 !== true)){
				if(isset($this->cmds[$cmd]) and is_callable($this->cmds[$cmd])){
					$output = @call_user_func($this->cmds[$cmd], $cmd, $params, $issuer, $alias);
				}elseif($this->server->api->dhandle("console.command.unknown", array("cmd" => $cmd, "params" => $params, "issuer" => $issuer, "alias" => $alias)) !== false){
					$output = $this->defaultCommands($cmd, $params, $issuer, $alias);
				}
			}

			if($output != "" and ($issuer instanceof Player)){
				$issuer->sendMessage(trim($output));
			}

			return $output;
		}
	}

	public function handle($time){
		if(defined("NO_THREADS")){
			return;
		}
		if($this->loop->line !== false){
			$line = preg_replace("#\\x1b\\x5b([^\\x1b]*\\x7e|[\\x40-\\x50])#", "", trim($this->loop->line));
			$this->loop->line = false;
			$output = $this->run($line, "console");
			if($output != ""){
				$mes = explode("\n", trim($output));
				foreach($mes as $m){
					console("[CMD] " . $m);
				}
			}
		}else{
			$this->loop->notify();
		}
	}

}

class ConsoleLoop extends \Thread{
	public $line;
	public $stop;
	public $base;
	public $ev;
	public $fp;

	public function __construct(){
		$this->line = false;
		$this->stop = false;
		$this->start();
	}

	public function stop(){
		$this->stop = true;
	}

	private function readLine(){
		if($this->fp){
			$line = trim(fgets($this->fp));
		}else{
			$line = trim(readline(""));
			if($line != ""){
				readline_add_history($line);
			}
		}

		return $line;
	}

	public function run(){
		if(!extension_loaded("readline")){
			$this->fp = fopen("php://stdin", "r");
		}

		while($this->stop === false){
			$this->line = $this->readLine();
			$this->wait();
			$this->line = false;
		}

		if(!extension_loaded("readline")){
			@fclose($this->fp);
		}
		exit(0);
	}
}
