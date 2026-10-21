<?php

use PHPUnit\Framework\TestCase;

require_once 'plugin/Watermarking/markers/MarkerBase.class.php';
require_once 'plugin/Watermarking/markers/PDFMarker/PDFMarker.marker.php';

class PdfWatermarkTest extends TestCase {
    private $directory;
    private $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/kloudspeaker-pdf-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        require_once 'include/ImageGenerator.class.php';
        $this->generator = new class(null) extends ImageGenerator {
            public $created = array();

            public function createText($text, $size, $font, $fontSize, $textColor, $textAlpha, $rotation) {
                $file = parent::createText($text, $size, $font, $fontSize, $textColor, $textAlpha, $rotation);
                $this->created[] = $file;
                return $file;
            }
        };
    }

    protected function tearDown(): void {
        foreach ($this->generator->created as $file) {
            if (is_file($file)) unlink($file);
        }
        foreach (glob($this->directory . '/*') as $file) unlink($file);
        rmdir($this->directory);
        parent::tearDown();
    }

    private function sourcePdf() {
        $path = $this->directory . '/source.pdf';
        $pdf = new FPDF();
        $pdf->SetAutoPageBreak(false);
        foreach (array(array('P', array(210, 297), 0), array('L', array(210, 297), 0), array('P', array(120, 180), 90)) as $index => $page) {
            $pdf->AddPage($page[0], $page[1], $page[2]);
            $pdf->SetFont('Helvetica', '', 12);
            $pdf->Text(15, 20, 'SOURCE PAGE ' . ($index + 1));
        }
        $pdf->Output('F', $path);
        return $path;
    }

    private function marker($settings = array()) {
        $environment = new class($this->generator) {
            private $generator;
            public function __construct($generator) { $this->generator = $generator; }
            public function imageGenerator() { return $this->generator; }
        };
        $controller = new class($environment) {
            private $environment;
            public function __construct($environment) { $this->environment = $environment; }
            public function env() { return $this->environment; }
        };
        return new PDFMarker($controller, 'pdf', 'fixture', $settings);
    }

    private function geometry($path) {
        $reader = new \setasign\Fpdi\Fpdi();
        $count = $reader->setSourceFile($path);
        $sizes = array();
        for ($page = 1; $page <= $count; $page++) {
            $size = $reader->getTemplateSize($reader->importPage($page));
            $sizes[] = array($size['width'], $size['height'], $size['orientation']);
        }
        return $sizes;
    }

    private function tool($arguments) {
        $process = proc_open($arguments, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        return $output;
    }

    private function assertGeometryMatches($source, $target): void {
        $sourceSizes = $this->geometry($source);
        $targetSizes = $this->geometry($target);
        $this->assertCount(3, $targetSizes);
        foreach ($sourceSizes as $index => $size) {
            $this->assertEqualsWithDelta($size[0], $targetSizes[$index][0], 0.02);
            $this->assertEqualsWithDelta($size[1], $targetSizes[$index][1], 0.02);
            $this->assertSame($size[2], $targetSizes[$index][2]);
        }
    }

    private function assertVisibleDifference($source, $target): void {
        $sourcePrefix = $this->directory . '/source-render';
        $targetPrefix = $this->directory . '/target-render';
        $this->tool(array('pdftoppm', '-f', '1', '-singlefile', '-r', '72', '-png', $source, $sourcePrefix));
        $this->tool(array('pdftoppm', '-f', '1', '-singlefile', '-r', '72', '-png', $target, $targetPrefix));
        $before = imagecreatefrompng($sourcePrefix . '.png');
        $after = imagecreatefrompng($targetPrefix . '.png');
        try {
            $this->assertSame(imagesx($before), imagesx($after));
            $this->assertSame(imagesy($before), imagesy($after));
            $changed = 0;
            for ($pixelY = 0; $pixelY < imagesy($before); $pixelY++) {
                for ($pixelX = 0; $pixelX < imagesx($before); $pixelX++) {
                    if (imagecolorat($before, $pixelX, $pixelY) !== imagecolorat($after, $pixelX, $pixelY)) $changed++;
                }
            }
            $this->assertGreaterThan(100, $changed, 'The output must contain a visible watermark');
        } finally {
            imagedestroy($before);
            imagedestroy($after);
        }
    }

    public function testWatermarkedPdfConstructorWorksUnderEAll(): void {
        $pdf = new WatermarkedPDF(array());
        $this->assertInstanceOf(FPDF::class, $pdf);
    }

    public function testTextWatermarkPreservesPagesContentAndSourceBytes(): void {
        $source = $this->sourcePdf();
        $checksum = hash_file('sha256', $source);
        $target = $this->directory . '/marked.pdf';
        $this->assertTrue($this->marker(array('font' => array('Helvetica', 'B', 18), 'color' => array(255, 0, 0)))->mark($source, $target, 'CONFIDENTIAL FIXTURE'));
        $this->assertSame($checksum, hash_file('sha256', $source));
        $this->assertGeometryMatches($source, $target);
        $text = $this->tool(array('pdftotext', '-layout', $target, '-'));
        for ($page = 1; $page <= 3; $page++) $this->assertStringContainsString('SOURCE PAGE ' . $page, $text);
        $this->assertSame(3, substr_count($text, 'CONFIDENTIAL FIXTURE'));
        $this->assertVisibleDifference($source, $target);
    }

    public function testFooterWatermarkDoesNotAddOrResizePages(): void {
        $source = $this->sourcePdf();
        $target = $this->directory . '/footer.pdf';
        $this->assertTrue($this->marker(array('position' => array('right', 'bottom')))->mark($source, $target, 'FOOTER FIXTURE'));
        $this->assertGeometryMatches($source, $target);
        $text = $this->tool(array('pdftotext', '-layout', $target, '-'));
        $this->assertSame(3, substr_count($text, 'FOOTER FIXTURE'));
    }

    public function testRotatedImageWatermarkIsVisibleAndTemporaryImageIsRemoved(): void {
        $source = $this->sourcePdf();
        $target = $this->directory . '/rotated.pdf';
        $settings = array('rotation' => 35, 'img_size' => array(221, 71), 'img_font_size' => 20, 'alpha' => 50);
        $this->assertTrue($this->marker($settings)->mark($source, $target, "IMAGE caf\xc3\xa9 FIXTURE"));
        $this->assertGeometryMatches($source, $target);
        $this->assertVisibleDifference($source, $target);
        $this->assertCount(1, $this->generator->created);
        $this->assertFileDoesNotExist($this->generator->created[0]);
    }

    public function testProvidedImageIsNotDeleted(): void {
        $source = $this->sourcePdf();
        $imagePath = $this->directory . '/provided.png';
        $image = imagecreatetruecolor(40, 20);
        imagefilledrectangle($image, 0, 0, 39, 19, imagecolorallocate($image, 255, 0, 0));
        imagepng($image, $imagePath);
        imagedestroy($image);
        $checksum = hash_file('sha256', $imagePath);
        $target = $this->directory . '/image.pdf';
        $this->assertTrue($this->marker(array('img_file' => $imagePath))->mark($source, $target, 'Unused text'));
        $this->assertSame($checksum, hash_file('sha256', $imagePath));
        $this->assertGeometryMatches($source, $target);
        $this->assertVisibleDifference($source, $target);
    }

    public function testCorruptPdfFailsWithServiceErrorAndRemovesGeneratedImage(): void {
        $source = $this->directory . '/corrupt.pdf';
        file_put_contents($source, "%PDF-1.4\nnot a valid PDF\n");
        try {
            $this->marker(array('rotation' => 35))->mark($source, $this->directory . '/failed.pdf', 'FIXTURE');
            $this->fail('Invalid PDF must not produce success');
        } catch (ServiceException $exception) {
            $this->assertSame('REQUEST_FAILED', $exception->type());
        }
        $this->assertCount(1, $this->generator->created);
        $this->assertFileDoesNotExist($this->generator->created[0]);
        $this->assertFileDoesNotExist($this->directory . '/failed.pdf');
    }

    public function testMissingImageFailsWithConfigurationError(): void {
        $source = $this->sourcePdf();
        try {
            $this->marker(array('img_file' => $this->directory . '/missing.png'))->mark($source, $this->directory . '/failed.pdf', 'FIXTURE');
            $this->fail('Missing configured images must not produce success');
        } catch (ServiceException $exception) {
            $this->assertSame('INVALID_CONFIGURATION', $exception->type());
        }
    }

    public function testSourceCannotBeOverwritten(): void {
        $source = $this->sourcePdf();
        $checksum = hash_file('sha256', $source);
        try {
            $this->marker()->mark($source, $source, 'FIXTURE');
            $this->fail('Watermarking must not overwrite its source');
        } catch (ServiceException $exception) {
            $this->assertSame('INVALID_REQUEST', $exception->type());
        }
        $this->assertSame($checksum, hash_file('sha256', $source));
    }

    public function testFailedConversionPreservesExistingDestination(): void {
        $valid = $this->sourcePdf();
        $target = $this->directory . '/existing.pdf';
        copy($valid, $target);
        $checksum = hash_file('sha256', $target);
        $source = $this->directory . '/invalid.pdf';
        file_put_contents($source, "%PDF-1.4\ninvalid\n");
        try {
            $this->marker()->mark($source, $target, 'FIXTURE');
            $this->fail('Invalid sources must not replace existing output');
        } catch (ServiceException $exception) {
            $this->assertSame('REQUEST_FAILED', $exception->type());
        }
        $this->assertSame($checksum, hash_file('sha256', $target));
        $this->assertSame(array(), glob($this->directory . '/kloudspeaker-pdf-*'));
    }

    public function testUnsupportedPdfFormatsFailWithoutChangingSource(): void {
        $source = $this->sourcePdf();
        $encrypted = $this->directory . '/encrypted.pdf';
        $compressed = $this->directory . '/compressed.pdf';
        $this->tool(array('qpdf', '--encrypt', 'fixture-user', 'fixture-owner', '256', '--', $source, $encrypted));
        $this->tool(array('qpdf', '--object-streams=generate', $source, $compressed));
        foreach (array($encrypted, $compressed) as $input) {
            $checksum = hash_file('sha256', $input);
            $target = $this->directory . '/unsupported-output.pdf';
            try {
                $this->marker()->mark($input, $target, 'FIXTURE');
                $this->fail('Unsupported PDF formats must produce a controlled failure');
            } catch (ServiceException $exception) {
                $this->assertSame('REQUEST_FAILED', $exception->type());
            }
            $this->assertSame($checksum, hash_file('sha256', $input));
            $this->assertFileDoesNotExist($target);
        }
        $this->assertSame(array(), glob($this->directory . '/kloudspeaker-pdf-*'));
    }

    public function testInvalidImageWithApplicationErrorHandlerIsControlled(): void {
        $source = $this->sourcePdf();
        $image = $this->directory . '/invalid.png';
        file_put_contents($image, 'invalid image fixture');
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            try {
                $this->marker(array('img_file' => $image))->mark($source, $this->directory . '/failed.pdf', 'FIXTURE');
                $this->fail('Invalid configured images must not bypass configuration errors');
            } catch (ServiceException $exception) {
                $this->assertSame('INVALID_CONFIGURATION', $exception->type());
            }
        } finally {
            restore_error_handler();
        }
    }

    public function testPluginSetupRegistersItsManagerWithoutDynamicProperties(): void {
        require_once 'plugin/PluginBase.class.php';
        require_once 'plugin/Watermarking/Watermarking.plugin.class.php';
        $environment = new class {
            public $registered;
            public function filesystem() { return $this; }
            public function registerActionInterceptor($id, $manager) { $this->registered = array($id, $manager); }
        };
        $plugin = new Watermarking($environment, 'Watermarking', array());
        $plugin->setup();
        $this->assertInstanceOf(WatermarkingManager::class, $plugin->getManager());
        $this->assertSame(array('plugin-watermarking', $plugin->getManager()), $environment->registered);
    }

    public function testPdfLibrariesMatchPinnedUpstreamSources(): void {
        $vendorDirectory = dirname(__DIR__) . '/vendor/';
        $manifest = json_decode(file_get_contents($vendorDirectory . 'dependencies.json'), true, 512, JSON_THROW_ON_ERROR);
        $packages = array_column($manifest['packages'], null, 'name');
        foreach (array('setasign/fpdf', 'setasign/fpdi') as $name) {
            $this->assertFalse($packages[$name]['modified']);
            foreach ($packages[$name]['runtime_files'] as $path => $checksum) {
                $this->assertSame($checksum, hash_file('sha256', $vendorDirectory . $path));
            }
        }
        $this->assertSame('1.9', FPDF::VERSION);
        $this->assertSame('2.6.9', \setasign\Fpdi\Fpdi::VERSION);
        $this->assertSame($vendorDirectory . 'fpdf/fpdf.php', (new ReflectionClass(FPDF::class))->getFileName());
        $this->assertSame($vendorDirectory . 'fpdi/src/Fpdi.php', (new ReflectionClass(\setasign\Fpdi\Fpdi::class))->getFileName());
    }
}