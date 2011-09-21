<?php
/**
 * Fuel is a fast, lightweight, community driven PHP5 framework.
 *
 * @package    Fuel
 * @version    1.0
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2011 Fuel Development Team
 * @link       http://fuelphp.com
 */

namespace Email;

class SmtpConnectionException extends \Fuel_Exception {}

class SmtpCommandFailureException extends \Fuel_Exception {}

class SmtpAuthenticationFailedException extends \Fuel_Exception {}

class Email_Driver_Smtp extends \Email_Driver {

	/**
	 * The SMTP connection
	 */
	protected $smtp_connection = 0;

	/**
	 * Initalted all needed for SMTP mailing.
	 *
	 * @return	bool	success boolean
	 */
	protected function _send()
	{
		$message = $this->build_message();
	
		if(empty($this->config['smtp']['host']) or empty($this->config['smtp']['port']))
		{
			throw new \Fuel_Exception('Must supply a SMTP host and port, none given.');
		}
		
		// Use authentication?
		$authenticate = ! empty($this->config['smtp']['username']) and ! empty($this->config['smtp']['password']);
		
		// Connect
		$this->smtp_connect($authenticate, $this->config['encoding'] == '8bit');
		
		$authenticate and $this->smtp_authenticate();
		
		// Set from
		$this->smtp_command('MAIL FROM:<'.$this->config['from']['email'].'>', 250);
			
		foreach(array('to', 'cc', 'bcc') as $list)
		{
			foreach($this->{$list} as $recipient)
			{
				$this->smtp_command('RCPT TO:<'.$recipient['email'].'>', array(250, 251));
			}
		}
						
		// Prepare for data sending
		$this->smtp_command('DATA', 354);
		
		$lines = explode($this->config['newline'], $message['header'].$this->config['newline'].preg_replace('/^\./m', '..$1', $message['body']));
		
		foreach($lines as $line)
		{
			if(substr($line, 0, 1) === '.')
			{
				$line = '.'.$line;
			}
			
			fputs($this->smtp_connection, $line.$this->config['newline']);
		}

		// Finish the message
		$this->smtp_command('.', 250);
				
		// Close the connection
		$this->smtp_disconnect();
		
		return true;
	}
	
	/**
	 * Connects to the given smtp and says hello to the other server.
	 */
	protected function smtp_connect($authenticate, $force_ehlo)
	{
		$this->smtp_connection = @fsockopen(
			$this->config['smtp']['host'],
			$this->config['smtp']['port'],
			$error_number,
			$error_string,
			$this->config['smtp']['timeout']
		);
				
		if(empty($this->smtp_connection))
		{
			throw new \SmtpConnectionException('Could not connect to SMTP: ('.$error_number.') '.$error_string);
		}
		
		// Clear the smtp response
		$this->smtp_get_response();
		
		$hello = ($authenticate or $force_ehlo) ? 'EHLO' : 'HELO';
				
		// Just say hello!
		if($this->smtp_command('EHLO'.' '.\Input::server('SERVER_NAME', 'localhost.local'), 250, true) !== 250)
		{
			$this->smtp_command('HELO'.' '.\Input::server('SERVER_NAME', 'localhost.local'), 250);
		}
		
		$this->smtp_command('HELP', 214);
	}
	
	/**
	 * Close SMTP connection
	 */
	protected function smtp_disconnect()
	{
		$this->smtp_command('QUIT', 221);
		fclose($this->smtp_connection);
		$this->smtp_connection = 0;
	}
	
	/**
	 * Performs authentication with the SMTP host
	 */
	protected function smtp_authenticate()
	{
		// Encode login data
		$username = base64_encode($this->config['smtp']['username']);
		$password = base64_encode($this->config['smtp']['password']);
		
		try
		{
			// Prepare login
			$this->smtp_command('AUTH LOGIN', 334);
			
			// Send username
			$this->smtp_command($username, 334);
			
			// Send password
			$this->smtp_command($password, 235);
			
		}
		catch(\SmtpCommandFailureException $e)
		{
			throw new \SmtpAuthenticationFailedException('Failed authentication.');
		}
		
	}
	
	/**
	 * Send a command to the SMTP host
	 *
	 * @param	string	$command	the SMTP command
	 * @param	mixed	$expecting	the expected response
	 */
	protected function smtp_command($command, $expecting, $return_number = false)
	{
		! is_array($expecting) and $expecting !== false and $expecting = array($expecting);
	
		if ( ! fputs($this->smtp_connection, $command . $this->config['newline']))
		{
			if($expecting === false)
			{
				return false;
			}
			throw new \SmtpCommandFailureException('Failed executing command: '. $command);
		}
		
		// Get the reponse
		$response = $this->smtp_get_response();
		
		// Get the reponse number
		$number = (int) substr($response, 0, 3);
		// Check against expected result
		if($expecting !== false and ! in_array($number, $expecting))
		{
			throw new \SmtpCommandFailureException('Got an unexpected response from host on command: ['.$command.'] expecting: '.join(' or ',$expecting).' received: '.$response);
		}
		
		if($return_number)
		{
			return $number;
		}
		
		return $response;
	}
	
	/**
	 * Get SMTP response
	 *
	 * @return	string	SMTP response
	 */
	protected function smtp_get_response()
	{
		$data = '';

		while($str = fgets($this->smtp_connection, 512))
		{
			$data .= $str;

			if (substr($str, 3, 1) === ' ')
			{
				break;
			}
		}

		return $data;
	}

}