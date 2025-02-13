<?php
	/***************************************/
	/* UCVS - Unified Callback Vote Script */
	/*		Written by LemoniscooL		   */
	/*	 released under GPLv3 license	   */
	/***************************************/
require_once('usvsRest.class.php');
	class ucvsCore
	{
		var $config;
		var $dbCon;
		
		///<summary>
		///Constructor being executed at object creation. Will automatically choose DBMS and connect to DB.
		///Return values: none
		///</summary>
		public function __construct()
		{
			$this->config = new userConfig();
			
			if($this->config->dbMode == 0)
			{
				$this->dbCon = cMSSQL::withDB($this->config->dbHost, $this->config->dbID, $this->config->dbPW, $this->config->dbName);
			}
			else if ($this->config->dbMode == 1)
			{
				$this->dbCon = cMySQL::withDB($this->config->dbHost, $this->config->dbID, $this->config->dbPW, $this->config->dbName);
			}
		}
		
		///<summary>
		///Reward silk to a given user id. Silkroad only, requires use of mssql
		///Return values: Success => true | Failure => Error message
		///</summary>
		private function rewardSilk($userID)
		{
			if(!empty($userID) && $userID != "" && is_numeric($userID))
			{
				$this->dbCon->execute("EXEC [{$this->config->dbName}].[CGI].[CGI_WebPurchaseSilk] @OrderID = N'VoteSystem', @UserID = {$this->dbCon->secure($userID)}, @PkgID = 0, @NumSilk = {$this->dbCon->secure($this->config->rewardAmount)}, @Price = 0");
				return true;
			}
			else
			{
				cLog::ErrorLog("UCVS Error: Something is wrong with the user ID! Its either empty or not numeric. For alphanumeric user IDs please use custom point system!");
				return "Error: Something is wrong with the user ID! Its either empty or not numeric." . PHP_EOL . "For alphanumeric user IDs please use custom point system!" . PHP_EOL;
			}
		}
		
		///<summary>
		///Reward points to a given user id. Using custom point system
		///Return values: Success => true | Failure => Error message
		///</summary>
		///$this->dbCon->execute("UPDATE {$this->config->tableName} SET {$this->config->pointColName} = {$this->config->pointColName} + {$this->config->rewardAmount} WHERE {$this->config->idColName} LIKE {$this->dbCon->secure($userID)}");
		///$this->dbCon->execute("UPDATE `KkGsj6_usermeta` SET LEFT JOIN `KkGsj6_users`.`ID` = um.user_id SET 'meta_value' = CAST(`meta_value` AS UNSIGNED) + {$this->config->rewardAmount} WHERE `KkGsj6_users`.`ID` IS NOT NULL AND um.meta_key = {$this->dbCon->secure($userID)}");
		private function rewardPoints($userID)
		{
			if(!empty($userID) && $userID != "")
			{
				/**$this->dbCon->execute("UPDATE `liS8E6K_usermeta` um
					LEFT JOIN `liS8E6K_users` u ON u.`user_login` = {$this->dbCon->secure($userID)}
					SET `meta_value` = CAST(`meta_value` AS UNSIGNED) + {$this->config->rewardAmount}
					WHERE um.`nickname` LIKE {$this->dbCon->secure($userID)} AND um.`meta_key` = 'dp_points_total'");**/
				$action = "POST";
    			$url = "https://staging.theegn.com/wp-json/voting/";
    			$parameters = array("access_key" => "",
									"user_id" => $userID,
									"type" => "add",
									"ctype" => "vp_points",
									"reference" => "Vote",
									"amount" => "10",
									"entry" => "Vote");
    			$result = CurlHelper::http_request($action, $url, $parameters);
    			echo print_r($result);
				
			}
			else
			{
				cLog::ErrorLog("UCVS Error: User ID is empty!");
				return "Error: User ID is empty!" . PHP_EOL;
			}
		}
		
		///<summary>
		///Choose the right reward function, call it and return its return value
		///Return values: Success => true | Failure => Error message
		///</summary>
		private function doReward($userID, $siteIP)
		{
			$this->updateDelay($userID, $siteIP);
			
			if($this->config->rewardMode == 0)
			{
				return $this->rewardSilk($userID);
			}
			else
			{
				return $this->rewardPoints($userID);
			}
		}
		
		///<summary>
		///Check if a given IP is allowed to use ucvs
		///Return values: true | false
		///</summary>
		public function checkIP($ip)
		{
			if($this->isInArray($ip, $this->config->whitelist))
			{
				return true;
			}
			else
			{
				return false;
			}
		}
		
		///<summary>
		///Check if a given user in a dataset voted during the last x hours on a given toplist
		///Return values: true | false
		///</summary>
		private function checkDelay(array $data, $siteIP)
		{
			$user = $this->getUser($data);
			$siteName = $this->getSite($siteIP);
			
			if($this->config->dbMode == 0) //MSSQL
			{
				$sql = "SELECT [{$siteName}] FROM UCVS_VoteLog WHERE UserID = {$user}";
			}
			else if($this->config->dbMode == 1) //MySQL
			{
				$sql = "SELECT `{$siteName}` FROM UCVS_VoteLog WHERE UserID = '{$user}'";
			}
			
			if($this->dbCon->numRows($sql) == 0)
			{
				$this->dbCon->execute("INSERT INTO UCVS_VoteLog (UserID) VALUES ('{$user}')");
			}
			
			$row = $this->dbCon->fetchArray($sql);
			if($row[$siteName] == null || $row[$siteName] <= time())
			{
				return true;
			}
			else
			{
				return false;
			}
		}
		
		///<summary>
		///Update the cooldown for a given user on a given toplist
		///Return values: none
		///</summary>
		private function updateDelay($user, $siteIP)
		{
			$siteName = $this->getSite($siteIP);
			$time = time() + (($this->config->rewardDelay * 60) * 60);

			if($this->config->dbMode == 0) //MSSQL
			{
				$sql = "UPDATE UCVS_VoteLog SET [{$siteName}] = '{$time}' WHERE UserID = '{$user}'";
			}
			else if($this->config->dbMode == 1) //MySQL
			{
				$sql = "UPDATE UCVS_VoteLog SET `{$siteName}` = '{$time}' WHERE UserID = '{$user}'";
			}
			
			$this->dbCon->execute($sql);
		}
		
		///<summary>
		///Process data sent by the toplist
		///Return values: Success => true | Failure => Error message
		///</summary>
		public function procData(array $data, $siteIP)
		{
			if($this->checkDelay($data, $siteIP))
			{
				switch($siteIP)
				{
					case "199.59.161.214": //xtremetop100
						$result = $this->doReward($data['custom'], $siteIP);
					break;
					
					
					case "104.28.15.89": //gtop100
					case "2610:150:8019::1:fc02": //gtop100
					case "198.148.82.98": //gtop100
					case "198.148.82.99": //gtop100
						if(abs($data['Successful']) == 0)
						{
							$result = $this->doReward($data['pingUsername'], $siteIP);
						}
						else
						{
							$result = $data['Reason'];
						}
					break;
					
					case "192.99.101.31": //topg
						$result = $this->doReward($data['p_resp'], $siteIP);
					break;
					
					case "104.24.8.79": //top100arena
					case "209.59.143.11": //top100arena
						$result = $this->doReward($data['postback'], $siteIP);
					break;
					
					case "51.81.81.226": //arena-top100
					case "184.154.46.76": //arena-top100
						if($data['voted'] == 1)
						{
							$result = $this->doReward($data['userid'], $siteIP);
						}
						else
						{
							$result = "User " . $data['userid'] . " voted already today!" . PHP_EOL;
						}
					break;
					
					case "116.203.217.217": //silkroad-servers
						if($data['voted'] == 1)
						{
							$result = $this->doReward($data['userid'], $siteIP);
						}
						else
						{
							$result = "User " . $data['userid'] . " voted already today!" . PHP_EOL;
						}
					break;
					
					case "116.203.234.215": //private-server				
						if($data['voted'] == 1)
						{
							$result = $this->doReward($data['userid'], $siteIP);
						}
						else
						{
							$result = "User " . $data['userid'] . " voted already today!" . PHP_EOL;
						}
					break;
					
					case "185.176.40.63": //gamestop100
						$result = $this->doReward($data['custom'], $siteIP);
					break;
					
					default:
						$result = "Wrong IP called!";
					break;
				}
			}
			else
			{
				$result = "The user \"" . $this->getUser($data) . "\" voted already!";
			}
			
			return $result;
		}
		
		///<summary>
		///Extracts the user name/id from a given dataset
		///Return values: Success => user name/id | Failure => false
		///</summary>
		public function getUser(array $data)
		{
			if(isset($data['custom']))
			{
				return $data['custom'];
			}
			else if(isset($data['pingUsername']))
			{
				return $data['pingUsername'];
			}
			else if(isset($data['p_resp']))
			{
				return $data['p_resp'];
			}
			else if(isset($data['postback']))
			{
				return $data['postback'];
			}
			else if(isset($data['userid']))
			{
				return $data['userid'];
			}
			else
			{
				return false;
			}
		}
		
		///<summary>
		///Gets the site name from whitelist by a given IP
		///Return values: Success => site name | Failure => false
		///</summary>
		public function getSite($ip)
		{
			return $this->config->whitelist[$ip];
		}
		
		///<summary>
		///Extended version of in_array, searches the $needle in the value aswell as the key of $haystack
		///If the third parameter strict is set to TRUE then the function will search for identical elements in the $haystack. This means it will also perform a type comparison of the $needle in the $haystack.
		///Return values: Success => true | Failure => false
		///</summary>
		function isInArray($needle, $haystack, $strict = false)
		{
			foreach($haystack as $key => $value)
			{
				if($strict)
				{
					if($key === $needle || $value === $needle)
						return true;
				}
				else
				{
					if($key == $needle || $value == $needle)
						return true;
				}
			}
			return false;
		}
	}
