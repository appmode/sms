<?php
//----------------------------------------------------------------------------//
// sms.class.php (c) copyright 2008 Flame Herbohn
//----------------------------------------------------------------------------//
 
//----------------------------------------------------------------------------//
// THIS SOFTWARE IS GPL LICENSED
//----------------------------------------------------------------------------//
//  This program is free software; you can redistribute it and/or modify
//  it under the terms of the GNU General Public License (version 2) as 
//  published by the Free Software Foundation.
//
//  This program is distributed in the hope that it will be useful,
//  but WITHOUT ANY WARRANTY; without even the implied warranty of
//  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//  GNU Library General Public License for more details.
//
//  You should have received a copy of the GNU General Public License
//  along with this program; if not, write to the Free Software
//  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
//----------------------------------------------------------------------------//

define ("SMS_STATUS_OK",			0);
define ("SMS_STATUS_ERROR",			1);

// send
define ("SMS_STATUS_SEND_NEW",			0);
define ("SMS_STATUS_SEND_RESEND",		50);
define ("SMS_STATUS_SEND_START",		100);
// status of 200 or more will not be sent
define ("SMS_STATUS_SEND_ERROR",		200);
define ("SMS_STATUS_SEND_OK",			300);

// receive
define ("SMS_STATUS_RECEIVE_NEW",		0);
define ("SMS_STATUS_RECEIVE_READ",		100);
define ("SMS_STATUS_RECEIVE_ARCHIVED",	200);

// contacts
define ("SMS_CONTACT_SUBSCRIBED",		0);
define ("SMS_CONTACT_UNSUBSCRIBED",		100);

// SMS Specification Constants
define ("SMS_DELETE_SINGLE",					0);
define ("SMS_DELETE_RECEIVED_READ",				1);
define ("SMS_DELETE_RECEIVED_READ_STORED_SENT",	2);
define ("SMS_DELETE_RECEIVED_READ_STORED",		3);
define ("SMS_DELETE_ALL",						4);

class sms
{
	public function __construct($strPort)
	{
		// default to ok status
		$this->intStatus = SMS_STATUS_OK;
		
		// set port speed with stty
		//exec("stty -F /dev/{$strPort} 115200");
		
		// open port
		$this->_refPort = dio_open("/dev/{$strPort}", O_RDWR | O_NOCTTY | O_NONBLOCK);

		if (!$this->_refPort)
		{
			$this->intStatus = SMS_STATUS_ERROR;
		}
		else
		{
			// set some port crap
			dio_fcntl($this->_refPort, F_SETFL, O_SYNC);

			// set port speed etc
			dio_tcsetattr($this->_refPort, array(
			  'baud' => 19200,
			  'bits' => 8,
			  'stop'  => 1,
			  'parity' => 0
			));
			
			// init modem
			if (!$this->command('AT+CFUN=1,1'))
			{
				$this->intStatus = SMS_STATUS_ERROR;	
			}
			elseif (!$this->command('AT+CMGF=1'))
			{
				$this->intStatus = SMS_STATUS_ERROR;	
			}
		}
	}
	
	public function write($strCommand)
	{
		return dio_write($this->_refPort, $strCommand."\r");
	}
	
	public function readLine()
	{
		$chrReturn = "";
		$strReturn = "";
		while (1)
		{
			$chrReturn 	= dio_read($this->_refPort, 1);
			if ($chrReturn == "\n" || $chrReturn == "\r")
			{
				break;
			}
			$strReturn .= $chrReturn;
		}
		echo "{$strReturn}\n";
		return $strReturn;
	}
	
	public function command($strCommand)
	{
		// clear reply cache
		$this->strReply = "";
		
		// send command
		if (!$this->write($strCommand))
		{
			return FALSE;	
		}
		
		// clear response
		$strReply = "";
		
		// get response
		while (strpos($strReply, "OK") === FALSE && strpos($strReply, "ERROR") === FALSE)
		{
			$strReply 		 = $this->readLine();
			$this->strReply	.= $strReply;
		}
		
		// return something
		switch (trim($strReply))
		{
			case 'OK':
				return TRUE;
				break;
				
			case 'ERROR':
				return FALSE;
				break;
				
			default:
				return FALSE;
				break;
		}
	}
	
