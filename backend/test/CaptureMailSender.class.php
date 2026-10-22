<?php

class Kloudspeaker_CaptureMailer {
	public $sent = array();

	public function send($to, $subject, $message, $from = NULL, $attachments = NULL) {
		$this->sent[] = array('to' => $to, 'subject' => $subject, 'message' => $message);
	}
}
