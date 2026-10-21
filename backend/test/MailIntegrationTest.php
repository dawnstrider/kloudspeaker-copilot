<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MailSenderTest.php';

class MailIntegrationTest extends TestCase {
    use MailSenderFixture;

    protected function setUp(): void {
        parent::setUp();
        $this->assertSame('1', getenv('KLOUDSPEAKER_MAIL_INTEGRATION'), 'Use the isolated mail-tests Compose service');
        $this->request('messages', 'DELETE');
    }

    private function request($path, $method = 'GET') {
        $handle = curl_init('http://mailpit:8025/api/v1/' . $path);
        curl_setopt_array($handle, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CUSTOMREQUEST => $method));
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        $this->assertNotFalse($body, $error);
        $this->assertContains($status, array(200, 204));
        return $body;
    }

    private function api($path, $method = 'GET') {
        $body = $this->request($path, $method);
        return $body === '' ? array() : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function smtpSender($smtpOverrides = array()) {
        return $this->createSender(array(
            'mail_notification_from' => 'sender@example.test',
            'mail_smtp' => array_merge(array('host' => 'mailpit', 'port' => 1025, 'username' => 'fixture-user', 'password' => 'fixture-password', 'timeout' => 2), $smtpOverrides),
        ));
    }

    private function sendWithoutOutput($sender, $subject, $body, $from = null, $attachments = null) {
        $includePath = get_include_path();
        ob_start();
        try {
            $result = $sender->send(array('first@example.test', array('email' => 'second@example.test', 'name' => 'Second'), 'not-an-address', null), $subject, $body, $from, $attachments);
        } finally {
            $output = ob_get_clean();
        }
        $this->assertSame('', $output, 'SMTP debug output must not reach API responses');
        $this->assertSame($includePath, get_include_path(), 'Mail must not change global include paths');
        return $result;
    }

    private function capturedMessage() {
        $messages = $this->api('messages');
        $this->assertSame(1, $messages['total']);
        return $this->api('message/' . $messages['messages'][0]['ID']);
    }

    public function testAuthenticatedPlainTextAndBccPrivacy(): void {
        $body = "Plain caf\xc3\xa9 fixture";
        $this->assertTrue($this->sendWithoutOutput($this->smtpSender(), 'Plain fixture', $body));
        $message = $this->capturedMessage();
        $this->assertSame('sender@example.test', $message['From']['Address']);
        $this->assertSame('Plain fixture', $message['Subject']);
        $this->assertStringContainsString($body, $message['Text']);
        $this->assertEqualsCanonicalizing(array('first@example.test', 'second@example.test'), array_column($message['Bcc'], 'Address'));
        $visibleRecipients = array_column(array_merge($message['To'], $message['Cc']), 'Address');
        $this->assertNotContains('first@example.test', $visibleRecipients);
        $this->assertNotContains('second@example.test', $visibleRecipients);
        $raw = $this->request('message/' . $message['ID'] . '/raw');
        $this->assertStringContainsString('charset=utf-8', strtolower($raw));
    }

    public function testHtmlAndUnicodeSenderAndSubject(): void {
        $subject = "R\xc3\xa9sum\xc3\xa9 fixture";
        $body = "<html lang=\"en\"><body>HTML caf\xc3\xa9 fixture</body></html>";
        $this->assertTrue($this->sendWithoutOutput($this->smtpSender(), $subject, $body, "Exp\xc3\xa9diteur <sender@example.test>"));
        $message = $this->capturedMessage();
        $this->assertSame($subject, $message['Subject']);
        $this->assertSame("Exp\xc3\xa9diteur", $message['From']['Name']);
        $this->assertStringContainsString("HTML caf\xc3\xa9 fixture", $message['HTML']);
    }

    public function testAttachmentBytesArePreserved(): void {
        $path = tempnam(sys_get_temp_dir(), 'mail-attachment-');
        $bytes = "attachment\x00\x01\xff\xc3\xa9";
        file_put_contents($path, $bytes);
        try {
            $this->assertTrue($this->sendWithoutOutput($this->smtpSender(), 'Attachment fixture', 'Body', null, array($path)));
            $message = $this->capturedMessage();
            $this->assertCount(1, $message['Attachments']);
            $attachment = $message['Attachments'][0];
            $this->assertSame(basename($path), $attachment['FileName']);
            $this->assertSame(strlen($bytes), $attachment['Size']);
            $downloaded = $this->request('message/' . $message['ID'] . '/part/' . $attachment['PartID']);
            $this->assertSame($bytes, $downloaded);
        } finally {
            unlink($path);
        }
    }

    public function testAuthenticationFailureIsControlled(): void {
        $this->assertFalse($this->sendWithoutOutput($this->smtpSender(array('password' => 'wrong-fixture-password')), 'Failure', 'Body'));
        $this->assertSame(0, $this->api('messages')['total']);
    }

    public function testConnectionFailureIsControlled(): void {
        $this->assertFalse($this->sendWithoutOutput($this->smtpSender(array('port' => 1026, 'timeout' => 1)), 'Failure', 'Body'));
        $this->assertSame(0, $this->api('messages')['total']);
    }

    public function testRequiredTlsDoesNotFallBackToPlainText(): void {
        $this->assertFalse($this->sendWithoutOutput($this->smtpSender(array('secure' => 'tls')), 'TLS required', 'Body'));
        $this->assertSame(0, $this->api('messages')['total']);
    }

    public function testMissingAttachmentDoesNotSendAnIncompleteMessage(): void {
        $this->assertFalse($this->sendWithoutOutput($this->smtpSender(), 'Missing attachment', 'Body', null, array('/tmp/nonexistent-fixture-attachment')));
        $this->assertSame(0, $this->api('messages')['total']);
    }

    public function testMalformedMessageAndTransportOptionsAreRejected(): void {
        $sender = $this->smtpSender();
        $this->assertFalse($this->sendWithoutOutput($sender, null, 'Body'));
        $this->assertFalse($this->sendWithoutOutput($sender, 'Subject', array()));
        $this->assertFalse($this->sendWithoutOutput($sender, 'Subject', 'Body', array()));
        foreach (array(array('port' => 0), array('port' => true), array('timeout' => 0), array('timeout' => array()), array('username' => array()), array('secure' => 'invalid')) as $options) {
            $this->assertFalse($this->sendWithoutOutput($this->smtpSender($options), 'Invalid configuration', 'Body'));
        }
        $this->assertSame(0, $this->api('messages')['total']);
    }
}