	// delete an sms (or multiple sms)
	public function delete($intMessage, $intFlag=NULL)
	{
		switch ($intFlag)
		{
			case SMS_DELETE_RECEIVED_READ:
			case SMS_DELETE_RECEIVED_READ_STORED_SENT:
			case SMS_DELETE_RECEIVED_READ_STORED:
			case SMS_DELETE_ALL:
				$strCommand = "0,{$intFlag}";
				break;
				
			case SMS_DELETE_SINGLE:
			default:
				$strCommand = $intMessage;
				break;
		}
		
		// run the command
		return $this->command("AT+CMGD={$strCommand}");
	}
	
	// get all of the recieved messages as an array
	public function receive()
	{
		// run the command
		if (!$this->command("AT+CMGL=\"ALL\""))
		{
			return FALSE;
		}
		
		// get the messages as an array
		$arrMessages = explode("+CMGL:", $this->strReply);
		
		// remove junk from the start of the array
		$strJunk = array_shift($arrMessages);
		
		// set return array
		$arrReturn = Array();
		
		// check that we have messages
		if (is_array($arrMessages) && !empty($arrMessages))
		{
			// for each message
			foreach($arrMessages AS $strMessage)
			{
				// split content from metta data
				$arrMessage	= explode("\n", $strMessage, 2);
				$strMetta	= trim($arrMessage[0]);
				$arrMetta	= explode(",", $strMetta);
				$strContent	= trim($arrMessage[0]);
				
				/* metta data format is:
				 * 0	Id
				 * 1	Status
				 * 2	From
				 * 3	?
				 * 4	date
				 * 5	time (with +offset)
				 * 
				 * values may have leading, trailing (or both) double quotes
				 */
				
				// set the message array to go in the return array
				$arrReturnMessage = Array();
				
				// set the message values to return
				$arrReturnMessage['Id']			= trim($arrMetta[0], "\"");
				$arrReturnMessage['Status']		= trim($arrMetta[1], "\"");
				$arrReturnMessage['From']		= trim($arrMetta[2], "\"");
				$arrReturnMessage['Date']		= trim($arrMetta[4], "\"");
				$arrTime						= explode("+", $arrMetta[5], 2);
				$arrReturnMessage['Time']		= trim($arrTime[0], "\"");
				$arrReturnMessage['Content']	= trim($strContent);
				
				// add message to return array
				$arrReturn[] = $arrReturnMessage;
			}
		}
		
		// return messages array
		return $arrReturn;
	}
	
	// send an sms (and delete it from storage after sending)
	public function send($strTarget, $strMessage)
	{		
		// set message id to zero
		$intMessage = 0;
		
		// clear response
		$strReply = "";
		
		// set recipient
		$strCommand = "AT+CMGW=\"{$strTarget}\"";
		if (!$this->write($strCommand))
		{
			return FALSE;	
		}
		
		// get response (wait for a matching response line)
		while (trim($strReply) != trim($strCommand))
		{
			$strReply = $this->readLine();
		}
		
		// set message
		dio_write($this->_refPort, $strMessage);
		
		// send ctrl-z
		dio_write($this->_refPort, chr(26));
		
		// get reply
		while (strpos($strReply, "OK") === FALSE && strpos($strReply, "ERROR") === FALSE)
		{
			$strReply = $this->readLine();
			if (strpos($strReply, "CMGW:") !== FALSE)
			{
				$arrReply = explode(":", $strReply);
				$intMessage = (int)$arrReply[1];
			}
		}
		
		// send the message
		if ($intMessage)
		{
			if ($this->command("AT+CMSS={$intMessage}") == TRUE)
			{
				// delete message
				$this->command("AT+CMGD={$intMessage}");
				return TRUE;
			}
			
			// delete message
			$this->command("AT+CMGD={$intMessage}");
		}
		
		return FALSE;
	}
}



?>
