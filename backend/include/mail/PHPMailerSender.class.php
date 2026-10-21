<?php

	/**
	 * PHPMailerSender.class.php
	 *
	 * Copyright 2015- Samuli J�rvel�
	 * Released under GPL License.
	 *
	 * License: http://www.kloudspeaker.com/license.php
	 */

	class Kloudspeaker_MailSender {
		private $env;
		private $enabled;
		
		public function __construct($env) {
			$this->env = $env;
			$this->enabled = $env->features()->isFeatureEnabled("mail_notification");
		}
		
		public function send($to, $subject, $message, $from = NULL, $attachments = NULL) {			
			if (!$this->enabled) return;
			if (!is_string($subject) or !is_string($message)) return FALSE;
			if ($from !== NULL and !is_string($from)) return FALSE;
			
			$isHtml = (stripos($message, "<html") !== FALSE);
			$f = ($from != NULL ? $from : $this->env->settings()->setting("mail_notification_from"));
			if (!is_string($f)) return FALSE;

			$emailStartIndex = strpos($f, "<");
			$emailEndIndex = strpos($f, ">");
			$fromName = ($emailStartIndex !== FALSE) ? trim(substr($f, 0, $emailStartIndex)) : '';
			if ($emailStartIndex !== FALSE and $emailEndIndex !== FALSE) $f = substr($f, $emailStartIndex+1, ($emailEndIndex - $emailStartIndex)-1);
			
			$validRecipients = $this->getValidRecipients($to);
			if (count($validRecipients) === 0) {
				Logging::logDebug("No valid recipient email addresses, no mail sent");
				return;
			}
			
			Logging::logDebug("Sending mail to " . count($validRecipients) . " recipients");
			$library = dirname(__DIR__, 2) . '/vendor/PHPMailer/src/';
			require_once $library . 'PHPMailer.php';
			require_once $library . 'SMTP.php';
			require_once $library . 'Exception.php';

			try {
				$mailer = new PHPMailer\PHPMailer\PHPMailer;
				$mailer->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
				$mailer->SMTPDebug = 0;
				$smtp = $this->env->settings()->setting("mail_smtp");
				if ($smtp != NULL) {
					if (!is_array($smtp) or !isset($smtp['host']) or !is_string($smtp['host'])) return FALSE;
					$mailer->isSMTP();
					$mailer->Host = $smtp['host'];
					if (isset($smtp['port'])) {
						if (!is_int($smtp['port']) and !is_string($smtp['port'])) return FALSE;
						$port = filter_var($smtp['port'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 65535)));
						if ($port === FALSE) return FALSE;
						$mailer->Port = $port;
					}
					if (isset($smtp['timeout'])) {
						if (!is_int($smtp['timeout']) and !is_string($smtp['timeout'])) return FALSE;
						$timeout = filter_var($smtp['timeout'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
						if ($timeout === FALSE) return FALSE;
						$mailer->Timeout = $timeout;
					}
					if (isset($smtp['username']) or isset($smtp['password'])) {
						if (!isset($smtp['username'], $smtp['password']) or !is_string($smtp['username']) or !is_string($smtp['password'])) return FALSE;
						$mailer->SMTPAuth = true;
						$mailer->Username = $smtp['username'];
						$mailer->Password = $smtp['password'];
					}
					if (isset($smtp['secure'])) {
						if (!in_array($smtp['secure'], array('', 'tls', 'ssl'), TRUE)) return FALSE;
						$mailer->SMTPSecure = $smtp['secure'];
					}
				}
				if (!$mailer->setFrom(trim($f), $fromName)) return FALSE;
				foreach ($validRecipients as $recipient) {
					$mailer->addBCC($recipient['email'], isset($recipient['name']) ? $recipient['name'] : '');
				}
				if (!$isHtml) $mailer->WordWrap = 50;
				else $mailer->isHTML(true);
				if ($attachments !== NULL) {
					if (!is_iterable($attachments)) return FALSE;
					foreach ($attachments as $attachment) {
						if (!is_string($attachment) or !$mailer->addAttachment($attachment)) return FALSE;
					}
				}
				$mailer->Subject = $subject;
				$mailer->Body = $message;
				if (!$mailer->send()) {
					Logging::logError('Message could not be sent');
					return FALSE;
				}
				return TRUE;
			} catch (Exception $e) {
				Logging::logError('Message could not be sent');
				return FALSE;
			}
		}
		
		private function getValidRecipients($recipients) {
			$valid = array();
			if (!is_iterable($recipients)) return $valid;
			foreach ($recipients as $recipient) {
				if (is_string($recipient)) {
					if (trim($recipient) !== '') $valid[] = array("email" => trim($recipient));
					continue;
				}
				if (!is_array($recipient) or !isset($recipient["email"]) or !is_string($recipient["email"]) or trim($recipient["email"]) === '') continue;

				$valid[] = array("email" => trim($recipient["email"]), "name" => isset($recipient["name"]) && is_string($recipient["name"]) ? $recipient["name"] : '');
			}
			return $valid;
		}
				
		public function __toString() {
			return "Kloudspeaker_MailSender_PHPMailer";
		}
	}
?>
