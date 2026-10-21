<?php

use PHPUnit\Framework\TestCase;

require_once 'include/mail/PHPMailerSender.class.php';

trait MailSenderFixture {
    protected function createSender($configuration = array(), $enabled = true) {
        $environment = new class($configuration, $enabled) {
            private $configuration;
            private $enabled;

            public function __construct($configuration, $enabled) {
                $this->configuration = new Settings($configuration);
                $this->enabled = $enabled;
            }

            public function features() {
                return $this;
            }

            public function isFeatureEnabled($name) {
                return $this->enabled;
            }

            public function settings() {
                return $this->configuration;
            }
        };
        return new Kloudspeaker_MailSender($environment);
    }
}

class MailSenderTest extends TestCase {
    use MailSenderFixture;

    public function testDisabledMailDoesNotLoadOrSend(): void {
        $includePath = get_include_path();
        $sender = $this->createSender(array(), false);
        $this->assertNull($sender->send(array('recipient@example.test'), 'Disabled', 'Body'));
        $this->assertSame($includePath, get_include_path());
    }

    public function testMalformedRecipientCollectionsDoNotProduceDiagnostics(): void {
        $sender = $this->createSender(array('mail_notification_from' => 'sender@example.test'));
        foreach (array(null, 1, false, array(), array(null, array(), array('email' => null), array('email' => array()), 42, '')) as $recipients) {
            $this->assertNull($sender->send($recipients, 'No recipients', 'Body'));
        }
    }

    public function testMailDependencyMatchesPinnedUpstreamSource(): void {
        $vendorDirectory = dirname(__DIR__) . '/vendor/';
        require_once $vendorDirectory . 'PHPMailer/src/PHPMailer.php';
        $manifest = json_decode(file_get_contents($vendorDirectory . 'dependencies.json'), true, 512, JSON_THROW_ON_ERROR);
        $packages = array_column($manifest['packages'], null, 'name');
        $package = $packages['phpmailer/phpmailer'];
        $this->assertFalse($package['modified']);
        $this->assertSame($package['source_version'], PHPMailer\PHPMailer\PHPMailer::VERSION);
        foreach ($package['runtime_files'] as $path => $checksum) {
            $this->assertSame($checksum, hash_file('sha256', $vendorDirectory . $path));
        }
        $this->assertSame($vendorDirectory . 'PHPMailer/src/PHPMailer.php', (new ReflectionClass(PHPMailer\PHPMailer\PHPMailer::class))->getFileName());
    }
}